<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;

/**
 * Wraps Nextcloud's Files Metadata API for Grafana dashboard files — the master's
 * {@see \OCA\N8nSync\Service\WorkflowMetadata}, re-cut for our ingredient. This is
 * the **metadata contract** the pull/push spine builds against (saga Ch2 Round 2,
 * the "seam"); the key set was scrutinised, not 1:1 renamed (Fork A):
 *
 *   grafana_uid         — the dashboard uid. Stable across renames/moves.
 *   grafana_mode        — sync | link | unmapped. INDEXED.
 *   grafana_version     — the Grafana `version` we last reconciled. Grafana bumps
 *                         this on EVERY save, so we store it but NEVER hash it
 *                         (saga Ch1 risk #6).
 *   grafana_syncedHash  — sha1 of the spec WE SENT at the last pull/push (the
 *                         writeback loop guard — never Grafana's echoed-back object).
 *   grafana_mapping     — id of the originating mapping. INDEXED.
 *   grafana_folderUid   — source Grafana folder uid (nested-folder breadcrumb).
 *                         BANKED: registered + readable now, written by the subfolder
 *                         course.
 *
 * Why this is the cleanest layer (same as the master):
 *  - **Server-side reads** (listeners, occ commands) call ::read() directly — zero
 *    DAV plumbing, zero round-trips.
 *  - **DAV/PROPFIND exposure is automatic.** Once registered with `initMetadata()`,
 *    every key is advertised at `{http://nextcloud.org/ns}metadata-<key>`, and the
 *    indexed keys are SEARCH/REPORT-queryable.
 *
 * The `link` ⇄ `reference` wire translation (THE one place it lives): NC core's
 * FilesPlugin feeds metadata values straight into PropFind::handle(), which calls
 * them as callbacks when `is_callable($value)` is true. The string `link` matches
 * PHP's builtin `link()`, so storing it explodes every PROPFIND. So **link mode is
 * stored as the value `reference`** and translated back on read — everywhere else in
 * the codebase the mode is `link`. `sync` / `unmapped` are not callable,
 * so they store as-is. Any future mode value MUST clear `is_callable()`.
 *
 * Isolation from the n8n sibling is free by construction: NC Files-Metadata keys are
 * a flat global namespace keyed by the exact string, so `grafana_mode` and `n8n_mode`
 * are different keys and never cross-contaminate (saga Ch2 Fork B).
 *
 * All keys are EDIT_FORBIDDEN: clients cannot mutate them via PROPPATCH. Only this
 * app writes them, from the pull/push reconcilers.
 */
final class DashboardMetadata {
	public const KEY_UID = 'grafana_uid';
	public const KEY_MODE = 'grafana_mode';       // sync | reference(=link) | unmapped — INDEXED
	public const KEY_VERSION = 'grafana_version';
	/** sha1 of the spec we sent at the last successful pull/push — the writeback loop guard. */
	public const KEY_SYNCED_HASH = 'grafana_syncedHash';
	/** Id of the originating mapping — INDEXED so files can be targeted by mapping. */
	public const KEY_MAPPING = 'grafana_mapping';
	/** Source Grafana folder uid (nested-folder breadcrumb). Banked — written by the subfolder course. */
	public const KEY_FOLDER_UID = 'grafana_folderUid';
	/** Serialization schema (classic JSON vs v2 YAML). Banked — written by Course 6. */

	/** File-mode values not covered by {@see Mapping} (which only configures sync/link). */
	public const MODE_UNMAPPED = 'unmapped';

	/**
	 * The on-the-wire (stored) value for {@see Mapping::MODE_LINK}. `link` itself is
	 * is_callable() and crashes core PROPFIND, so it is stored as `reference` and
	 * translated back by {@see read()}. This is the ONLY place `reference` appears.
	 */
	private const WIRE_LINK = 'reference';

	/** All managed keys, in a stable order suitable for diagnostics. */
	public const KEYS = [
		self::KEY_UID,
		self::KEY_MODE,
		self::KEY_VERSION,
		self::KEY_SYNCED_HASH,
		self::KEY_MAPPING,
		self::KEY_FOLDER_UID,
	];

	/** Keys stored as searchable indexes (the rest are plain, read-only props). */
	private const INDEXED_KEYS = [self::KEY_MODE, self::KEY_MAPPING];

	public function __construct(
		private IFilesMetadataManager $manager,
	) {
	}

	/**
	 * Idempotently register every key with the Files Metadata system.
	 *
	 * Called once from {@see \OCA\GrafanaSync\AppInfo\Application::boot()}. After this
	 * runs, the keys are surfaced over DAV as `{nc:}metadata-<key>`, and the
	 * INDEXED_KEYS (mode + mapping) are SEARCH/REPORT-queryable — so "find every sync /
	 * unmapped file" is a fast indexed query, not a folder walk. Registering
	 * the two banked keys now (before anything writes them) means the subfolder / YAML
	 * courses drop in without a metadata migration.
	 */
	public function register(): void {
		foreach (self::KEYS as $key) {
			$this->manager->initMetadata(
				$key,
				IMetadataValueWrapper::TYPE_STRING,
				in_array($key, self::INDEXED_KEYS, true), // indexed → searchable
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}
	}

	/**
	 * Upsert the managed keys for a file. Any key omitted from `$values` is left as-is;
	 * pass an explicit empty string to overwrite. The mode is given in the canonical
	 * vocabulary (`sync`/`link`/`unmapped`); `link` is stored as `reference`
	 * on the wire (see class docblock).
	 *
	 * @param array{
	 *     grafana_uid?:string,
	 *     grafana_mode?:string,
	 *     grafana_version?:string,
	 *     grafana_syncedHash?:string,
	 *     grafana_mapping?:string,
	 *     grafana_folderUid?:string,
	 * } $values
	 */
	public function write(int $fileId, array $values): void {
		if ($values === []) {
			return;
		}
		$metadata = $this->manager->getMetadata($fileId, true);
		foreach (self::KEYS as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}
			$stored = $this->toWire($key, $values[$key]);
			// Indexed keys must be written with the index flag so they're searchable.
			$metadata->setString($key, $stored, in_array($key, self::INDEXED_KEYS, true));
		}
		$this->manager->saveMetadata($metadata);
	}

	/**
	 * Stamp the core sync-metadata set for a managed file in one call: uid, mode,
	 * version, the body hash (the push loop-guard, computed here from $spec), and the
	 * originating mapping. The single home for the shape the pull reconciler and
	 * create-on-land both write — callers apply the ownership tag separately.
	 *
	 * `$spec` MUST be the exact bytes we sent to / read from Grafana for the dashboard
	 * body — hashing Grafana's echoed-back object (which carries the bumped `version`)
	 * would make a push→pull look like a change and loop (saga Ch1 risk #6).
	 *
	 * The banked key `grafana_folderUid` is intentionally NOT stamped here — the
	 * subfolder course writes it via {@see write()}.
	 */
	public function stampSynced(int $fileId, string $uid, string $mode, string $version, string $spec, string $mappingId): void {
		$this->write($fileId, [
			self::KEY_UID => $uid,
			self::KEY_MODE => $mode,
			self::KEY_VERSION => $version,
			self::KEY_SYNCED_HASH => sha1($spec),
			self::KEY_MAPPING => $mappingId,
		]);
	}

	/**
	 * Read the managed keys for a file as a typed {@see ManagedFile}.
	 *
	 * Returns null if the file has no metadata record at all. Otherwise a ManagedFile
	 * whose unset keys read back as `''`. The mode is returned in the canonical
	 * vocabulary (the stored `reference` becomes `link`).
	 */
	public function read(int $fileId): ?ManagedFile {
		try {
			$metadata = $this->manager->getMetadata($fileId, false);
		} catch (FilesMetadataNotFoundException) {
			return null;
		}
		$value = fn (string $key): string => $metadata->hasKey($key)
			? $this->fromWire($key, $metadata->getString($key))
			: '';
		return new ManagedFile(
			$value(self::KEY_UID),
			$value(self::KEY_MODE),
			$value(self::KEY_VERSION),
			$value(self::KEY_SYNCED_HASH),
			$value(self::KEY_MAPPING),
			$value(self::KEY_FOLDER_UID),
		);
	}

	/**
	 * Drop the entire managed-metadata record for a file. Used when a COPY lands: a
	 * copy is ALWAYS a brand-new instance and must never inherit the original's
	 * `grafana_uid` / mode / mapping, so its metadata is wiped to a clean slate.
	 * Idempotent — safe on a file that has no record.
	 */
	public function clear(int $fileId): void {
		$this->manager->deleteMetadata($fileId);
	}

	/** Canonical → stored: `link` mode is persisted as `reference`. */
	private function toWire(string $key, string $value): string {
		return ($key === self::KEY_MODE && $value === Mapping::MODE_LINK) ? self::WIRE_LINK : $value;
	}

	/** Stored → canonical: the stored `reference` mode reads back as `link`. */
	private function fromWire(string $key, string $value): string {
		return ($key === self::KEY_MODE && $value === self::WIRE_LINK) ? Mapping::MODE_LINK : $value;
	}
}
