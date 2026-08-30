<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Tears down a folder mapping (`mapping/delete.feature`). Removing a mapping is *not* the
 * Purge button (that keeps the mapping and never touches Grafana) — it dissolves the
 * connection, and the question is what happens to the files that were connected through it.
 *
 * ## THE MODE DECIDES, AND IT IS ASKED OF EACH FILE
 *
 *  - **link** — the file GOES, with no trash entry. A link is a pointer whose only meaning
 *    was the mapping; once the mapping is gone there is nothing left for it to be, and a
 *    trash entry would offer a restore that reconnects to nothing.
 *  - **sync** — the file STAYS and becomes unmapped. It holds the dashboard JSON itself and
 *    may be the last copy of it in existence. Removing a mapping is an administrative act
 *    about a connection; destroying somebody's archive on the way past is not something it
 *    gets to do. The uid stays too — the dashboard is still there — it simply stops meaning
 *    "reattach me".
 *
 * Asked of each FILE rather than of the mapping, which is the same answer for every tree the
 * pull builds and the right answer for a tree that is mixed.
 *
 * Files that were **never connected** (an unmapped / untracked standalone `.grafana`) are
 * left strictly alone. And **NEITHER FOLDER IS EVER REMOVED** — not the Nextcloud folder, not
 * the Grafana folder — which is what lets a later re-map land straight back onto itself.
 *
 * ## IT RUNS UNDER {@see SyncGuard}, AND THAT IS NOT A DETAIL
 *
 * Removing a link is a `Node::delete()`, which fires the same {@see
 * \OCP\Files\Events\Node\BeforeNodeDeletedEvent} a person's delete does — and
 * {@see \OCA\GrafanaSync\Listener\DeleteToGrafanaListener} answers that by reaching into
 * Grafana. Without the guard this method could not work at all:
 *
 *  - the listener REFUSES to delete a link ("cannot be deleted from Nextcloud"), so every
 *    link file threw, the tear-down counted them all as failures, and removing a link
 *    mapping errored out and kept the mapping — measured, and no test caught it because the
 *    teardown suite only ever built `sync` files;
 *  - and on the sync side the old code trashed the files instead of unmapping them, so with
 *    the recycle bin OFF the trash-move DELETED every dashboard in Grafana, from the one
 *    action whose whole promise is that it costs you neither a dashboard nor a file.
 *
 * The guard fences the whole walk rather than one file at a time: this is one operation, and
 * a delete that escaped the fence would reach Grafana.
 *
 * ## AND IT NEVER FAILS THE REMOVAL
 *
 * Removing the mapping is the act the admin asked for, and it must not fail because one file
 * would not move. The binding is dropped first — it is the half that can throw, and torn down
 * first a throw would leave the mapping configured over a tree already dismantled — then each
 * file is its own try, and one that resists is logged and left where it is.
 *
 * Ported from `kubed-io/nextcloud-penpot`, where this shape was worked out.
 */
final class MappingTeardownService {
	public function __construct(
		private MappingService $mappings,
		private StorageService $storage,
		private DashboardMetadata $metadata,
		private TrashControl $trash,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Drop the mapping, then tear its files down by mode.
	 *
	 * @throws \OutOfBoundsException when no mapping has that id (preserves the callers' 404)
	 */
	public function remove(string $id): void {
		$mapping = $this->mappings->getById($id);
		if ($mapping === null) {
			throw new \OutOfBoundsException('No mapping with id "' . $id . '".');
		}

		// THE BINDING GOES FIRST. It is the only half that can throw, and the mapping
		// OBJECT is already loaded — which is all `findFolder()` needs. Torn down first,
		// a throw here would leave the mapping configured over a tree already dismantled.
		$this->mappings->delete($id);

		$folder = $this->storage->findFolder($mapping);
		if ($folder === null) {
			// Never provisioned, or already deleted by hand. Either way there is nothing
			// to answer for — and the folder is not this method's to re-create.
			return;
		}

		// THE WHOLE WALK INSIDE ONE GUARD, not one per file. This is one operation, and a
		// delete that escaped the fence would reach Grafana — see the class docblock.
		$counts = ['removed' => 0, 'unmapped' => 0, 'failed' => 0];
		$this->guard->run(function () use ($folder, $id, &$counts): void {
			$counts = $this->tearDownFiles($folder, $id);
		});

		$this->logger->info('grafana_sync: tore down a mapping\'s files', [
			'app' => Application::APP_ID,
			'mapping' => $id,
			'grafanaFolder' => $mapping->grafanaFolderUid,
			'ncFolder' => $mapping->ncFolder,
			'removed' => $counts['removed'],
			'unmapped' => $counts['unmapped'],
			'failed' => $counts['failed'],
		]);
	}

	/**
	 * Every file connected to $mappingId, at any depth, answered by its own mode.
	 *
	 * A file connected to a DIFFERENT mapping is left alone: mappings nest, so a mapped
	 * subfolder inside this tree is somebody else's and stays whole.
	 *
	 * @return array{removed:int, unmapped:int, failed:int}
	 */
	private function tearDownFiles(Folder $folder, string $mappingId): array {
		$counts = ['removed' => 0, 'unmapped' => 0, 'failed' => 0];
		// COLLECTED BEFORE ANYTHING IS TOUCHED, so the walk never reads a listing it is
		// concurrently changing — half the link files in a folder used to survive it.
		foreach ($this->connectedBelow($folder, $mappingId) as $node) {
			$managed = $this->metadata->read($node->getId());
			if ($managed === null) {
				continue;
			}
			$ok = $managed->isLink() ? $this->removeLink($node) : $this->unmap($node);
			if (!$ok) {
				$counts['failed']++;
				continue;
			}
			$counts[$managed->isLink() ? 'removed' : 'unmapped']++;
		}
		return $counts;
	}

	/**
	 * Every `.grafana` at or below $folder that THIS mapping connected.
	 *
	 * The stamp is what makes it ours. A `.grafana` somebody dropped into the mapped
	 * folder themselves carries no mapping id, and it is not this app's to remove or to
	 * re-label — the whole point of a mapped folder is that it stays usable as a folder.
	 *
	 * @return list<File>
	 */
	private function connectedBelow(Folder $folder, string $mappingId): array {
		$found = [];
		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync tear-down: could not list a folder; skipping it', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);
			return [];
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->connectedBelow($child, $mappingId) as $deeper) {
					$found[] = $deeper;
				}
				continue;
			}
			if (!$child instanceof File || !FilenameCodec::isDashboardFile($child)) {
				continue;
			}
			try {
				$managed = $this->metadata->read($child->getId());
			} catch (\Throwable) {
				continue; // a file this app cannot identify is never one it may act on
			}
			if ($managed?->isManaged() && $managed->mappingId === $mappingId) {
				$found[] = $child;
			}
		}
		return $found;
	}

	/**
	 * A pointer whose mapping is gone. Remove it, WITH NO TRASH ENTRY.
	 *
	 * A link holds no dashboard of its own, so a trash entry would offer a restore of a
	 * file that reconnects to nothing — not a recovery, just a way to be confused later.
	 * The dashboard it pointed at is untouched in Grafana, which is where it always lived.
	 */
	private function removeLink(File $node): bool {
		$path = $node->getPath();
		try {
			$this->trash->withoutTrash(static function () use ($node): void {
				$node->delete();
			});
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync tear-down: could not remove a link whose mapping was removed', [
				'app' => Application::APP_ID,
				'file' => $path,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * A sync file whose mapping is gone. Keep the file, drop the connection.
	 *
	 * THE `grafana_uid` STAYS, and not as a claim on the dashboard it names — the dashboard
	 * is still in Grafana, untouched. It stays because a file carrying a uid is
	 * distinguishable from one that was never a mirror, which is what re-adoption reads
	 * when the folder is mapped again. An unmap is not a wipe; the uid simply stopped
	 * meaning "reattach me".
	 */
	private function unmap(File $node): bool {
		try {
			$this->metadata->write($node->getId(), [
				DashboardMetadata::KEY_MAPPING => '',
				DashboardMetadata::KEY_MODE => DashboardMetadata::MODE_UNMAPPED,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync tear-down: could not unmap a file whose mapping was removed', [
				'app' => Application::APP_ID,
				'file' => $node->getPath(),
				'exception' => $e,
			]);
			return false;
		}
	}
}
