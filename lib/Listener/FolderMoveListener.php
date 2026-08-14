<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Re-parents a mirrored folder in Grafana after it has been moved in Nextcloud.
 *
 * The sibling of {@see FolderRenameListener}, and split from it for the reason that
 * listener explains: Nextcloud fires one event for both gestures, but they are two
 * gestures with two far-side calls. That one takes the case where the NAME changed
 * under the same parent; this one takes the case where the PARENT changed.
 *
 * ## WHAT IT HANDLES, AND WHAT IT LEAVES ALONE
 *
 * It acts when the folder lands somewhere still mapped — inside its own mapping, or
 * inside a different one. Both are the same call: re-parent to whatever Grafana
 * folder now holds it, keeping the uid, so the dashboards inside travel with it and
 * none of them is re-created.
 *
 * **A folder that has left the mapped set entirely is NOT handled here.** That is a
 * cascade rather than a move: every dashboard inside has to be deleted in Grafana or
 * parked in the recycle-bin folder, one at a time, and the folder itself stops being
 * a mirror. Doing half of it — re-parenting a folder that no longer belongs anywhere
 * — would put the Grafana folder somewhere nobody chose. Until that cascade exists,
 * leaving the mapped set does what it has always done: nothing on the far side.
 *
 * The crossings that are outright forbidden — between a `sync` and a `link` mapping,
 * or a link folder leaving its mapping — never reach this listener at all. They are
 * refused before the move happens, by {@see MoveGuardListener}.
 *
 * ## WHY THE DESTINATION IS ASKED FOR, NOT COMPUTED
 *
 * {@see FolderMirror::folderUidFor()} answers "which Grafana folder should hold the
 * thing at this path", creating any missing levels on the way. Passing the moved
 * folder gives the uid of its new PARENT, because the walk starts at the node's
 * parent — so a folder dragged into a subfolder that Grafana has never seen brings
 * that subfolder into existence, exactly as a dashboard landing there would.
 */
final class FolderMoveListener implements IEventListener {
	public function __construct(
		private FolderMetadata $folders,
		private MappingService $mappings,
		private FolderMirror $folderMirror,
		private GrafanaClient $grafana,
		private SyncGuard $guard,
		private SyncNotifier $notifier,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		// The pull's tree reconcile moves Nextcloud folders to follow Grafana. Without
		// this that would bounce straight back as a fresh move.
		if ($this->guard->active()) {
			return;
		}
		if (!$event instanceof NodeRenamedEvent) {
			return;
		}

		$target = $event->getTarget();
		if (!$target instanceof Folder) {
			return; // files re-parent through MotionService
		}
		if (dirname($event->getSource()->getPath()) === dirname($target->getPath())) {
			return; // same parent: that is a RENAME, and FolderRenameListener has it
		}

		try {
			$uid = $this->folders->uidOf($target->getId());
		} catch (\Throwable) {
			return;
		}
		if ($uid === '') {
			return; // a folder the user made for their own reasons
		}

		$mapping = $this->mappings->resolveForPath($target->getPath());
		if ($mapping === null) {
			// Out of the mapped set. A cascade, not a move — see the class docblock.
			$this->logger->info('grafana_sync: a mirrored folder left the mapped set; Grafana not changed', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'path' => $target->getPath(),
			]);
			return;
		}

		try {
			$parentUid = $this->folderMirror->folderUidFor($target, $mapping);
			$this->grafana->moveFolder($uid, $parentUid ?? '');
			$this->logger->info('grafana_sync: re-parented a mirrored folder in Grafana', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'parentUid' => $parentUid,
			]);
		} catch (\Throwable $e) {
			// The Nextcloud move already happened and cannot be undone from here. Say so:
			// the two sides now disagree, and the uid is what lets the next sync settle it.
			$this->logger->warning('grafana_sync: moved locally, but Grafana would not re-parent the folder', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			$this->notifier->failed(
				$this->userSession->getUser()?->getUID() ?? $target->getOwner()?->getUID() ?? '',
				$target->getId(),
				$target->getName(),
				GrafanaClient::describeConnectionError($e),
			);
		}
	}
}
