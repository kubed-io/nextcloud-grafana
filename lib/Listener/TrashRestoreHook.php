<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The RESTORE step for every trash that is not the home one — the mirror image of
 * {@see TeamFolderPurgeListener}, and the same hole for the same reason.
 *
 * ## THE TYPED EVENT IS `files_trashbin`-ONLY
 *
 * {@see RestoreFromTrashListener} keys on `OCA\Files_Trashbin\Events\NodeRestoredEvent`,
 * which `Files_Trashbin\Trashbin::restore()` emits and groupfolders' `TrashBackend` does
 * not. So restoring a dashboard file out of a TEAM FOLDER's trash — the trash a
 * `team folder` mapping actually uses — reached Grafana never.
 *
 * AND THE FAILURE IS A LOOP, NOT A NO-OP. With the recycle bin on, the dashboard stays
 * parked in the bin folder while the file comes back to a mapped folder. The next pull
 * finds a mirror whose dashboard is not in the mapped Grafana folder, decides the mirror
 * should not be there, and trashes the file again. The user restores, waits, and watches
 * it vanish — with nothing in the log, because every step behaved correctly.
 *
 * ## `post_restore` IS THE ONE SIGNAL BOTH TRASHES EMIT
 *
 * Both backends emit the legacy `post_restore` hook — `Trashbin::restore()` and
 * groupfolders' `TrashBackend::restoreItem()` — carrying the RESTORED path. Reading it
 * here is what makes one code path cover both, so the deprecation is as unavoidable as
 * {@see TrashPurgeHook}'s.
 *
 * UNDER A DIFFERENT SIGNAL CLASS FROM ITS NEIGHBOUR, though, and that is a real trap:
 * `preDelete` is emitted under `\OCP\Trashbin`, `post_restore` under
 * `\OCA\Files_Trashbin\Trashbin`. Registered beside the purge hook under `\OCP\Trashbin`
 * — the obvious thing to do — this class was connected and never once called, and the
 * only visible symptom was the Team Folder restore it exists to fix still not working.
 *
 * ## BOTH ENTRY POINTS ARE KEPT, ON PURPOSE
 *
 * The typed listener still runs for a home-storage restore, so that case now reaches
 * Grafana twice. That is deliberate: moving a dashboard back into the folder it is
 * already in is an idempotent upsert, and one redundant call on the backend that already
 * worked is cheaper than betting the working path on a legacy hook firing identically in
 * every Nextcloud version. The Team Folder case has only this hook.
 *
 * The double call would matter if either entry point could CREATE twice — the bin-off
 * branch of {@see \OCA\GrafanaSync\Service\DeleteService::restore} does exactly that. It
 * cannot: both entry points read the metadata fresh, so whichever runs second sees the
 * uid the first one stamped and takes the upsert branch instead.
 *
 * Failures are logged and swallowed, exactly as the typed listener does. The file is
 * already back; stranding it because Grafana is down would be worse than a dashboard one
 * manual sync away from correct.
 */
final class TrashRestoreHook {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private RestoreFromTrashListener $restore,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Slot for the legacy `\OCA\Files_Trashbin\Trashbin` `post_restore` hook.
	 *
	 * `$params['filePath']` is the path the file was restored TO, relative to the user's
	 * files root — `/Shared/Fleet Health.grafana` for a Team Folder, because groupfolders
	 * composes it from the mount point. `$params['trashPath']` is where it came from and
	 * is not needed: the file is already back, and its id carried its metadata through
	 * the trash.
	 *
	 * @param array{filePath?: string, trashPath?: string} $params
	 */
	public function postRestore(array $params): void {
		if ($this->guard->active()) {
			return;
		}

		$path = $params['filePath'] ?? '';
		// Cheap pre-filter. Unlike the purge hook's, the restored name is the ORIGINAL
		// one — the deletion timestamp came off on the way out of the trash — so the
		// extension really is last here.
		if ($path === '' || !FilenameCodec::isDashboardName(basename($path))) {
			return;
		}

		$uid = $this->resolveUid();
		if ($uid === '') {
			$this->logger->warning('grafana_sync restore: no user context for the restored node; skipping', [
				'app' => Application::APP_ID,
				'path' => $path,
			]);
			return;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($path, '/'));
		} catch (\Throwable $e) {
			// WARNING, not debug: a restore that cannot find the file it just restored is
			// the failure this class exists to prevent, and silence is what let the Team
			// Folder case go unnoticed on the sibling for a whole release.
			$this->logger->warning('grafana_sync restore: could not resolve the restored node', [
				'app' => Application::APP_ID,
				'path' => $path,
				'uid' => $uid,
				'exception' => $e,
			]);
			return;
		}
		if (!$node instanceof File) {
			return;
		}

		// The stamp read, the mapping lookup and the log-and-swallow error policy all
		// live in restoreOne — shared with the typed listener, on purpose.
		$this->restore->restoreOne($node);
	}

	/**
	 * Whose restore this is.
	 *
	 * An interactive restore has a session user. A background one has none, but sets the
	 * filesystem up for the user it is processing, so `\OC_User::getUser()` names them.
	 * Deliberately NOT the rule the event listeners use — they fall back to the file's
	 * owner or the sync actor, which would be wrong here: a trash is always somebody's,
	 * and the filesystem setup says whose. {@see TrashPurgeHook} resolves it the same way.
	 */
	private function resolveUid(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid !== '') {
			return $uid;
		}
		$fsUser = \OC_User::getUser();
		return $fsUser === false ? '' : $fsUser;
	}
}
