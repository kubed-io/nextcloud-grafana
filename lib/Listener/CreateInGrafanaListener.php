<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MotionService;
use OCA\GrafanaSync\Service\ReplacedByMoveStore;
use OCA\GrafanaSync\Service\ResolvesActingUser;
use OCA\GrafanaSync\Service\RestoreInProgress;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Create-on-land (Course 4 · Slice 1): when a `*.grafana` file with no
 * `grafana_uid` lands in a mapped **sync** folder — made via the Files "New" menu,
 * saved by the Text editor, uploaded over WebDAV, or moved in from elsewhere — create
 * it as a real Grafana dashboard ({@see CreateService}).
 *
 * Listens to two events:
 *  - {@see NodeWrittenEvent}  — create + content writes (New-menu PUT, editor saves,
 *                               desktop-client uploads). The content exists here.
 *  - {@see NodeRenamedEvent}  — move-in from outside any mapping (NC does not fire
 *                               NodeWrittenEvent on a move).
 *
 * Bail conditions (cheap → expensive, so the hot path stays fast):
 *   1. {@see SyncGuard::active()} — our own pull/stamp writes never re-enter.
 *   2. name is `*.grafana`.
 *   3. path resolves into a mapping via {@see MappingService::resolveForPath}.
 *   4. the mapping's mode is `sync` — a `link` folder is for pointers, not authoring.
 *   5. {@see DashboardMetadata::read} returns no uid (else it's already managed → the
 *      writeback listener owns it).
 *
 * Loop-order safety vs. {@see NodeWrittenListener}: if we run first we create + stamp
 * the synced hash, and the writeback then sees the uid + `sha1(content) === syncedHash`
 * and bails; if the writeback runs first it sees no uid and bails, then we create.
 * Either order works — there is no race.
 *
 * Failures are non-blocking for the save: logged + surfaced as a notification (the
 * `push_failed` subject — "Couldn't sync X to Grafana" reads fine for a create too).
 *
 * @implements IEventListener<NodeWrittenEvent|NodeRenamedEvent>
 */
final class CreateInGrafanaListener implements IEventListener {
	use ResolvesActingUser;

	public function __construct(
		private CreateService $createService,
		private MappingService $mappings,
		private MotionService $motion,
		private ReplacedByMoveStore $replaced,
		private RestoreInProgress $restoring,
		private DashboardMetadata $metadata,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private SyncNotifier $notifier,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($this->guard->active()) {
			return;
		}

		$node = EventNode::of($event);
		if (!FilenameCodec::isDashboardFile($node)) {
			return;
		}
		/** @var \OCP\Files\File $node — isDashboardFile guarantees a File */

		$mapping = $this->mappings->resolveForPath($node->getPath());
		if ($mapping === null) {
			return; // outside any mapping — a plain, untracked .grafana
		}
		if ($mapping->mode !== Mapping::MODE_SYNC) {
			return; // a link folder is for read-only pointers, not authoring
		}

		$managed = $this->metadata->read($node->getId());
		if ($managed?->isManaged()) {
			return; // already tracked — the writeback listener owns it
		}

		// A RESTORE IS NOT AN AUTHORING GESTURE. Over WebDAV a restore is a copy out of
		// the trash, and a copy carries no metadata row — so the file that lands here
		// looks exactly like a brand new one, and create-on-land minted a SECOND
		// dashboard beside the one being restored. {@see \OCA\GrafanaSync\DAV\TrashRestorePlugin}
		// re-attaches the real identity once the move completes; this is what keeps the
		// two from racing to define what the file is.
		if ($this->restoring->active()) {
			$this->logger->info('grafana_sync create-on-land: a restore is under way; the file keeps the dashboard it had', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
			]);
			return;
		}

		// AN OVERWRITE INHERITS, IT DOES NOT CREATE — even from a file that arrived
		// carrying nothing. A copied `.grafana` has no `grafana_uid` (a copy does not
		// inherit the metadata row), so dragging one over a synced file lands here rather
		// than in {@see \OCA\GrafanaSync\Service\MotionService::onMove}. Create-on-land
		// would mint a second dashboard and leave the one the file replaced live in this
		// mapping's Grafana folder and file-less — which the next pull writes back beside
		// it, as `foo (1).grafana`.
		//
		// The rule is the same whatever the arrival carried: the destination's identity
		// survives and the arrival contributes only its body. `bindTo` already knows how
		// to do that, so this hands over rather than repeating it.
		$adopted = $this->replaced->adoptedUid($node->getId());
		if ($adopted !== null) {
			$this->logger->info('grafana_sync create-on-land: an overwrite inherits the dashboard it replaced', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'uid' => $adopted,
			]);
			try {
				$this->motion->bindTo($node, $adopted, $mapping);
			} catch (\Throwable $e) {
				$this->logger->warning('grafana_sync create-on-land: inheriting the replaced dashboard failed', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'uid' => $adopted,
					'exception' => $e,
				]);
				// AND THE USER IS TOLD, exactly as the create below tells them. This branch
				// is reached by a gesture the person is watching — they answered a conflict
				// dialog — and it is the branch where silence costs the most: the file is
				// sitting in the mapped folder looking synced while the dashboard it
				// replaced still holds the old body. A log line nobody reads is how that
				// becomes a mystery a week later.
				$this->notifyFailure($node, $e);
			}
			return;
		}

		try {
			$this->createService->createForFile($node, $mapping);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync create-on-land failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
				'exception' => $e,
			]);
			$this->notifyFailure($node, $e);
		}
	}

	/** Tell whoever performed the gesture that the Grafana half of it did not happen. */
	private function notifyFailure(File $node, \Throwable $e): void {
		$uid = $this->actingUserUid($node);
		if ($uid !== '') {
			$this->notifier->failed($uid, $node->getId(), $node->getName(), GrafanaClient::describeConnectionError($e));
		}
	}

}
