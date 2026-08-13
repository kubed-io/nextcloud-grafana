<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Storage + CRUD for the folder-mapping list.
 *
 * Backed by a single AppConfig key (`mappings`) holding a JSON array — keeps all
 * mappings in one round-trip read and makes occ/helm parity trivial
 * (`occ config:app:set grafana_sync mappings '[...json...]'`).
 *
 * Each mapping binds a Grafana folder uid to a Nextcloud folder; see {@see Mapping}.
 * A malformed stored row is skipped rather than breaking the admin page. The parsed
 * list is memoised for the request (the service is a per-request singleton), so a
 * panel render + the resolver don't re-decode the config repeatedly.
 */
final class MappingService {
	/**
	 * Request-scoped cache of the parsed list.
	 *
	 * @var list<Mapping>|null
	 */
	private ?array $cache = null;

	public function __construct(
		private readonly IAppConfig $config,
		private StorageService $storage,
	) {
	}

	/** @return list<Mapping> */
	public function list(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, 'mappings', '[]'), true);
		if (!is_array($decoded)) {
			return $this->cache = [];
		}
		$result = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			try {
				$result[] = Mapping::fromArray($entry);
			} catch (\InvalidArgumentException) {
				// A single bad row must not blank the whole panel.
				continue;
			}
		}
		return $this->cache = $result;
	}

	/** Look up a single mapping by its stable id (used to resolve a file's `grafana_mapping`). */
	public function getById(string $id): ?Mapping {
		foreach ($this->list() as $m) {
			if ($m->id === $id) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Store a new mapping, and provision its folder.
	 *
	 * `$groups` travels alongside the mapping rather than being part of it: they
	 * are applied to the folder and read back from it, never stored.
	 *
	 * THE FOLDER IS MADE BEFORE THE MAPPING IS PERSISTED, so a mapping that cannot
	 * be provisioned is not saved at all. A Team Folder mapping on an instance
	 * without groupfolders used to save happily and then fail on every sync, which
	 * reads as "the sync is broken" rather than "that backend is not installed".
	 *
	 * @param array<array-key, mixed>|string $groups
	 */
	public function add(Mapping $mapping, array|string $groups = []): Mapping {
		$all = $this->list();
		$this->assertFolderUnique($all, $mapping->grafanaFolderUid, null);
		$this->assertIdUnique($all, $mapping->id);
		// Provisioning is where the Nextcloud half of the pair becomes knowable, so
		// bank the folder id here rather than leaving the first resolve to discover it.
		// A backend that cannot say (id 0) leaves the mapping to self-heal instead —
		// never overwrite a real id with "unknown".
		$folder = $this->storage->ensureFolder($mapping, $groups);
		$folderId = $folder->getId();
		if ($folderId > 0) {
			$mapping = $mapping->withNcFolderId($folderId);
		}
		$all[] = $mapping;
		$this->persist($all);
		return $mapping;
	}

	/**
	 * Re-share a mapping's folder with the given groups — THE ONLY EDIT THERE IS.
	 *
	 * ## IMMUTABILITY IS NOW THE API'S SHAPE, NOT A LIST OF GUARDS
	 *
	 * This replaced an `update(string $id, Mapping $mapping)` that took a whole
	 * mapping and then rejected changes to four fields one by one. Guarding is
	 * weaker than not offering: it left `mode` and `format` editable by omission,
	 * and it meant the admin card PUT every field on every save whether or not any
	 * of them could change. Now no caller can EXPRESS a change to anything but the
	 * groups, so there is no path to check.
	 *
	 * Each field the old guards protected is still fixed, for the reason it always
	 * was — every one would force a live migration:
	 *
	 *   - the **Grafana folder** and the **Nextcloud folder** — re-pointing either
	 *     renames or moves a whole tree of already-synced files and re-stamps their
	 *     metadata (doubly fiddly when both change at once);
	 *   - the **Team Folder** flag — switching backend migrates the provisioned
	 *     folder and all of its shares;
	 *   - **subfolder-sync** — flipping it restructures the far side (on→off
	 *     flattens mirrored Grafana subfolders and re-parents their dashboards;
	 *     off→on lazily grows them).
	 *
	 * `mode` and `format` join them: both decide how every existing file under the
	 * mapping was written, so changing one silently invalidates what is on disk.
	 *
	 * To change any of it: delete the mapping and add a new one. That makes the
	 * migration cost visible instead of hiding it behind a dropdown.
	 *
	 * IT WRITES TO THE FOLDER AND PERSISTS NOTHING. The return value is what the
	 * folder reports afterwards, which is not always what was submitted — a group
	 * that does not exist cannot be shared with.
	 *
	 * @param array<array-key, mixed>|string $ncGroups
	 * @return list<string>
	 */
	public function updateGroups(string $id, array|string $ncGroups): array {
		$mapping = $this->getById($id);
		if ($mapping === null) {
			throw new \OutOfBoundsException('mapping not found');
		}

		$this->storage->ensureFolder($mapping, $ncGroups);

		return $this->storage->groupsOf($mapping);
	}

	/**
	 * The groups a mapping's folder is currently shared with.
	 *
	 * @return list<string>
	 */
	public function groupsOf(Mapping $mapping): array {
		return $this->storage->groupsOf($mapping);
	}

	/**
	 * The stored shape PLUS the folder's current groups — what the admin page and
	 * `list-mappings` render, as opposed to what is written to appconfig.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(Mapping $mapping): array {
		return $mapping->toArray() + ['nc_groups' => $this->groupsOf($mapping)];
	}

	public function delete(string $id): void {
		$all = $this->list();
		$filtered = array_values(array_filter($all, fn (Mapping $m) => $m->id !== $id));
		if (count($filtered) === count($all)) {
			throw new \OutOfBoundsException('mapping not found');
		}
		$this->persist($filtered);
	}

	/**
	 * Given a Nextcloud node path, return the mapping whose folder **encloses** the
	 * node, or null. NC node paths look like `/<uid>/files/<folder…>/<file>`; we
	 * compare the part after `files/` against each mapping's `ncFolder`.
	 *
	 * Mappings are metadata on a folder, so they nest: a folder can be mapped
	 * **inside** an already-mapped folder. When more than one mapping encloses the
	 * node the **nearest enclosing** one wins — i.e. the deepest folder path. Because
	 * every enclosing folder is a path-prefix of the node, the longest matching
	 * `ncFolder` is unambiguously the deepest, so we keep the longest.
	 *
	 * **The folder each mapping names is looked up by ID, not by the stored name.**
	 * That is the whole point: a mapped folder that has been renamed still resolves,
	 * because the id finds it wherever it now is and whatever it is now called. The
	 * stored `ncFolder` is only a label. Before this, the resolver matched the stored
	 * string, so renaming a mapped folder orphaned every file beneath it in silence.
	 *
	 * Kept in the service (ahead of the sync listeners that consume it) because the
	 * nearest-enclosing rule is model logic and cheaply unit-tested — the master's
	 * longest-prefix resolver, finally used on a real folder tree instead of an
	 * emulated one.
	 */
	public function resolveForPath(string $ncPath): ?Mapping {
		if (!preg_match('#/files/(.+)$#', $ncPath, $m)) {
			return null;
		}
		$relative = trim($m[1], '/');
		$best = null;
		$bestLen = -1;
		foreach ($this->list() as $mapping) {
			$folder = $this->currentFolderOf($mapping);
			if ($folder === '') {
				continue;
			}
			// The node belongs to $folder iff it IS that folder or lives anywhere
			// beneath it. The trailing slash pins the match to a segment boundary so
			// "observe" never swallows a sibling like "observability".
			$encloses = $relative === $folder
				|| str_starts_with($relative, $folder . '/');
			if ($encloses && strlen($folder) > $bestLen) {
				$bestLen = strlen($folder);
				$best = $mapping;
			}
		}
		return $best;
	}

	/**
	 * Where a mapping's folder is RIGHT NOW, relative to the user's files root, or
	 * `''` when it cannot be found.
	 *
	 * The id is the authority, so the answer follows the folder through a rename or a
	 * move without anything having to notice either happened.
	 *
	 * **Self-heal.** A mapping saved before its folder was provisioned — or before
	 * this field existed at all — carries id 0. Rather than refuse it, we resolve the
	 * stored name once, bank the id, and never look it up by name again. That is the
	 * only remaining path lookup in the app, and it happens at most once per mapping.
	 *
	 * A mapping whose folder has genuinely been deleted keeps its id and resolves to
	 * `''`, so it simply matches nothing. It is not repaired by falling back to the
	 * name — a new folder that happens to reuse the old name is a different folder,
	 * and quietly adopting it is how a mapping ends up pointing somewhere nobody
	 * chose.
	 */
	private function currentFolderOf(Mapping $mapping): string {
		if ($mapping->ncFolderId > 0) {
			$path = $this->storage->pathOfFolderId($mapping->ncFolderId);
			if ($path === null) {
				return '';
			}
			// The stored name is a LABEL, so when the folder has been renamed or moved
			// the label is simply out of date — catch it up. Without this the resolver
			// would be right while the admin panel and `occ` still showed the old name,
			// which is the same defect one layer up.
			if ($path !== trim($mapping->ncFolder, '/')) {
				$this->rewrite($mapping->id, static fn (Mapping $m): Mapping => $m->withNcFolder($path));
			}
			return $path;
		}

		$name = trim($mapping->ncFolder, '/');
		if ($name === '') {
			return '';
		}
		$found = $this->storage->findFolder($mapping);
		if ($found === null) {
			// Not provisioned yet. Fall back to the name for this resolve only, so a
			// mapping still works in the window before its folder exists.
			return $name;
		}
		$id = $found->getId();
		if ($id > 0) {
			$this->rewrite($mapping->id, static fn (Mapping $m): Mapping => $m->withNcFolderId($id));
		}
		return $name;
	}

	/**
	 * Replace one mapping in the stored list, in memory and on disk.
	 *
	 * Used only for the two facts the app learns about a mapping rather than being
	 * told: its folder's id, and that folder's current name.
	 *
	 * @param callable(Mapping): Mapping $change
	 */
	private function rewrite(string $id, callable $change): void {
		$all = $this->list();
		foreach ($all as $i => $existing) {
			if ($existing->id === $id) {
				$all[$i] = $change($existing);
				$this->persist($all);
				return;
			}
		}
	}

	/**
	 * Enforce one-mapping-per-Grafana-folder: reject a mapping whose folder uid is
	 * already used by a different mapping. This removes the "one dashboard lands in
	 * two folders" edge case by construction — a folder's dashboards have exactly
	 * one Nextcloud home.
	 *
	 * @param list<Mapping> $all
	 */
	private function assertFolderUnique(array $all, string $uid, ?string $exceptId): void {
		foreach ($all as $m) {
			if ($m->id !== $exceptId && $m->grafanaFolderUid === $uid) {
				throw new \InvalidArgumentException(
					'Another mapping already uses the Grafana folder "' . $uid . '". Each folder may map to only one location.',
				);
			}
		}
	}

	/**
	 * The id is the stable primary key update/delete resolve on, so it must be
	 * unique within the list. New mappings get a server-minted id (the create
	 * endpoint strips any client-supplied one), so this only fires on a genuinely
	 * corrupt store — but a duplicate id would make update touch one row and delete
	 * remove several, so reject it loudly rather than silently misbehave.
	 *
	 * @param list<Mapping> $all
	 */
	private function assertIdUnique(array $all, string $id): void {
		foreach ($all as $m) {
			if ($m->id === $id) {
				throw new \InvalidArgumentException('A mapping with id "' . $id . '" already exists.');
			}
		}
	}

	/** @param list<Mapping> $mappings */
	private function persist(array $mappings): void {
		$json = json_encode(
			array_map(fn (Mapping $m) => $m->toArray(), $mappings),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
		);
		$this->config->setValueString(Application::APP_ID, 'mappings', $json);
		// Keep the request cache in step with what we just stored ($mappings is a list).
		$this->cache = $mappings;
	}
}
