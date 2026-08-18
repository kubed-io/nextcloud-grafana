<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ResolvesActingUser;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Carries a mirrored folder's new name to Grafana.
 *
 * The first listener in this app that looks at a FOLDER at all. Every other one
 * filters to `.grafana` files on its first line, so a folder gesture has until
 * now fired events nothing acted on — which is why `renameFolder()` sat in the
 * client with no caller.
 *
 * ## A RENAME, NOT A MOVE — AND NEXTCLOUD DOES NOT DISTINGUISH THEM
 *
 * `NodeRenamedEvent` fires for both: internally a move IS a rename to a path with a
 * different parent. They are different gestures with different far-side calls
 * (`renameFolder` against `moveFolder`), and only the rename is handled here.
 *
 * A folder whose PARENT changed is left alone, deliberately, because a move is not
 * simply a rename with extra steps — it has to resolve the destination's Grafana
 * folder, refuse the crossings a link mapping forbids, and decide what leaving the
 * mapped set means for the dashboards inside. Treating it as a rename would send
 * Grafana a new title and quietly leave the folder in the wrong place on both sides.
 * Ignoring it preserves exactly what the app did before this listener existed.
 *
 * ## WHAT IT ACTS ON
 *
 * Only a folder this app has stamped ({@see FolderMetadata}). A folder the user made
 * for their own reasons carries no uid, so renaming it is none of our business — the
 * same rule that keeps a mapped folder usable for ordinary things.
 *
 * ## WHEN GRAFANA REFUSES
 *
 * The Nextcloud rename has already happened; this runs after the fact. So a failure
 * is logged and swallowed rather than thrown: throwing cannot put the folder back,
 * and the uid means the next pull sees the same folder under a name that disagrees
 * and settles it. A local rename that stands while Grafana is unreachable is the
 * specified behaviour, not a consolation.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class FolderRenameListener implements IEventListener {
	use ResolvesActingUser;

	public function __construct(
		private FolderMetadata $folders,
		private GrafanaClient $grafana,
		private SyncGuard $guard,
		private SyncNotifier $notifier,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		// Our own writes rename folders too — the pull's tree reconcile follows a
		// Grafana rename by renaming the Nextcloud folder. Without this the reconcile
		// would bounce that straight back to Grafana as a fresh rename.
		if ($this->guard->active()) {
			return;
		}
		if (!$event instanceof NodeRenamedEvent) {
			return;
		}

		$target = $event->getTarget();
		if (!$target instanceof Folder) {
			return; // files have their own name-sync
		}

		$source = $event->getSource();
		if (dirname($source->getPath()) !== dirname($target->getPath())) {
			return; // the parent changed: that is a MOVE, and it is not this listener's
		}

		$name = $target->getName();
		if ($name === $source->getName()) {
			return; // nothing about the name actually changed
		}

		try {
			$uid = $this->folders->uidOf($target->getId());
		} catch (\Throwable) {
			return; // cannot classify → do nothing
		}
		if ($uid === '') {
			return; // not a folder we mirror
		}

		try {
			$this->grafana->renameFolder($uid, $name);
			$this->logger->info('grafana_sync: renamed a mirrored folder in Grafana', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'name' => $name,
			]);
		} catch (\Throwable $e) {
			// The local rename already stands, and the uid is what lets the next sync
			// finish the job rather than guess at a delete. But the two sides now
			// disagree, so SAY SO — a silent divergence is the thing this app exists to
			// avoid, and the user is the only one who knows whether it matters.
			$this->logger->warning('grafana_sync: renamed locally, but Grafana would not take the new name', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'name' => $name,
				'exception' => $e,
			]);
			$this->notifier->failed(
				$this->actingUserUid($target),
				$target->getId(),
				$name,
				GrafanaClient::describeConnectionError($e),
			);
		}
	}
}
