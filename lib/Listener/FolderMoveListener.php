<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MotionService;
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
 * Re-parents a mirrored folder in Grafana after it has been moved in Nextcloud.
 *
 * The sibling of {@see FolderRenameListener}, and split from it for the reason that
 * listener explains: Nextcloud fires one event for both gestures, but they are two
 * gestures with two far-side calls. That one takes the case where the NAME changed
 * under the same parent; this one takes the case where the PARENT changed.
 *
 * ## A FOLDER MOVE HAS THREE ENDINGS, AND ONLY ONE OF THEM IS A MOVE
 *
 * What a folder move means to Grafana depends on whether the folder was a mirror
 * before and whether it is inside a mapping after. Those two questions give three
 * cases that do genuinely different things, and for a long time only the middle one
 * was built — which is why a folder dragged out of a mapping left its dashboards
 * live in Grafana, and a folder of loose `.grafana` files dragged INTO one stayed
 * invisible over there.
 *
 * | was a mirror | lands mapped | what it is                                        |
 * |--------------|--------------|---------------------------------------------------|
 * | yes          | yes          | a MOVE — re-parent the Grafana folder, keep the uid |
 * | yes          | no           | a LEAVING — the cascade, dashboard by dashboard     |
 * | no           | yes          | an ARRIVING — every dashboard in it becomes real    |
 *
 * (The fourth row, no/no, is a plain folder moving between plain places. Nothing.)
 *
 * **Leaving** is the same gesture as trashing the folder, so it is the same code:
 * {@see FolderCascade::trash()}. The recycle bin decides whether each dashboard is
 * deleted or parked, the Grafana folder goes either way, and every stamp underneath
 * is forgotten. What it does NOT do is touch the Nextcloud files — they keep their
 * bodies, which is what lets the whole folder be moved back in and rebuilt.
 *
 * **Arriving** is a walk, for the reason {@see CopyListener} records about copies:
 * Nextcloud raises ONE event for the folder and none for the files inside it, so
 * create-on-land never sees them. Each dashboard file gets the same treatment a
 * single moved file gets — {@see MotionService::onMove()} when it already carries an
 * identity (a parked folder coming home un-parks), {@see CreateService::createForFile()}
 * when it does not. The Grafana folder appears as a CONSEQUENCE of the first dashboard
 * landing in it, never as a step of its own: a folder is in Grafana when a dashboard is.
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
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class FolderMoveListener implements IEventListener {
	use ResolvesActingUser;

	public function __construct(
		private FolderMetadata $folders,
		private DashboardMetadata $metadata,
		private MappingService $mappings,
		private FolderMirror $folderMirror,
		private FolderCascade $cascade,
		private MotionService $motion,
		private CreateService $create,
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
		$fromPath = $event->getSource()->getPath();
		if (dirname($fromPath) === dirname($target->getPath())) {
			return; // same parent: that is a RENAME, and FolderRenameListener has it
		}

		try {
			$uid = $this->folders->uidOf($target->getId());
		} catch (\Throwable) {
			return;
		}
		$mapping = $this->mappings->resolveForPath($target->getPath());

		if ($uid !== '') {
			if ($mapping === null) {
				$this->leaving($target, $uid);
				return;
			}
			$this->moving($target, $event->getSource()->getName(), $mapping, $uid);
			return;
		}
		if ($mapping !== null) {
			$this->arriving($target, $fromPath, $mapping);
		}
		// Neither a mirror before nor mapped after: a folder the user made for their
		// own reasons, moved somewhere this app has no opinion about.
	}

	/**
	 * A MIRRORED FOLDER LEFT THE MAPPED SET, which is a cascade and not a move.
	 *
	 * Delegated whole to {@see FolderCascade::trash()} because it IS that gesture: the
	 * recycle bin decides delete-or-park per dashboard, the Grafana folder goes either
	 * way, and every mirror stamp underneath is dropped. Dragging a folder out of a
	 * mapping and dragging it to the trash differ only in where the Nextcloud files end
	 * up, and the far side cannot tell those apart — so answering them differently was
	 * the bug, not the symmetry.
	 *
	 * The Nextcloud move has already happened by the time this runs and cannot be undone
	 * from here, so a failure is reported rather than thrown: the two sides disagree, and
	 * saying so is the only honest ending.
	 */
	private function leaving(Folder $folder, string $uid): void {
		try {
			$this->cascade->trash($folder, $uid);
			$this->logger->info('grafana_sync: a mirrored folder left the mapped set; its dashboards followed', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'path' => $folder->getPath(),
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: a mirrored folder left the mapped set, but Grafana would not follow', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'path' => $folder->getPath(),
				'exception' => $e,
			]);
			$this->notifier->failed(
				$this->actingUserUid($folder),
				$folder->getId(),
				$folder->getName(),
				GrafanaClient::describeConnectionError($e),
			);
		}
	}

	/**
	 * A MIRRORED FOLDER MOVED WITHIN THE MAPPED SET: re-parent it in Grafana, keeping the
	 * uid, so every dashboard inside travels with it and none is re-created.
	 */
	private function moving(Folder $target, string $wasNamed, Mapping $mapping, string $uid): void {
		try {
			$parentUid = $this->folderMirror->folderUidFor($target, $mapping);
			$this->grafana->moveFolder($uid, $parentUid ?? '');

			// A WebDAV MOVE can change the parent AND the basename in one operation, and
			// FolderRenameListener steps aside the moment the parent differs — so if this
			// listener only re-parented, a drag that also renamed would leave the Grafana
			// folder correctly placed under a stale title, with nothing else coming to fix
			// it. Grafana has no call that does both: /move takes a parentUid, the title
			// is a separate PUT. So it is two calls here, even though Nextcloud did it in
			// one.
			$name = $target->getName();
			if ($name !== $wasNamed) {
				$this->grafana->renameFolder($uid, $name);
			}

			$this->logger->info('grafana_sync: re-parented a mirrored folder in Grafana', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'parentUid' => $parentUid,
				'name' => $name,
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
				$this->actingUserUid($target),
				$target->getId(),
				$target->getName(),
				GrafanaClient::describeConnectionError($e),
			);
		}
	}

	/**
	 * A FOLDER GRAFANA HAS NEVER SEEN LANDED IN A MAPPING, so everything in it becomes
	 * real — one file at a time, because Nextcloud raised no event for any of them.
	 *
	 * Each file is handed the move it just made, spelled as the path it came from, so the
	 * per-file service can tell an un-parking from a first arrival. A file that carries no
	 * identity has nothing to move and is created outright; a link mapping creates
	 * nothing, because its folder is Grafana's to fill.
	 *
	 * One file's failure never stops the rest: the folder is already here, and an
	 * unregistered `.grafana` is a file the user can re-save, not a loss.
	 */
	private function arriving(Folder $folder, string $fromPath, Mapping $mapping): void {
		$base = $folder->getPath();
		foreach ($this->cascade->dashboardFilesIn($folder) as $file) {
			$cameFrom = $fromPath . substr($file->getPath(), strlen($base));
			try {
				$managed = $this->metadata->read($file->getId());
				if ($managed?->isManaged() === true) {
					$this->motion->onMove($file, $cameFrom);
				} elseif ($mapping->mode === Mapping::MODE_SYNC) {
					$this->create->createForFile($file, $mapping);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('grafana_sync: a dashboard in an arriving folder could not be registered', [
					'app' => Application::APP_ID,
					'fileId' => $file->getId(),
					'path' => $file->getPath(),
					'exception' => $e,
				]);
			}
		}
	}
}
