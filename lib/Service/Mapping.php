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
 * ## BOTH SIDES ARE HELD BY ID
 *
 * | side | authority | display / creation |
 * |---|---|---|
 * | Grafana | `grafanaFolderUid` | `grafanaFolderTitle` |
 * | Nextcloud | `ncFolderId` | `ncFolder` |
 *
 * We key on the Grafana folder **uid**, not its title, so a mapping survives a
 * folder rename in Grafana (the uid is immutable; the title is display-only and
 * refreshed on sync). `grafanaFolderTitle` is carried purely so the admin panel /
 * occ output can show a human name without a round-trip to Grafana.
 *
 * **The Nextcloud side works the same way, and did not used to.** `ncFolder` was
 * the authority — a path string the resolver prefix-matched — so renaming a mapped
 * folder left the mapping pointing at a name nothing had any more, and every file
 * beneath it silently resolved to no mapping at all. No error, no sync, no clue.
 * `ncFolderId` is the Nextcloud file id of the mapped folder, which survives both a
 * rename and a move, so the mapping is a pair of ids and a rename is a no-op
 * (`features/mapping/rename.feature`).
 *
 * `ncFolder` stays because something has to NAME the folder when it is first
 * created, and the panel has to show something — exactly the job
 * `grafanaFolderTitle` does on the other side. It is no longer what anything
 * resolves on.
 *
 * `ncFolderId` is **0 until the folder exists**: a mapping can be saved before its
 * folder is provisioned, and an old mapping predates the field. Both self-heal —
 * see {@see MappingService::resolveForPath()}.
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
 * shared base later): `useTeamFolder` picks the backend — an ownerless Team Folder
 * (groupfolders) when true, an admin-owned shared folder when false.
 *
 * ## WHAT IS NOT ON THIS OBJECT: GROUPS
 *
 * Which groups the mapped folder is shared with is a property OF THE FOLDER, and
 * Nextcloud already stores it — as groupfolders assignments, or as group shares.
 * Copying it here would create a second answer to the same question, and the two
 * disagree the moment an admin re-shares the folder from the Files app or `occ`,
 * which they are entitled to do.
 *
 * That is not a hypothetical tidy-up. Three apps in this family can map to the
 * SAME folder, and while each stored its own list every sync stamped that list
 * over the others' — so Grafana, n8n and Penpot fought for control of one folder
 * forever, and none of them was wrong. Sourcing the groups from the folder makes
 * the folder the single answer, so all three (and the Files UI, and `occ`) can
 * edit the same sharing without contending.
 *
 * Groups are therefore read on demand ({@see StorageService::groupsOf()}) and
 * written straight through ({@see MappingService::updateGroups()}).
 *
 * ## WHAT IS NOT ON THIS OBJECT: A SUBFOLDER TOGGLE
 *
 * There was a `syncSubfolders` flag here, stored and validated and read by nothing.
 * It is gone. A subfolder is in Grafana exactly when a dashboard lives beneath it
 * — that is the whole rule, it is per-folder, and it needs no per-mapping switch
 * (`features/folders/create.feature`). A flag would only be able to say "never
 * mirror subfolders", which is not a thing anyone asked for.
 *
 * Invariants:
 *  - `grafanaFolderUid` MUST be non-empty.
 *  - `ncFolder` MUST be non-empty (after normalising away surrounding slashes).
 *  - `mode` MUST be `sync` or `link`.
 *  - `format` MUST be `json` or `yaml`.
 *  - `ncFolderId` is 0 or a real Nextcloud file id; never negative.
 */
final class Mapping implements JsonSerializable {
	public const MODE_SYNC = 'sync';
	public const MODE_LINK = 'link';

	public const FORMAT_JSON = 'json';
	public const FORMAT_YAML = 'yaml';

	/**
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $grafanaFolderUid,
		public readonly string $grafanaFolderTitle,
		public readonly string $ncFolder,
		public readonly string $mode,
		public readonly string $format,
		public readonly bool $useTeamFolder,
		public readonly int $ncFolderId = 0,
	) {
	}

	/** The same mapping with its Nextcloud folder id filled in. */
	public function withNcFolderId(int $id): self {
		return new self(
			$this->id,
			$this->grafanaFolderUid,
			$this->grafanaFolderTitle,
			$this->ncFolder,
			$this->mode,
			$this->format,
			$this->useTeamFolder,
			$id,
		);
	}

	/** The same mapping with its Nextcloud folder name refreshed after a rename. */
	public function withNcFolder(string $ncFolder): self {
		return new self(
			$this->id,
			$this->grafanaFolderUid,
			$this->grafanaFolderTitle,
			self::normaliseFolder($ncFolder),
			$this->mode,
			$this->format,
			$this->useTeamFolder,
			$this->ncFolderId,
		);
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
		// The Nextcloud folder is OPTIONAL: when omitted, materialise it to the Grafana
		// folder's NAME (its title) AT CREATE and store it (mappings are immutable, so
		// resolving once is enough). This keeps both folder fields populated in the saved
		// mapping + the admin list, so it's visible at a glance that they match because the
		// NC name was left blank. If there is no title to borrow either, the invariant below
		// still requires an explicit nc_folder.
		if ($ncFolder === '' && $title !== '') {
			$ncFolder = self::normaliseFolder($title);
		}
		// DEFAULTS TO `link`, WHICH IT DID NOT USED TO. An omitted mode was a hard
		// refusal, so the shortest useful add-mapping — a Grafana folder and
		// nothing else — could not be written at all, and every caller had to name
		// a mode it had no opinion about. `link` is the conservative choice: it
		// downloads nothing and pushes nothing back, so a mapping made without
		// thinking about mode cannot cost anything. Individual files are promoted
		// afterwards.
		//
		// Note the inconsistency this removes: `format` two lines below has always
		// defaulted, and for the same reason. Mode was the odd one out.
		//
		// Matches the Penpot sibling, which has always defaulted this way. The gap
		// here and in nextcloud-n8n was found by writing the admin-mapping spec's
		// defaults table and having no value to put in the `mode` row.
		$mode = (string)($data['mode'] ?? self::MODE_LINK);

		// Format defaults to the classic JSON cut when absent — everything already
		// is this, so an omitted field is never a surprise.
		$format = (string)($data['format'] ?? self::FORMAT_JSON);
		if ($format === '') {
			$format = self::FORMAT_JSON;
		}

		// Storage backend. DEFAULT FALSE — an omitted flag means an admin-owned
		// folder, because that is the only backend guaranteed to exist.
		//
		// A Team Folder needs the groupfolders app, which is OPTIONAL and absent on
		// a stock Nextcloud. Defaulting to it meant the default mapping was the one
		// that could not be provisioned: an admin who filled in the required fields
		// and touched nothing else got a refusal on a plain install. A default must
		// be the safe choice, not the preferred one.
		//
		// This was inherited from the n8n master, which had the same inversion and
		// is fixed in the same pass. Penpot has always defaulted to false.
		//
		// NOTE FOR OLD DATA: `toArray()` always writes the key, so every mapping
		// this app has ever saved carries it explicitly and is unaffected. Only a
		// row persisted before the flag existed at all would read differently.
		$useTeamFolder = array_key_exists('use_team_folder', $data)
			&& filter_var($data['use_team_folder'], FILTER_VALIDATE_BOOLEAN);

		// The Nextcloud folder id. 0 means "not resolved yet" — either the folder has
		// not been provisioned, or this row predates the field. A negative id is
		// nonsense, so it reads as 0 rather than being trusted.
		$ncFolderId = max(0, (int)($data['nc_folder_id'] ?? 0));

		if ($uid === '') {
			throw new \InvalidArgumentException('grafana_folder_uid is required');
		}
		if ($ncFolder === '') {
			// Reachable when nc_folder is blank AND there is no grafana_folder_title to default
			// from (a non-empty uid alone does not fill it — we default from the folder *name*).
			throw new \InvalidArgumentException('nc_folder is required (or a grafana_folder_title to default it from)');
		}
		if (!in_array($mode, [self::MODE_SYNC, self::MODE_LINK], true)) {
			throw new \InvalidArgumentException('mode must be "sync" or "link"');
		}
		if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_YAML], true)) {
			throw new \InvalidArgumentException('format must be "json" or "yaml"');
		}

		return new self($id, $uid, $title, $ncFolder, $mode, $format, $useTeamFolder, $ncFolderId);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'grafana_folder_uid' => $this->grafanaFolderUid,
			'grafana_folder_title' => $this->grafanaFolderTitle,
			'nc_folder' => $this->ncFolder,
			'nc_folder_id' => $this->ncFolderId,
			'mode' => $this->mode,
			'format' => $this->format,
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

}
