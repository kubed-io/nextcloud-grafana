<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * The last third of the delete story: the Nextcloud trash follows Grafana in both
 * directions.
 *
 * A mirror sits in the Nextcloud trash only for as long as the dashboard it mirrors
 * still exists. Trashing a file parks its dashboard in the recycle-bin folder
 * (`delete.feature`); emptying the Nextcloud trash deletes it for good
 * (`purge.feature`). What neither covers is the OTHER side of the same gesture —
 * somebody empties the bin folder in Grafana — and until now that left the Nextcloud
 * trash holding an entry whose restore had nothing to reconnect to.
 *
 * {@see reap} closes it: a dashboard that is gone from Grafana purges its trashed
 * mirror too.
 *
 * ## WHY MIRRORING A PURGE WITH A PURGE IS THE RIGHT ANSWER
 *
 * The cautious rule would be *leave the trashed file alone* — once Grafana has
 * destroyed the dashboard, that file is the LAST COPY OF IT IN EXISTENCE, and reaching
 * in to delete the last copy on a schedule is the most destructive thing this app could
 * do. That fear is right about the stakes and wrong about the gesture: emptying the bin
 * folder is not an accident anyone has on a schedule. It is the second, deliberate step
 * of a two-step delete — the dashboard was already parked there — and it is exactly the
 * gesture Nextcloud spells "empty the trash".
 *
 * What the app must not do is GUESS. {@see isGone} refuses to purge unless it can prove
 * the dashboard is gone, and Grafana being unreachable is not proof.
 *
 * ## WHAT IT WILL NOT TOUCH
 *
 *   - a trash entry with no `grafana_uid` — never ours, never was
 *   - a file belonging to a DIFFERENT mapping — that mapping's pull will judge it
 *   - a file whose mode is not `sync` — an `unmapped` file left its mapping and its
 *     dashboard stopped being this app's business (`purge.feature` says the same about
 *     the user-driven purge), and a `link` is never trashed at all
 *   - anything whatsoever while the answer from Grafana is uncertain
 *
 * ## THE PULL'S LISTING IS NOT AN EXISTENCE SET HERE, UNLIKE THE SIBLING'S
 *
 * n8n can answer most of this for free: its tag listing returns ARCHIVED workflows too,
 * so the ids the pull just saw prove existence. Ours cannot. A parked dashboard has been
 * moved OUT of the mapped folder and into the bin, so it is absent from the mapping's
 * listing precisely BECAUSE it is parked — the state where the mirror legitimately stays
 * in the trash. Reading "absent from the listing" as "gone" would purge every correctly
 * parked mirror on the next pull.
 *
 * So every candidate is asked about by uid. The cost is one GET per trashed mirror of
 * this mapping, which is bounded by how many files the user has trashed, not by how many
 * dashboards exist.
 */
final class TrashReconcileService {
	public function __construct(
		private IRootFolder $rootFolder,
		private TrashControl $trash,
		private DashboardMetadata $metadata,
		private FolderMetadata $folders,
		private GrafanaClient $grafana,
		private RecycleBin $bin,
		private TeamFolderService $teamFolders,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Bring $mapping's trashed mirror of $dashboardUid back out of the trash, if there is
	 * one, and return the restored file.
	 *
	 * THE OTHER DIRECTION OF THE SAME RULE. A mirror sits in the trash only while its
	 * dashboard is out of the mapped folder; the moment the dashboard is back — somebody
	 * dragged it out of the recycle-bin folder in Grafana — the trash entry is describing
	 * a state that has stopped being true.
	 *
	 * WITHOUT THIS, THE PULL WRITES A SECOND FILE. The dashboard reappears in the mapped
	 * folder, `indexByUid` finds no live mirror for it (the only one is in the trash), and
	 * the pull does the reasonable thing and creates one — leaving the user a restored
	 * dashboard, a fresh file, and a trash entry for the file they actually had. Restoring
	 * the existing entry is what makes it the SAME file rather than a copy beside the
	 * original.
	 *
	 * Answers null whenever there is nothing to restore, which is the ordinary case: the
	 * caller then writes a mirror as it always did.
	 */
	public function restoreMirror(Mapping $mapping, string $dashboardUid): ?File {
		if ($dashboardUid === '') {
			return null;
		}
		$uid = $this->actorUid($mapping);
		if ($uid === null) {
			return null;
		}

		$trashed = $this->mirrors($uid, $mapping)[$dashboardUid] ?? null;
		if ($trashed === null) {
			return null;
		}

		try {
			// UNDER THE GUARD. A restore emits `post_restore`, and
			// {@see \OCA\GrafanaSync\Listener\TrashRestoreHook} answers it by pushing the
			// dashboard back into its mapped folder — which is where it already is, and
			// which is the news this whole pass is downstream of.
			$this->guard->run(static function () use ($trashed): void {
				$trashed->restore();
			});
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: could not restore the mirror of a rescued dashboard', [
				'app' => Application::APP_ID,
				'fileId' => $trashed->fileId,
				'name' => $trashed->name,
				'uid' => $dashboardUid,
				'exception' => $e,
			]);
			return null;
		}

		$node = $this->resolve($uid, $trashed->fileId);
		$this->logger->info('grafana_sync trash: brought a mirror back out of the trash for a rescued dashboard', [
			'app' => Application::APP_ID,
			'fileId' => $trashed->fileId,
			'name' => $trashed->name,
			'uid' => $dashboardUid,
			'resolved' => $node !== null,
		]);
		return $node;
	}

	/**
	 * The restored file, found by the id it kept through the trash.
	 *
	 * Null is survivable and is not a failure: the file IS back either way — that is what
	 * the restore did — and the caller falls back to writing a fresh mirror only if it
	 * cannot get a node to update. Looked up BY ID rather than by path because a restore
	 * can land the file under a `(1)` name when something has since taken its original
	 * one, and the id is the identity anyway.
	 */
	private function resolve(string $uid, int $fileId): ?File {
		try {
			$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: restored the mirror but could not find it afterwards', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * Purge the trashed mirrors of $mapping whose dashboards no longer exist in Grafana.
	 *
	 * Returns how many were purged, for the pull's counters.
	 */
	public function reap(Mapping $mapping): int {
		$uid = $this->actorUid($mapping);
		if ($uid === null) {
			return 0;
		}

		$purged = 0;
		foreach ($this->mirrors($uid, $mapping) as $dashboardUid => $trashed) {
			if (!$this->isGone($dashboardUid)) {
				continue;
			}

			try {
				// UNDER THE GUARD, because the home trash's purge fires the legacy
				// `preDelete` hook and {@see \OCA\GrafanaSync\Listener\TrashPurgeHook}
				// would answer it by deleting the dashboard in Grafana. Harmless in
				// itself — the dashboard is the thing that is already gone, so the call
				// is an idempotent no-op — but it would put a "deleting the dashboard"
				// line in the log for a purge doing the exact opposite, and this app has
				// already lost hours to trash diagnostics that said the wrong thing.
				$this->guard->run(static function () use ($trashed): void {
					$trashed->purge();
				});
				$purged++;
				$this->logger->info('grafana_sync trash: purged a mirror whose dashboard no longer exists in Grafana', [
					'app' => Application::APP_ID,
					'fileId' => $trashed->fileId,
					'name' => $trashed->name,
					'uid' => $dashboardUid,
					'mapping' => $mapping->id,
				]);
			} catch (\Throwable $e) {
				// A member without delete permission on the Team Folder, a backend that
				// refused: leave the entry alone and say so. It is still recoverable,
				// which is the failure direction to prefer.
				$this->logger->warning('grafana_sync trash: could not purge a trashed mirror', [
					'app' => Application::APP_ID,
					'fileId' => $trashed->fileId,
					'name' => $trashed->name,
					'uid' => $dashboardUid,
					'exception' => $e,
				]);
			}
		}
		return $purged;
	}

	/**
	 * The same reap, for the trashed FOLDERS — where the mirrors are one entry down.
	 *
	 * ## A PURGE IS A PURGE, AND THE ENTRY IS A SEPARATE QUESTION
	 *
	 * Two things can happen to a trashed folder when the dashboards it held are destroyed
	 * in Grafana, and conflating them is how this got written wrong once already:
	 *
	 *   - **every mirror inside goes**, always. Its dashboard has been deleted for good,
	 *     so the file is a mirror of nothing — offering a restore that reconnects to
	 *     nothing is the state this whole class exists to close.
	 *   - **the ENTRY goes only if nothing else was in it.** A spreadsheet has no far
	 *     side, so nothing that happened in Grafana may destroy it, and the entry is what
	 *     the user restores to get it back.
	 *
	 * So a folder of nothing but finished mirrors is purged whole — one call that takes
	 * the folder with them, rather than emptying it and leaving an entry whose restore
	 * puts back an empty folder. A folder holding anything else keeps its entry and loses
	 * only the mirrors.
	 *
	 * **THIS WAS BUILT THE OTHER WAY FIRST**, sparing the whole entry whenever anything
	 * else was in it — which is what the sibling does. It is wrong here and arguably
	 * there: it leaves a `.grafana` in the trash whose dashboard was permanently deleted,
	 * so a purge in Grafana did not purge in Nextcloud. A purge is a purge.
	 *
	 * ## A SURVIVOR VETOES THE ENTRY, NOT THE MIRRORS
	 *
	 * Anything the app cannot account for — a spreadsheet, a subtree that could not be
	 * read, one deeper than the walk goes — keeps the entry. So does a mirror that is NOT
	 * finished: a dashboard still in Grafana (parked, or rescued back out of the bin), or
	 * one belonging to another mapping, which that mapping's own pull will judge.
	 *
	 * @return int mirrors purged
	 */
	public function reapFolders(Mapping $mapping): int {
		$uid = $this->actorUid($mapping);
		if ($uid === null) {
			return 0;
		}

		$purged = 0;
		foreach ($this->trash->listTrashedFolders($uid) as $folder) {
			if ($folder->dashboards === []) {
				continue; // nothing of ours in here to finish
			}

			$finished = [];
			$survivors = $folder->holdsOtherFiles;
			foreach ($folder->dashboards as $mirror) {
				$managed = $this->metadata->read($mirror->fileId);
				if (!$managed?->isManaged() || !$managed->isSync() || $managed->mappingId !== $mapping->id) {
					// Another mapping's mirror, or one that left its mapping. Not ours to
					// finish, and its presence keeps the entry.
					$survivors = true;
					continue;
				}
				if (!$this->isGone($managed->uid)) {
					$survivors = true;
					continue;
				}
				$finished[] = $mirror;
			}

			if ($finished === []) {
				continue;
			}

			if (!$survivors) {
				// Nothing outlives them, so the entry goes and takes them with it — one
				// call instead of N, and no empty folder left in the trash.
				$purged += $this->purgeInTrash(
					static fn () => $folder->purge(),
					count($finished),
					$folder->name,
					$mapping,
				);
				continue;
			}

			foreach ($finished as $mirror) {
				$purged += $this->purgeInTrash(
					static fn () => $mirror->purge(),
					1,
					$folder->name . '/' . $mirror->name,
					$mapping,
				);
			}
		}
		return $purged;
	}

	/**
	 * Bring back the trashed FOLDERS whose dashboards someone rescued out of the bin.
	 *
	 * The mirror image of {@see reapFolders()}, and it answers the same two-part
	 * question in the same shape — because it is the same question with the sign
	 * flipped. Somebody went into the recycle-bin folder in Grafana and moved the
	 * parked dashboards back out; the trashed Nextcloud folder is now describing a
	 * state that has stopped being true.
	 *
	 * ## A RESTORE IS A RESTORE, AND THE ENTRY IS A SEPARATE QUESTION
	 *
	 *   - **every rescued mirror inside comes back**, always. Its dashboard is live
	 *     again and out of the bin, so a file left in the trash is the pull's cue to
	 *     write a SECOND one beside it — the duplicate {@see restoreMirror()} exists
	 *     to prevent, one folder deeper.
	 *   - **the ENTRY comes back only if nothing in it was left behind.** A
	 *     spreadsheet has no far side and nothing that happened in Grafana speaks for
	 *     it, so a folder still holding one keeps its entry and the user restores it
	 *     themselves.
	 *
	 * So a folder of nothing but rescued mirrors is restored whole — one call that
	 * brings the folder and its files back together, rather than restoring each file
	 * and leaving an empty entry in the trash for the user to notice and clear.
	 *
	 * ## AND THE RESTORED FOLDER IS RE-STAMPED, WHICH IS NOT OPTIONAL
	 *
	 * Trashing the folder DELETED its Grafana folder (`folders/delete.feature`: the
	 * folder goes either way). So whatever the rescuer moved those dashboards into is
	 * a NEW Grafana folder with a new uid, and the Nextcloud folder coming out of the
	 * trash still carries the dead one.
	 *
	 * That is not untidy, it is dangerous, and {@see FolderTreeMirror} already says
	 * why: the stamp is what decides where the next write goes, so a dead uid invites
	 * a mirror into a folder Grafana threw away. It is also what stops the very next
	 * line of the pull from making a mess — `FolderTreeMirror::sync()` looks the
	 * folder up BY UID, would not find this one, and would try to `newFolder()` a name
	 * that now exists. The collision is caught and the folder skipped, which quietly
	 * drops every dashboard in it back to the mapping's root.
	 *
	 * ## WHICH IS WHY THIS RUNS BEFORE THE TREE SYNC, NOT AFTER
	 *
	 * The other order loses either way: `sync()` creates its own `Revived` first, and
	 * then the restore lands beside it as `Revived (2)` — two folders, one Grafana
	 * folder, and the user's file in the one the app is not using.
	 *
	 * @return int mirrors brought back
	 */
	public function restoreFolders(Mapping $mapping): int {
		$uid = $this->actorUid($mapping);
		if ($uid === null) {
			return 0;
		}

		// NOTHING WAS EVER PARKED WITH THE BIN OFF, so there is nothing to be rescued
		// FROM and no signal to read: the dashboards were destroyed at trash time. A bin
		// that is on but unresolvable is the same answer for the opposite reason — the
		// one thing this pass has to be able to recognise is the bin itself, and
		// guessing would restore mirrors whose dashboards are still parked.
		try {
			$binUid = $this->bin->activeFolderUid();
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: could not resolve the recycle-bin folder; leaving the trashed folders alone', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return 0;
		}
		if ($binUid === null || $binUid === '') {
			return 0;
		}

		$restored = 0;
		foreach ($this->trash->listTrashedFolders($uid) as $folder) {
			if ($folder->dashboards === []) {
				continue; // nothing of ours in here to bring back
			}

			$rescued = [];
			$leftBehind = $folder->holdsOtherFiles;
			foreach ($folder->dashboards as $mirror) {
				$managed = $this->metadata->read($mirror->fileId);
				if (!$managed?->isManaged() || !$managed->isSync() || $managed->mappingId !== $mapping->id) {
					// Another mapping's mirror, or one that left its mapping. Not ours to
					// bring back, and its presence keeps the entry.
					$leftBehind = true;
					continue;
				}
				$into = $this->rescuedInto($managed->uid, $binUid);
				if ($into === null) {
					$leftBehind = true;
					continue;
				}
				$rescued[] = [$mirror, $into];
			}

			if ($rescued === []) {
				continue;
			}

			// THE RE-STAMP FOLLOWS THE RESTORE THAT WORKED, never the one that was
			// merely attempted. Stamping a folder for a file still sitting in the trash
			// would point a live folder at a Grafana folder holding nothing of ours.
			$back = [];
			if (!$leftBehind) {
				// Nothing was left behind, so the entry comes back whole and takes them
				// with it — one call instead of N, and no empty folder left in the trash.
				if ($this->restoreInTrash(static fn () => $folder->restore(), count($rescued), $folder->name, $mapping) > 0) {
					$back = $rescued;
				}
			} else {
				foreach ($rescued as $one) {
					[$mirror] = $one;
					$worked = $this->restoreInTrash(
						static fn () => $mirror->restore(),
						1,
						$folder->name . '/' . $mirror->name,
						$mapping,
					);
					if ($worked > 0) {
						$back[] = $one;
					}
				}
			}

			$restored += count($back);
			foreach ($back as [$mirror, $into]) {
				$this->reclaimFolderOf($uid, $mirror->fileId, $into, $mapping);
			}
		}
		return $restored;
	}

	/**
	 * Point the folder a restored mirror landed in at the Grafana folder holding its
	 * dashboard now.
	 *
	 * See {@see restoreFolders()} for why this is load-bearing rather than tidy. Read
	 * off the DASHBOARD rather than assumed, because the rescuer chose where to put it
	 * and may not have reproduced the tree the trash remembers.
	 *
	 * Silent when there is nothing to do: a mapping's own root folder is not a mirror
	 * of anything and is never stamped, and a folder already carrying the right uid is
	 * left alone rather than written for the sake of it.
	 */
	private function reclaimFolderOf(string $actor, int $fileId, string $grafanaFolderUid, Mapping $mapping): void {
		if ($grafanaFolderUid === '' || $grafanaFolderUid === $mapping->grafanaFolderUid) {
			return; // the dashboard sits in the mapped folder itself, which nothing stamps
		}
		$file = $this->resolve($actor, $fileId);
		if ($file === null) {
			return;
		}
		try {
			$parent = $file->getParent();
			if ($this->folders->uidOf($parent->getId()) === $grafanaFolderUid) {
				return;
			}
			$this->folders->stamp($parent->getId(), $grafanaFolderUid);
		} catch (\Throwable $e) {
			// The file is back either way, which is the part the user asked for. A wrong
			// stamp is the next pull's problem and it re-reads this every tick.
			$this->logger->warning('grafana_sync trash: restored a mirror but could not re-stamp the folder it landed in', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'grafanaFolderUid' => $grafanaFolderUid,
				'exception' => $e,
			]);
		}
	}

	/**
	 * One restore, under the guard, counted only if it worked.
	 *
	 * UNDER THE GUARD for the reason {@see restoreMirror()} gives: a restore emits
	 * `post_restore`, and {@see \OCA\GrafanaSync\Listener\TrashRestoreHook} answers it
	 * by pushing the dashboard back into its mapped folder — which is where the rescuer
	 * has already put it, and which is the news this whole pass is downstream of.
	 *
	 * A failure is logged and stepped over. The entry stays in the trash, which is the
	 * failure direction to prefer: the next tick asks again, and nothing was destroyed.
	 *
	 * @param \Closure():void $restore
	 */
	private function restoreInTrash(\Closure $restore, int $worth, string $what, Mapping $mapping): int {
		try {
			$this->guard->run($restore);
			$this->logger->info('grafana_sync trash: brought a trashed entry back for dashboards rescued out of the bin', [
				'app' => Application::APP_ID,
				'entry' => $what,
				'mirrors' => $worth,
				'mapping' => $mapping->id,
			]);
			return $worth;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: could not restore a trashed entry', [
				'app' => Application::APP_ID,
				'entry' => $what,
				'exception' => $e,
			]);
			return 0;
		}
	}

	/**
	 * The Grafana folder $uid sits in now, or null if that is not a rescue.
	 *
	 * The counterpart of {@see isGone()} and it runs on the same asymmetry: **answers
	 * null whenever it cannot tell.** A wrong null leaves a trash entry for the next
	 * tick to look at again; a wrong answer restores a file whose dashboard is still
	 * parked, undoing a delete the user meant. So an unreachable Grafana, a 404, a
	 * partial body — every one means "leave it".
	 *
	 * A dashboard still in the recycle-bin folder answers with the bin's own uid, which
	 * is the whole test: parked is not rescued, and its mirror belongs in the trash
	 * exactly where it is.
	 */
	private function rescuedInto(string $uid, string $binUid): ?string {
		if ($uid === '') {
			return null;
		}
		try {
			$record = $this->grafana->readDashboard($uid);
		} catch (GrafanaApiException $e) {
			if ($e->httpStatus !== 404) {
				$this->logger->warning('grafana_sync trash: could not ask where a dashboard is; leaving its mirror in the trash', [
					'app' => Application::APP_ID,
					'uid' => $uid,
					'status' => $e->httpStatus,
					'exception' => $e,
				]);
			}
			return null; // a 404 is reap()'s business, not this pass's
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: could not reach Grafana; leaving the mirror in the trash', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return null;
		}

		// NO `meta` IS NOT THE GRAFANA ROOT. Grafana has answered 200 with a partial
		// body before now ({@see GrafanaClient::readDashboard}), and reading that as
		// "folderUid is empty, so it is out of the bin" would restore on a bad response.
		$meta = $record['meta'] ?? null;
		if (!is_array($meta)) {
			return null;
		}
		$folderUid = (string)($meta['folderUid'] ?? '');
		return $folderUid === $binUid ? null : $folderUid;
	}

	/**
	 * One purge, under the guard, counted only if it worked.
	 *
	 * UNDER THE GUARD for the reason {@see reap()} gives: the purge fires the legacy
	 * `preDelete` hook and {@see \OCA\GrafanaSync\Listener\TrashPurgeHook} would answer it
	 * by deleting the dashboard in Grafana. Harmless in itself — that dashboard is the
	 * thing that is already gone — but it would log the exact opposite of what happened,
	 * and this app has lost hours to trash diagnostics that said the wrong thing.
	 *
	 * A failure is logged and stepped over. The entry is still recoverable, which is the
	 * failure direction to prefer.
	 *
	 * @param \Closure():void $purge
	 */
	private function purgeInTrash(\Closure $purge, int $worth, string $what, Mapping $mapping): int {
		try {
			$this->guard->run($purge);
			$this->logger->info('grafana_sync trash: purged a trashed entry whose dashboards no longer exist in Grafana', [
				'app' => Application::APP_ID,
				'entry' => $what,
				'mirrors' => $worth,
				'mapping' => $mapping->id,
			]);
			return $worth;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: could not purge a trashed entry', [
				'app' => Application::APP_ID,
				'entry' => $what,
				'exception' => $e,
			]);
			return 0;
		}
	}

	/**
	 * Whose trash to look in: the sync actor's, or null when there isn't one.
	 *
	 * `resolveActorUid()` throws on an instance whose admin group has no members. A pull
	 * must survive that — the reconcile is a pass inside the pull, not the point of it —
	 * so it is caught here rather than at every call site.
	 */
	private function actorUid(Mapping $mapping): ?string {
		try {
			return $this->teamFolders->resolveActorUid();
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: no sync actor, so no trash to reconcile', [
				'app' => Application::APP_ID,
				'mapping' => $mapping->id,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * $mapping's trashed `sync` mirrors in $uid's trash, keyed by the dashboard each one
	 * mirrors.
	 *
	 * The NAME is tested before the metadata because it costs nothing and answers almost
	 * everything: this is a whole user's trash, and the overwhelming majority of what is
	 * in it has never had anything to do with this app. Only entries that look like ours
	 * cost a metadata read.
	 *
	 * TWO MIRRORS OF ONE DASHBOARD CANNOT BOTH BE KEYED, and the later one wins. That
	 * needs the same dashboard mirrored twice in one mapping AND both copies trashed, and
	 * either survivor is a correct answer — the loser stays in the trash for the next
	 * tick, which keys it once the winner is out.
	 *
	 * @return array<string,TrashedFile>
	 */
	private function mirrors(string $uid, Mapping $mapping): array {
		$index = [];
		foreach ($this->trash->listTrashed($uid) as $trashed) {
			if (!FilenameCodec::isDashboardName($trashed->name)) {
				continue;
			}
			$managed = $this->metadata->read($trashed->fileId);
			if (!$managed?->isManaged() || !$managed->isSync() || $managed->mappingId !== $mapping->id) {
				continue;
			}
			$index[$managed->uid] = $trashed;
		}
		return $index;
	}

	/**
	 * Is $uid really gone from Grafana?
	 *
	 * Answers **false whenever it cannot tell**, and that asymmetry is the safety
	 * property of this whole class. A wrong "no" leaves a trash entry the next tick looks
	 * at again; a wrong "yes" destroys the last copy of a dashboard, and Grafana has no
	 * undo. So an unreachable Grafana, a 500, a transport error — every one means "leave
	 * it". Only an explicit 404 counts as proof.
	 *
	 * A dashboard sitting in the recycle-bin folder answers 200 here, which is the whole
	 * point: parked is not gone, and its mirror belongs in the trash exactly where it is.
	 */
	private function isGone(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		try {
			$this->grafana->readDashboard($uid);
			return false; // still there — parked in the bin, or refiled somewhere else
		} catch (GrafanaApiException $e) {
			if ($e->httpStatus === 404) {
				return true;
			}
			$this->logger->warning('grafana_sync trash: could not confirm a dashboard is gone; leaving its mirror in the trash', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'status' => $e->httpStatus,
				'exception' => $e,
			]);
			return false;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync trash: could not reach Grafana; leaving the mirror in the trash', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return false;
		}
	}
}
