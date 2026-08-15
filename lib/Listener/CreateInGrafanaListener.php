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
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
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
	public function __construct(
		private CreateService $createService,
		private MappingService $mappings,
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

		$node = $this->resolveNode($event);
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

		try {
			$this->createService->createForFile($node, $mapping);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync create-on-land failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
				'exception' => $e,
			]);
			$uid = $this->userSession->getUser()?->getUID() ?? $node->getOwner()?->getUID() ?? '';
			if ($uid !== '') {
				$this->notifier->failed($uid, $node->getId(), $node->getName(), GrafanaClient::describeConnectionError($e));
			}
		}
	}

	/** Pull the post-event file node out of either supported event. */
	private function resolveNode(Event $event): ?Node {
		if ($event instanceof NodeWrittenEvent) {
			return $event->getNode();
		}
		if ($event instanceof NodeRenamedEvent) {
			return $event->getTarget();
		}
		return null;
	}
}
