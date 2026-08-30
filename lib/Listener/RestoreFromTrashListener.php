<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Mirrors NC's "restore from trash" back into Grafana (`delete.feature`). Fires after a restore
 * completes; NC preserves the file id through the trash, so a still-parked file's metadata still
 * carries its uid + mapping. {@see DeleteService::restore} reverses whichever delete step ran:
 *
 *   - a **still-managed** file (bin ON parked it, id kept) → move the dashboard back into its
 *     mapped folder, same uid. The mapping comes from the file's stored `grafana_mapping`.
 *   - an **unmanaged** file (bin OFF stripped its id at trash-time) → re-create it from the
 *     file's JSON when it lands back in a mapped **sync** folder — a fresh dashboard, new uid.
 *     The mapping is resolved from where the file was restored to.
 *
 * **Failures here are logged + swallowed.** Don't block the user's restore just because Grafana
 * is down — a temporarily out-of-sync local file (which the next manual sync fixes) beats a file
 * stuck in the trash.
 *
 * @implements IEventListener<NodeRestoredEvent>
 */
final class RestoreFromTrashListener implements IEventListener {
	public function __construct(
		private DeleteService $deleteService,
		private MappingService $mappings,
		private DashboardMetadata $metadata,
		private FolderCascade $cascade,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeRestoredEvent) {
			return;
		}
		if ($this->guard->active()) {
			return;
		}
		$this->restoreTree($event->getTarget());
	}

	/**
	 * One restored node, file or folder, dispatched to the per-file rule table.
	 *
	 * PUBLIC FOR THE SAME REASON {@see restoreOne()} IS, and it is the level the second
	 * entry point actually needs. {@see TrashRestoreHook} covers the trashes the typed
	 * event never fires for — a Team Folder's above all — and it was calling
	 * `restoreOne()` directly, which meant it could only ever answer a FILE restore.
	 *
	 * So the folder branch below existed only on the path a groupfolder does not take,
	 * and restoring a folder out of a Team Folder's trash reached Grafana never. That is
	 * the whole of the `Restore a folder in a Team Folder` gap: not the groupfolder trash
	 * backend being exotic, just this dispatch living one level too deep for the caller
	 * that needed it.
	 */
	public function restoreTree(\OCP\Files\Node $target): void {
		// A FOLDER restore is one event for the whole subtree — Nextcloud fires nothing
		// for the files inside it, exactly as it fires nothing for them on the way in
		// (see {@see \OCA\GrafanaSync\Service\FolderCascade}). Without this branch a
		// folder trash would be a ONE-WAY DOOR: the delete cascade reaches every
		// dashboard, and nothing brings any of them back.
		if ($target instanceof Folder) {
			foreach ($this->cascade->dashboardFilesIn($target) as $file) {
				$this->restoreOne($file);
			}
			return;
		}

		if (!FilenameCodec::isDashboardFile($target)) {
			return;
		}
		/** @var \OCP\Files\File $target — isDashboardFile guarantees a File */
		$this->restoreOne($target);
	}

	/**
	 * The per-file rule table, reached one file at a time or a whole folder at a time.
	 *
	 * PUBLIC BECAUSE A SECOND ENTRY POINT NEEDS IT. {@see TrashRestoreHook} covers the
	 * trashes this typed event never fires for, and both must answer a restore the same
	 * way — a second copy of these branches is a second place for them to drift.
	 */
	public function restoreOne(\OCP\Files\File $target): void {
		$managed = $this->metadata->read($target->getId());
		if ($managed !== null && $managed->isManaged()) {
			// BIN ON parked path: move the dashboard back into its stored mapping's folder. If the
			// stored mapping no longer resolves (it was removed, or removed then re-created with a
			// new id), fall back to the mapping for where the file was actually restored, so a
			// re-mapped folder reconnects the restored file instead of leaving it parked.
			$mapping = $managed->mappingId !== '' ? $this->mappings->getById($managed->mappingId) : null;
			if ($mapping === null) {
				$mapping = $this->mappings->resolveForPath($target->getPath());
			}
			$this->tryRestore($target, $managed, $mapping);
			return;
		}

		// BIN OFF stripped path (or a plain restored file): re-create only when it lands back in
		// a mapped sync folder. resolveForPath keys on the restored (real) path, not the trash.
		$mapping = $this->mappings->resolveForPath($target->getPath());
		if ($mapping === null) {
			return; // restored outside any mapping — leave it as a plain document
		}
		// A synthetic unmanaged marker so DeleteService takes the re-create branch.
		$unmanaged = new ManagedFile('', '', '', '', '', '');
		$this->tryRestore($target, $unmanaged, $mapping);
	}

	private function tryRestore(\OCP\Files\File $target, ManagedFile $managed, ?\OCA\GrafanaSync\Service\Mapping $mapping): void {
		try {
			$this->deleteService->restore($target, $managed, $mapping);
		} catch (\Throwable $e) {
			// Log + swallow: see class docblock — never block the restore.
			$this->logger->warning('grafana_sync restore: Grafana-side restore failed; the file is already back in Nextcloud', [
				'app' => Application::APP_ID,
				'fileId' => $target->getId(),
				'exception' => $e,
			]);
		}
	}
}
