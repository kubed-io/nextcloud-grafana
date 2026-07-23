<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use JsonSerializable;

/**
 * Folder-mapping value object.
 *
 * Each Mapping binds a **Grafana folder** (by its stable `uid`) to a **Nextcloud
 * folder**, plus the mode files under it get and which serialization cut the app
 * writes them as. This is the plainest expression of the master's idea: where the
 * n8n app had to fake structure by binding an n8n *tag* to a folder (n8n has no
 * folder API — saga Ch1 "Difference #1"), Grafana has real, nestable folders, so a
 * mapping is a folder-to-folder mirror with nothing to invent.
 *
 * We key on the folder **uid**, not its title, so a mapping survives a folder
 * rename in Grafana (the uid is immutable; the title is display-only and refreshed
 * on sync). `grafanaFolderTitle` is carried purely so the admin panel / occ output
 * can show a human name without a round-trip to Grafana.
 *
 * Mode model (mirrors the master, saga Ch2 §14): a mapping's mode is exactly
 * **`sync`** (full dashboard body lives in Nextcloud, edits push back) or **`link`**
 * (a read-only pointer that opens the dashboard in Grafana — the natural fit for
 * operator/GitOps-provisioned dashboards owned elsewhere).
 *
 * Format model (saga Ch1 "Difference #2"): Grafana serves a dashboard two ways —
 * the classic dashboard JSON (`v1beta1`/`v1`) and the newer k8s-style App Platform
 * schema that reads cleanly as YAML (`v2`). The cut is a property of the *mapping*,
 * not the app, so one folder can be classic JSON and another the YAML cut. `format`
 * is `json` (the safe default — every existing dashboard already is this) or `yaml`
 * (opt-in). The sync chapter reads it to pick the serializer + file extension
 * (`.grafana.json` vs `.grafana.yaml`); it is inert config until then.
 *
 * Storage model (mirrors the n8n master's Mapping, so the two reduce cleanly into a
 * shared base later): `ncGroups` are the Nextcloud groups the mapped folder is
 * shared with, and `useTeamFolder` picks the backend — an ownerless Team Folder
 * (groupfolders) when true, an admin-owned shared folder when false. Both persist
 * with the mapping today; the sync engine that *provisions* the folder from them
 * lands in a later chapter (Course 2), so they are stored-but-not-yet-acted-on —
 * config the engine will read, not decoration.
 *
 * Invariants:
 *  - `grafanaFolderUid` MUST be non-empty.
 *  - `ncFolder` MUST be non-empty (after normalising away surrounding slashes).
 *  - `mode` MUST be `sync` or `link`.
 *  - `format` MUST be `json` or `yaml`.
 *  - `ncGroups` MAY be empty (a folder no group can see); the sync reconciler will
 *    warn + skip those, same as the master.
 */
final class Mapping implements JsonSerializable {
	public const MODE_SYNC = 'sync';
	public const MODE_LINK = 'link';

	public const FORMAT_JSON = 'json';
	public const FORMAT_YAML = 'yaml';

	/**
	 * @param list<string> $ncGroups
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $grafanaFolderUid,
		public readonly string $grafanaFolderTitle,
		public readonly string $ncFolder,
		public readonly string $mode,
		public readonly string $format,
		public readonly array $ncGroups,
		public readonly bool $useTeamFolder,
	) {
	}

	/**
	 * Validate + normalise a raw input array (from REST params, occ JSON, or the
	 * stored config) into a Mapping. Throws InvalidArgumentException on any
	 * invariant violation so the controller returns a clean 400 and the occ command
	 * exits non-zero rather than persisting nonsense.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self {
		$id = isset($data['id']) && is_string($data['id']) && $data['id'] !== ''
			? $data['id']
			: self::newId();

		$uid = trim((string)($data['grafana_folder_uid'] ?? ''));
		$title = trim((string)($data['grafana_folder_title'] ?? ''));
		$ncFolder = self::normaliseFolder((string)($data['nc_folder'] ?? ''));
		$mode = (string)($data['mode'] ?? '');

		// Format defaults to the classic JSON cut when absent — everything already
		// is this, so an omitted field is never a surprise.
		$format = (string)($data['format'] ?? self::FORMAT_JSON);
		if ($format === '') {
			$format = self::FORMAT_JSON;
		}

		$ncGroups = self::normaliseGroups($data['nc_groups'] ?? []);

		// Storage backend. Default true: groupfolders is the preferred path (matches
		// the n8n master), and an omitted flag means "use a Team Folder".
		$useTeamFolder = !array_key_exists('use_team_folder', $data)
			|| filter_var($data['use_team_folder'], FILTER_VALIDATE_BOOLEAN);

		if ($uid === '') {
			throw new \InvalidArgumentException('grafana_folder_uid is required');
		}
		if ($ncFolder === '') {
			throw new \InvalidArgumentException('nc_folder is required');
		}
		if (!in_array($mode, [self::MODE_SYNC, self::MODE_LINK], true)) {
			throw new \InvalidArgumentException('mode must be "sync" or "link"');
		}
		if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_YAML], true)) {
			throw new \InvalidArgumentException('format must be "json" or "yaml"');
		}

		return new self($id, $uid, $title, $ncFolder, $mode, $format, $ncGroups, $useTeamFolder);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'grafana_folder_uid' => $this->grafanaFolderUid,
			'grafana_folder_title' => $this->grafanaFolderTitle,
			'nc_folder' => $this->ncFolder,
			'mode' => $this->mode,
			'format' => $this->format,
			'nc_groups' => $this->ncGroups,
			'use_team_folder' => $this->useTeamFolder,
		];
	}

	#[\Override]
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/** Tiny opaque id, unique within the mappings list. */
	public static function newId(): string {
		return bin2hex(random_bytes(8));
	}

	/**
	 * Nextcloud target folder — usually a plain name (`observe`), but a mapping may
	 * also sit on a **nested** folder (`dashboards/observe`) since mappings are
	 * per-folder metadata and the resolver picks the nearest enclosing one. Strip
	 * surrounding slashes, collapse any duplicate separators, and drop whitespace so
	 * the stored value is a clean relative path the resolver can prefix-match.
	 */
	private static function normaliseFolder(string $value): string {
		$v = trim($value);
		$v = preg_replace('#/+#', '/', $v) ?? $v;
		return trim($v, '/');
	}

	/**
	 * Group ids: a list of non-empty trimmed strings, de-duplicated, re-indexed.
	 * Tolerates a comma-separated string from a form field. Identical to the n8n
	 * master's normaliser so the two mapping models reduce cleanly into a shared
	 * base later.
	 *
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function normaliseGroups(mixed $value): array {
		if (is_string($value)) {
			$value = $value === '' ? [] : explode(',', $value);
		}
		if (!is_array($value)) {
			return [];
		}
		$out = [];
		foreach ($value as $g) {
			$g = trim((string)$g);
			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}
		return $out;
	}
}
