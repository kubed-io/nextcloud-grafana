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
 *  - files that were **never connected** (an unmapped / untracked standalone `.grafana.json`
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
		if ($folder !== null) {
			$this->trashConnectedFiles($folder, $id);
		}

		// Drop the binding last: if a trash step above threw and was swallowed, the mapping is
		// still there to retry against; only once the files are handled do we forget it.
		$this->mappings->delete($id);
	}

	/**
	 * Recursively move every file connected to $mappingId (managed, `grafana_mapping` == id)
	 * into the Nextcloud trash. Trashing fires {@see \OCP\Files\Events\Node\BeforeNodeDeletedEvent},
	 * so the delete listener does the Grafana side per the recycle-bin setting. Unmanaged /
	 * unconnected files are skipped — never touched.
	 */
	private function trashConnectedFiles(Folder $folder, string $mappingId): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->trashConnectedFiles($node, $mappingId);
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
				$this->logger->warning('grafana_sync tear-down: could not trash a connected file; continuing', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'path' => $node->getPath(),
					'exception' => $e,
				]);
			}
		}
	}
}
