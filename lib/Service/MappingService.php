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

	public function add(Mapping $mapping): Mapping {
		$all = $this->list();
		$this->assertFolderUnique($all, $mapping->grafanaFolderUid, null);
		$this->assertIdUnique($all, $mapping->id);
		$all[] = $mapping;
		$this->persist($all);
		return $mapping;
	}

	public function update(string $id, Mapping $mapping): Mapping {
		$all = $this->list();
		$this->assertFolderUnique($all, $mapping->grafanaFolderUid, $id);
		$updated = null;
		foreach ($all as $i => $existing) {
			if ($existing->id === $id) {
				// Preserve the original id even if the caller sent a different one.
				$updated = new Mapping(
					$id,
					$mapping->grafanaFolderUid,
					$mapping->grafanaFolderTitle,
					$mapping->ncFolder,
					$mapping->mode,
					$mapping->format,
					$mapping->ncGroups,
					$mapping->useTeamFolder,
				);
				$all[$i] = $updated;
				break;
			}
		}
		if ($updated === null) {
			throw new \OutOfBoundsException('mapping not found');
		}
		$this->persist($all);
		return $updated;
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
	 * Kept in the service now (ahead of the sync listeners that consume it) because
	 * it is pure model logic, cheaply unit-tested, and the shape the sync chapter
	 * builds on — the master's longest-prefix resolver, finally used on a real
	 * folder tree instead of an emulated one.
	 */
	public function resolveForPath(string $ncPath): ?Mapping {
		if (!preg_match('#/files/(.+)$#', $ncPath, $m)) {
			return null;
		}
		$relative = trim($m[1], '/');
		$best = null;
		$bestLen = -1;
		foreach ($this->list() as $mapping) {
			$folder = trim($mapping->ncFolder, '/');
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
