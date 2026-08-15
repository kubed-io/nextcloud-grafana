<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Tears down a folder mapping safely (`remove-mapping.feature`). Removing a mapping is *not*
 * the Purge button (that keeps the mapping and never touches Grafana) — it dissolves the
 * connection, and the Nextcloud recycle bin is what makes that safe:
 *
 *  - every file **actively connected** to the mapping (a managed file whose `grafana_mapping`
 *    is this one) is **moved to the Nextcloud trash**. Because a trash-move rides the delete
 *    contract ({@see DeleteToGrafanaListener}), the Grafana side follows for free — bin OFF
 *    deletes the connected dashboard + strips the file; bin ON parks it in the bin folder,
 *    id kept. So restoring the trash later reconnects (same uid with bin ON, a re-create with
 *    bin OFF).
 *  - files that were **never connected** (an unmapped / untracked standalone `.grafana`
 *    that only ever lived in Nextcloud) are **left strictly alone** — removing a mapping they
 *    weren't part of must never move or bin them. No data loss.
 *  - finally the mapping's config binding is dropped.
 *
 * A per-file failure is logged and the walk continues — one unreachable dashboard must not
 * strand the rest of the tear-down. The mapping binding is dropped last, so a re-run resumes.
 */
final class MappingTeardownService {
	public function __construct(
		private MappingService $mappings,
		private StorageService $storage,
		private DashboardMetadata $metadata,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Trash the mapping's connected files, then drop the binding.
	 *
	 * @throws \OutOfBoundsException when no mapping has that id (preserves the callers' 404)
	 */
	public function remove(string $id): void {
		$mapping = $this->mappings->getById($id);
		if ($mapping === null) {
			throw new \OutOfBoundsException('No mapping with id "' . $id . '".');
		}

		$folder = $this->storage->findFolder($mapping);
		$failed = $folder !== null ? $this->trashConnectedFiles($folder, $id) : 0;

		// If any connected file could NOT be trashed (e.g. the delete listener aborted because
		// Grafana was unreachable), keep the binding so the admin can retry the whole tear-down —
		// dropping it now would strand a still-managed file with a dead mapping id. We attempted
		// every file first (one bad dashboard never blocks the rest), then decide on the binding.
		if ($failed > 0) {
			throw new \RuntimeException(
				$failed . ' connected file(s) could not be removed (Grafana may be unreachable); '
				. 'the mapping was kept so you can retry.',
			);
		}

		$this->mappings->delete($id);
	}

	/**
	 * Recursively move every file connected to $mappingId (managed, `grafana_mapping` == id)
	 * into the Nextcloud trash. Trashing fires {@see \OCP\Files\Events\Node\BeforeNodeDeletedEvent},
	 * so the delete listener does the Grafana side per the recycle-bin setting. Unmanaged /
	 * unconnected files are skipped — never touched. A per-file failure is logged and the walk
	 * continues (one unreachable dashboard must not strand the rest); the count is returned so the
	 * caller can decide whether the tear-down was complete enough to drop the binding.
	 *
	 * @return int the number of connected files that failed to trash
	 */
	private function trashConnectedFiles(Folder $folder, string $mappingId): int {
		$failed = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$failed += $this->trashConnectedFiles($node, $mappingId);
				continue;
			}
			if (!FilenameCodec::isDashboardFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged() || $managed->mappingId !== $mappingId) {
				continue; // unmanaged / connected to a different mapping → leave it alone
			}
			try {
				$node->delete();
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning('grafana_sync tear-down: could not trash a connected file; continuing', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'path' => $node->getPath(),
					'exception' => $e,
				]);
			}
		}
		return $failed;
	}
}
