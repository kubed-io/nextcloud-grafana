<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Makes the Grafana folder tree match the Nextcloud one, on demand.
 *
 * ## THE RULE: A FOLDER IS IN GRAFANA WHEN A DASHBOARD IS IN IT
 *
 * A folder created inside a mapping is an ORDINARY folder — nothing is sent, and a
 * mapped folder stays usable for notes, exports and anything else. It becomes a
 * Grafana folder at the moment a dashboard lands somewhere beneath it, and then its
 * parents come with it: a dashboard three folders deep needs all three to exist.
 *
 * This replaced a per-mapping "sync subfolders" toggle, which could only ever say
 * *mirror this folder but nothing under it* — something already expressible by
 * mapping a leaf folder instead. Depth is chosen by WHICH folder you map.
 *
 * ## WHY THE UID IS ASKED FOR, NOT THE NAME
 *
 * Each mirrored Nextcloud folder banks its Grafana folder uid
 * ({@see FolderMetadata}). Resolution walks that, never the title, so a folder
 * renamed on either side keeps resolving to the same Grafana folder. Matching by
 * name would make a rename look like a delete plus a create and re-mint every
 * dashboard underneath.
 *
 * The one place a name IS used is the initial create — the new Grafana folder takes
 * the Nextcloud folder's name, exactly, case included. A subfolder has nowhere to
 * record a differing pair, so it may not have one; only a MAPPING can pair two
 * different names.
 *
 * ## WHAT IT DELIBERATELY DOES NOT DO
 *
 * It never DELETES a Grafana folder. Emptying is not deleting: a folder whose last
 * dashboard leaves stays on both sides, so the next dashboard created there lands in
 * the folder both sides already agree on instead of minting a second one beside it.
 * Deleting a folder is a gesture someone performs, and it lives in the delete path.
 */
final class FolderMirror {
	public function __construct(
		private GrafanaClient $grafana,
		private FolderMetadata $folders,
		private StorageService $storage,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The Grafana folder uid that should hold a dashboard living at `$file`, creating
	 * whatever is missing on the way down.
	 *
	 * Returns `null` for the Grafana root — reachable only through an explicit
	 * reserved-root (`/`) mapping, where a dashboard genuinely belongs to no folder.
	 * Every other answer is a real uid.
	 *
	 * A file sitting directly in the mapped folder resolves to the mapping's own
	 * Grafana folder without touching Grafana at all, which is the common case and
	 * costs nothing.
	 */
	public function folderUidFor(Node $file, Mapping $mapping): ?string {
		$root = $mapping->grafanaFolderUid === '/' ? null : $mapping->grafanaFolderUid;

		$chain = $this->chainBelowMapping($file, $mapping);
		if ($chain === []) {
			return $root;
		}

		$parentUid = $root ?? '';
		foreach ($chain as $folder) {
			$parentUid = $this->ensureFolder($folder, $parentUid);
		}
		return $parentUid;
	}

	/**
	 * The Nextcloud folders between the mapped folder and `$file`, outermost first.
	 *
	 * Walks UP from the file and reverses, because a node knows its parent and not
	 * its children. Stops at the mapped folder itself — anything above that belongs to
	 * no mapping and is none of this app's business.
	 *
	 * @return list<Folder>
	 */
	private function chainBelowMapping(Node $file, Mapping $mapping): array {
		$mappedPath = $this->storage->pathOfFolderId($mapping->ncFolderId);
		if ($mappedPath === null) {
			// The mapped folder is gone. The caller's own guards will refuse the write;
			// inventing a chain here would create Grafana folders for a mapping that no
			// longer has anywhere to put them.
			return [];
		}

		$chain = [];
		$node = $file;
		while (true) {
			$parent = $node->getParent();
			$relative = $this->storage->pathOfFolderId($parent->getId());
			if ($relative === null || $relative === $mappedPath) {
				break;
			}
			// Guard against walking clean out of the mapping if the paths ever stop
			// agreeing — a loop that cannot see its own root would climb to the storage
			// root and mirror the user's entire home into Grafana.
			if (!str_starts_with($relative . '/', $mappedPath . '/')) {
				break;
			}
			$chain[] = $parent;
			$node = $parent;
		}
		return array_reverse($chain);
	}

	/**
	 * The Grafana folder for one Nextcloud folder, creating it under `$parentUid` if
	 * it has none yet. Returns the uid.
	 *
	 * The banked uid is trusted without a round-trip: verifying every level on every
	 * dashboard write would cost a request per folder per push, and a stale uid
	 * surfaces as a failed write the caller already handles. A folder deleted in
	 * Grafana is the pull's problem to notice, not this path's.
	 */
	private function ensureFolder(Folder $folder, string $parentUid): string {
		$known = $this->folders->uidOf($folder->getId());
		if ($known !== '') {
			return $known;
		}

		$created = $this->grafana->createFolder($folder->getName(), $parentUid);
		$this->folders->stamp($folder->getId(), $created['uid']);
		$this->logger->info('Mirrored a Nextcloud folder into Grafana', [
			'app' => 'grafana_sync',
			'folder' => $folder->getName(),
			'uid' => $created['uid'],
			'parentUid' => $parentUid,
		]);
		return $created['uid'];
	}
}
