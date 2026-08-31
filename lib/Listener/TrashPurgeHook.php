<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\RestoreInProgress;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The **permanent-delete-from-trash** ("empty the trash") step of the delete lifecycle.
 *
 * This is the one irreversible moment when the recycle-bin is ON: a dashboard parked in the bin
 * folder gets permanently deleted. But — unlike the move-to-trash step — Nextcloud does NOT fire a
 * typed `BeforeNodeDeletedEvent` when a file is purged from the trash (proven live: the trashbin's
 * `removeItem` fires nothing typed). It emits the **legacy `\OCP\Trashbin` `preDelete` hook**
 * instead, just before it unlinks the trashed node — so we hook that. {@see Application::boot}
 * wires it with `\OCP\Util::connectHook`.
 *
 * The hook path is `/files_trashbin/files/<name>.d<timestamp>`, and the node still exists at that
 * point, so we resolve it, read its metadata, and delegate to {@see DeleteService::hardDelete}:
 *   - bin ON, still-managed sync file → delete the parked dashboard (only this one — never a
 *     wholesale bin-clear; the bin may hold dashboards Nextcloud doesn't manage).
 *   - bin OFF → the id was already stripped at move-to-trash, so the file is unmanaged here and
 *     hardDelete is a no-op (the dashboard is long gone).
 *   - link / untracked → no-op.
 *
 * A trashed **folder** arrives here the same way and gets one hook for the whole subtree —
 * Nextcloud emits nothing for its contents — so it is handed to {@see FolderCascade::purge()},
 * which walks it and applies the rule above to each parked dashboard it finds.
 *
 * A failure is logged and swallowed: a legacy hook can't cleanly abort the purge, and a parked
 * dashboard left behind in the bin is a recoverable leak, never data loss.
 */
final class TrashPurgeHook {
	public function __construct(
		private RestoreInProgress $restoring,
		private DeleteService $deleteService,
		private DashboardMetadata $metadata,
		private FolderCascade $cascade,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Slot for the `\OCP\Trashbin` `preDelete` hook. $params['path'] is the trash-relative path
	 * `/files_trashbin/files/<name>.d<timestamp>` of the node about to be permanently deleted.
	 *
	 * @param array{path?: string} $params
	 */
	public function preDelete(array $params): void {
		if ($this->guard->active()) {
			return;
		}
		$path = $params['path'] ?? '';
		if ($path === '') {
			return;
		}
		// NO NAME-BASED PRE-FILTER ANY MORE. A trashed dashboard is
		// "<orig>.grafana.d<timestamp>", so `.grafana` used to settle the whole
		// question without resolving anything — but it also silently dropped every FOLDER,
		// and a folder's name says nothing about what is inside it. A purged folder emits
		// ONE hook, for itself, and none for its contents (see {@see FolderCascade}), so
		// that fast path was the reason emptying the trash left a folder's parked
		// dashboards in Grafana forever. There is no name that distinguishes "Team.d123"
		// from "Report.d123", so the node has to be resolved to find out.
		//
		// Whose trash is this? An interactive purge has a session user; a background retention
		// cleanup ({@see \OCA\Files_Trashbin\BackgroundJob\ExpireTrash}) has none, but it sets up
		// the filesystem for the user it's processing, so \OC_User::getUser() names them. Try the
		// session first, then that FS context — otherwise a retention purge would leak the parked
		// dashboard, breaking the "emptying the trash deletes it for good" guarantee.
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			$fsUser = \OC_User::getUser();
			$uid = $fsUser === false ? '' : $fsUser;
		}
		if ($uid === '') {
			$this->logger->warning('grafana_sync empty-trash: no user context to resolve the trashed node; skipping', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);
			return;
		}

		try {
			// The user's home is …/<uid>/files; the trash lives at …/<uid>/files_trashbin, so we
			// resolve the hook path against the home's parent. The node still exists (pre-unlink).
			$node = $this->rootFolder->getUserFolder($uid)->getParent()->get(ltrim($path, '/'));
		} catch (\Throwable) {
			return; // couldn't resolve — nothing we can safely act on
		}
		if ($node instanceof Folder) {
			// One hook, a whole subtree. The cascade walks it and purges each parked
			// dashboard; it logs and swallows its own failures, for the same reason this
			// method does.
			$this->cascade->purge($node);
			return;
		}
		if (!$node instanceof File) {
			return;
		}

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // bin OFF stripped it already, or never ours — nothing to delete
		}

		try {
			// A RESTORE REACHES THIS HOOK, AND IT IS NOT A PURGE. Restoring over WebDAV is
			// a MOVE out of `trashbin/`, which Sabre cannot do as a rename across
			// collections — so it copies and then DELETES the trashed node, and that
			// delete is `Trashbin::delete()`, the very function emptying the trash calls,
			// emitting this very hook. Nothing at this depth can tell the two apart;
			// {@see \OCA\GrafanaSync\Service\RestoreInProgress} is set from the DAV
			// layer, which is the only place that still knows.
			//
			// Without it a restore permanently deleted the dashboard it was restoring.
			if ($this->restoring->active()) {
				$this->logger->info('grafana_sync purge: this trashbin delete is half of a restore; leaving Grafana alone', [
					'app' => Application::APP_ID,
					'uid' => $managed->uid,
				]);
				return;
			}

			$this->deleteService->hardDelete($managed);
		} catch (\Throwable $e) {
			// Log + swallow: a legacy hook can't cleanly abort, and a leftover parked dashboard
			// is a recoverable leak, not data loss.
			$this->logger->warning('grafana_sync empty-trash: could not delete the parked dashboard in Grafana', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'uid' => $managed->uid,
				'exception' => $e,
			]);
		}
	}
}
