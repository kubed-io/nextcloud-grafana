<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Brings the Nextcloud folder tree into agreement with Grafana's, on a pull.
 *
 * The counterpart to {@see FolderMirror}, which runs the other way: that one creates
 * Grafana folders because a dashboard landed in a Nextcloud folder, this one creates
 * Nextcloud folders because Grafana has them. Together they are the mirror.
 *
 * ## THE UID DOES ALL THE WORK
 *
 * Every mirrored Nextcloud folder banks its Grafana folder uid
 * ({@see FolderMetadata}), so the reconcile compares by ID and never by name. That
 * single choice answers four questions that look different and are not:
 *
 * | what changed in Grafana | what the uid shows | what happens here |
 * |---|---|---|
 * | nothing | same uid, same title, same parent | nothing |
 * | a rename | **same uid, different title** | the Nextcloud folder is renamed |
 * | a move | **same uid, different parent** | the Nextcloud folder is moved |
 * | a move AND a rename | same uid, both differ | one move, to a new place under a new name |
 * | a new folder | a uid we have never seen | a Nextcloud folder is created and stamped |
 *
 * The last-but-one row is the one worth stating out loud. Grafana can re-parent and
 * retitle in two calls with no sync in between, so this app WILL observe both at
 * once — and it needs no special case, because the uid already identifies the folder.
 * A name-keyed mirror would read that as one folder vanishing and another appearing,
 * delete every dashboard underneath and re-create them with new uids.
 *
 * ## WHAT IT DOES NOT DO
 *
 * It does not delete. A Grafana folder that has gone away leaves its Nextcloud
 * mirror standing, because deleting it is a decision with a rule of its own — a
 * folder holding a user's non-dashboard files must survive and merely stop being a
 * mirror (`folders/delete.feature`). That belongs to the prune, not here.
 *
 * It does not touch folders it has never stamped. A folder the user made for their
 * own reasons carries no uid, so it is invisible to this reconcile — which is what
 * keeps a mapped folder usable for ordinary things.
 */
final class FolderTreeMirror {
	public function __construct(
		private GrafanaClient $grafana,
		private FolderMetadata $folders,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile the tree under `$root` and return where each Grafana folder now lives.
	 *
	 * The map it returns is the point: the pull uses it to put every dashboard in the
	 * Nextcloud folder mirroring the Grafana folder that holds it, instead of
	 * flattening them all into the mapping's root.
	 *
	 * @return array<string, Folder> grafana folder uid → the Nextcloud folder mirroring it
	 */
	public function sync(Folder $root, Mapping $mapping): array {
		$rootUid = $mapping->grafanaFolderUid === '/' ? '' : $mapping->grafanaFolderUid;

		$wanted = $this->descendantsOf($rootUid);
		if ($wanted === []) {
			return [];
		}

		$byUid = $this->indexMirroredFolders($root);
		$placed = [];

		// Parents before children: a child cannot be created until the folder it goes
		// in exists. descendantsOf() already returns them in that order.
		foreach ($wanted as $uid => $folder) {
			$parent = $folder['parentUid'] === $rootUid || $folder['parentUid'] === ''
				? $root
				: ($placed[$folder['parentUid']] ?? null);
			if ($parent === null) {
				// The parent could not be placed — a folder Grafana reports whose parent
				// it does not. Skipping is right: creating it at the root would invent a
				// structure neither side has.
				$this->logger->warning('grafana_sync: skipped a Grafana folder whose parent could not be placed', [
					'app' => 'grafana_sync',
					'uid' => $uid,
					'parentUid' => $folder['parentUid'],
				]);
				continue;
			}

			// ONE BAD FOLDER MUST NOT TAKE THE PULL WITH IT. newFolder() throws on a
			// name collision with an existing file, on a permission problem, on a name
			// the storage refuses — all of them local to one folder. Letting that
			// escape would abort the reconcile AND the pull around it, so a single
			// unmakeable folder would stop every dashboard in the mapping from syncing.
			// Skip it, say so, and carry on; its children are skipped in turn because
			// they will find no parent placed.
			try {
				$placed[$uid] = isset($byUid[$uid])
					? $this->reconcile($byUid[$uid], $parent, $folder['title'], $uid)
					: $this->create($parent, $folder['title'], $uid);
			} catch (\Throwable $e) {
				$this->logger->warning('grafana_sync: could not mirror a Grafana folder; skipping it', [
					'app' => 'grafana_sync',
					'uid' => $uid,
					'title' => $folder['title'],
					'exception' => $e,
				]);
			}
		}

		return $placed;
	}

	/**
	 * Grafana's folders beneath `$rootUid`, outermost first.
	 *
	 * `/api/folders` returns the whole flat list with each folder's `parentUid`, so
	 * the tree is assembled here rather than walked over the wire — one request
	 * instead of one per level.
	 *
	 * @return array<string, array{title:string, parentUid:string}>
	 */
	private function descendantsOf(string $rootUid): array {
		// Index by PARENT once. Walking the flat list again for every level was
		// quadratic — with the tree three deep it read every folder three times, and
		// a whole-instance mapping is exactly where that bites.
		$byParent = [];
		foreach ($this->grafana->listFolders() as $row) {
			$byParent[$row['parentUid']][] = $row;
		}

		// Breadth-first from the root, so the result is already in
		// parents-before-children order and a child always has somewhere to go.
		$out = [];
		$frontier = [$rootUid];
		while ($frontier !== []) {
			$next = [];
			foreach ($frontier as $parentUid) {
				foreach ($byParent[$parentUid] ?? [] as $row) {
					if (isset($out[$row['uid']])) {
						continue; // a cycle, or a folder listed twice; either way, once is enough
					}
					$out[$row['uid']] = ['title' => $row['title'], 'parentUid' => $row['parentUid']];
					$next[] = $row['uid'];
				}
			}
			$frontier = $next;
		}
		return $out;
	}

	/**
	 * Every folder under `$root` that this app has stamped, keyed by Grafana uid.
	 *
	 * @return array<string, Folder>
	 */
	private function indexMirroredFolders(Folder $root): array {
		$found = [];
		$queue = [$root];
		// An index-based cursor rather than array_shift(), which reindexes the whole
		// queue on every pop and turns a deep tree quadratic.
		for ($i = 0; $i < count($queue); $i++) {
			$current = $queue[$i];
			foreach ($current->getDirectoryListing() as $node) {
				if (!$node instanceof Folder) {
					continue;
				}
				$uid = $this->folders->uidOf($node->getId());
				if ($uid !== '') {
					$found[$uid] = $node;
				}
				$queue[] = $node;
			}
		}
		return $found;
	}

	/**
	 * A folder we already mirror: move it if its parent changed, rename it if its
	 * title did, and do both in one step when Grafana did both.
	 *
	 * One `move()` covers all three, because in Nextcloud a rename IS a move to a
	 * path with a new last segment. Splitting it into a move then a rename would put
	 * the folder somewhere it never was, for as long as the second call took.
	 */
	private function reconcile(Folder $folder, Folder $parent, string $title, string $uid): Folder {
		$wantedPath = rtrim($parent->getPath(), '/') . '/' . $title;
		if (rtrim($folder->getPath(), '/') === $wantedPath) {
			return $folder;
		}

		try {
			$moved = $folder->move($wantedPath);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not follow a Grafana folder change', [
				'app' => 'grafana_sync',
				'uid' => $uid,
				'from' => $folder->getPath(),
				'to' => $wantedPath,
				'exception' => $e,
			]);
			return $folder;
		}

		$this->logger->info('grafana_sync: followed a Grafana folder rename or move', [
			'app' => 'grafana_sync',
			'uid' => $uid,
			'to' => $wantedPath,
		]);
		return $moved instanceof Folder ? $moved : $folder;
	}

	/** A Grafana folder with no mirror yet: make one and stamp it with the uid. */
	private function create(Folder $parent, string $title, string $uid): Folder {
		$folder = $parent->newFolder($title);
		$this->folders->stamp($folder->getId(), $uid);
		$this->logger->info('grafana_sync: mirrored a Grafana folder into Nextcloud', [
			'app' => 'grafana_sync',
			'uid' => $uid,
			'title' => $title,
		]);
		return $folder;
	}
}
