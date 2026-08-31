<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * A folder gesture reaching every dashboard underneath it (`folders/delete.feature`,
 * `folders/purge.feature`).
 *
 * ## WHY THIS EXISTS AT ALL — THE ASSUMPTION THAT WAS WRONG
 *
 * The delete engine was built one file at a time, on the understanding that Nextcloud
 * decomposes a folder delete into a delete per descendant. **It does not.** Verified
 * live against the running instance:
 *
 *  - `View::unlink()` on a directory runs ONE `basicOperation('rmdir', …, ['delete'])`,
 *    so exactly one {@see \OCP\Files\Events\Node\BeforeNodeDeletedEvent} fires — for the
 *    folder. Every file inside is removed by the storage layer with no hook at all.
 *  - The trashbin's purge is the same shape: `Trashbin::delete()` emits one legacy
 *    `preDelete` for the trashed entry and then calls `$node->delete()`, which takes the
 *    whole subtree silently. `deleteAll()` emits one per TOP-LEVEL entry, no deeper.
 *
 * So {@see DeleteToGrafanaListener} and {@see TrashPurgeHook} — both of which require a
 * {@see File} — never fired for anything inside a trashed folder. Dragging a folder of
 * dashboards to the trash left every one of them live in Grafana, still stamped on files
 * sitting in the Nextcloud trash. The subtree walk that Nextcloud does not do is here.
 *
 * ## THE RULE TABLE
 *
 * `DELETE /api/folders/:uid` **cascades** ({@see GrafanaClient::deleteFolder}), which is
 * what makes the bin-off case one request rather than N — and what forces the bin-on case
 * to park every dashboard BEFORE the folder goes, since anything still inside when the
 * folder is deleted is destroyed with it.
 *
 * It does NOT count what it is about to reach and warn about it. The Nextcloud trash is
 * that confirmation, and emptying it is the second one — see
 * `features/AGENTS.md`, "RETIRED — warning that forty dashboards are about to be
 * deleted".
 *
 * | step                | bin OFF                                  | bin ON                                    |
 * |---------------------|------------------------------------------|-------------------------------------------|
 * | trash the folder    | delete the folder; it cascades over every | park each dashboard in the bin, THEN      |
 * |                     | dashboard, then strip the files' identity | delete the now-empty folder               |
 * | purge from trash    | nothing left — already stripped           | delete each parked dashboard, one by one  |
 *
 * The folder itself goes in both columns. The bin decides what happens to the
 * DASHBOARDS; trashing a folder is a delete, and a delete carries whatever it held.
 *
 * ## WHAT IT DELIBERATELY DOES NOT REACH
 *
 * A mapping's own folder is never stamped — {@see FolderMirror} and
 * {@see FolderTreeMirror} stamp subfolders only — so trashing it never gets here. That is
 * the safe answer for now: deleting the Grafana folder a mapping points at would leave the
 * mapping aimed at a dead uid, and the mapping is meant to outlive the folder so the next
 * pull can rebuild it. Deciding what a mapped-root trash should do is its own scenario and
 * is still unwritten.
 */
final class FolderCascade {
	public function __construct(
		private DeleteService $deleteService,
		private DashboardMetadata $metadata,
		private FolderMetadata $folders,
		private RecycleBin $recycleBin,
		private GrafanaClient $grafana,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The soft step for a whole folder: the user moved a mirrored folder to the trash.
	 *
	 * Throws if Grafana will not confirm, so the caller can abort the Nextcloud delete —
	 * the same never-desync policy the single-file path uses.
	 */
	public function trash(Folder $folder, string $uid): void {
		// Resolve the bin ONCE for the whole gesture. Asking per file would re-read the
		// setting and re-resolve the folder for every dashboard, and — worse — could
		// answer differently halfway through a subtree.
		$binUid = $this->recycleBin->activeFolderUid();
		$files = $this->dashboardsIn($folder);

		if ($binUid !== null) {
			// BIN ON. Park first: the folder delete below cascades, so a dashboard still
			// inside it when it goes would be destroyed rather than preserved — exactly
			// the outcome the admin turned the bin on to prevent.
			foreach ($files as [$file, $managed]) {
				$this->deleteService->softDelete($file, $managed, $binUid);
			}
			$this->grafana->deleteFolder($uid);
			$this->forgetMirrors($folder);
			return;
		}

		// BIN OFF. One request takes the folder and every dashboard under it. Delete
		// FIRST and strip after: if Grafana cannot confirm, the exception propagates, the
		// Nextcloud delete is aborted, and every file keeps the identity that makes it
		// reconcilable. Stripping first would orphan them on a failure.
		$this->grafana->deleteFolder($uid);
		foreach ($files as [$file]) {
			$this->guard->run(fn () => $this->metadata->clear($file->getId()));
		}
		$this->forgetMirrors($folder);
	}

	/**
	 * The hard step for a whole folder: a trashed folder is being purged for good.
	 *
	 * Bin OFF has nothing to do — {@see trash()} already deleted the dashboards and
	 * stripped the files, so nothing under here reads as managed. Bin ON is the
	 * irreversible moment, one parked dashboard at a time via
	 * {@see DeleteService::hardDelete}, which re-checks each is still sitting in the bin.
	 *
	 * Failures are logged and swallowed, not thrown: a legacy hook cannot cleanly abort a
	 * purge, and a parked dashboard left behind is a recoverable leak rather than data
	 * loss. One unreachable dashboard must not stop the rest of the subtree either.
	 */
	public function purge(Folder $folder): void {
		foreach ($this->dashboardsIn($folder) as [$file, $managed]) {
			try {
				$this->deleteService->hardDelete($managed);
			} catch (\Throwable $e) {
				$this->logger->warning('grafana_sync empty-trash: could not delete a parked dashboard from a purged folder', [
					'app' => Application::APP_ID,
					'fileId' => $file->getId(),
					'uid' => $managed->uid,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Every dashboard file under $folder, at any depth, managed or not.
	 *
	 * The restore path needs the unmanaged ones too: a bin-off trash strips a file's
	 * identity, so the files coming back out of the trash are exactly the ones this
	 * class's other methods filter away. Deciding what to do with each is
	 * {@see \OCA\GrafanaSync\Listener\RestoreFromTrashListener}'s job, not this one's —
	 * all that is shared is the walk Nextcloud does not do.
	 *
	 * @return list<File>
	 */
	public function dashboardFilesIn(Folder $folder): array {
		$found = [];
		foreach ($this->descend($folder) as $node) {
			if (FilenameCodec::isDashboardFile($node)) {
				/** @var File $node — isDashboardFile guarantees a File */
				$found[] = $node;
			}
		}
		return $found;
	}

	/**
	 * Every managed dashboard file under $folder, at any depth, paired with its metadata.
	 *
	 * Returned as a materialised list rather than a generator on purpose: the callers
	 * iterate it twice (park, then delete the folder) and — in the bin-off case — read it
	 * AFTER a Grafana call that changes the world. A lazy walk would be re-run against a
	 * tree that has already started disappearing.
	 *
	 * @return list<array{0: File, 1: ManagedFile}>
	 */
	private function dashboardsIn(Folder $folder): array {
		$found = [];
		foreach ($this->descend($folder) as $node) {
			if (!FilenameCodec::isDashboardFile($node)) {
				continue;
			}
			/** @var File $node — isDashboardFile guarantees a File */
			$managed = $this->metadata->read($node->getId());
			if ($managed?->isManaged() !== true) {
				continue; // never ours, or already stripped
			}
			if ($managed->isLink()) {
				continue; // a link never owned the dashboard
			}
			$found[] = [$node, $managed];
		}
		return $found;
	}

	/**
	 * Depth-first walk of a folder's contents. A folder that will not open is logged and
	 * skipped rather than aborting the gesture: one unreadable subfolder must not leave the
	 * other dashboards half-handled.
	 *
	 * @return list<Node>
	 */
	private function descend(Folder $folder): array {
		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not read a folder while cascading a delete', [
				'app' => Application::APP_ID,
				'path' => $folder->getPath(),
				'exception' => $e,
			]);
			return [];
		}

		$nodes = [];
		foreach ($children as $child) {
			$nodes[] = $child;
			if ($child instanceof Folder) {
				foreach ($this->descend($child) as $deeper) {
					$nodes[] = $deeper;
				}
			}
		}
		return $nodes;
	}

	/**
	 * Drop `grafana_folder_uid` from every mirrored subfolder in the subtree.
	 *
	 * The Grafana folders are gone — the cascade took them — so those stamps now name
	 * folders that do not exist. A trashed node keeps its file id in Nextcloud, and so
	 * keeps its metadata, which means a restore would otherwise bring back a folder
	 * claiming to mirror a dead uid and every dashboard created under it would be written
	 * into nothing.
	 *
	 * Never throws: the Grafana side is already settled by the time this runs, and failing
	 * here would abort a Nextcloud delete over bookkeeping.
	 */
	private function forgetMirrors(Folder $folder): void {
		foreach ([$folder, ...$this->descend($folder)] as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			try {
				$this->guard->run(fn () => $this->folders->clear($node->getId()));
			} catch (\Throwable $e) {
				$this->logger->warning('grafana_sync: could not clear a folder mirror stamp after a delete', [
					'app' => Application::APP_ID,
					'path' => $node->getPath(),
					'exception' => $e,
				]);
			}
		}
	}
}
