<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * Typed view of a managed dashboard file's Files-Metadata — the `grafana_*` keys
 * {@see DashboardMetadata} stores, read back as a value object instead of an
 * `array<string,mixed>` the caller pokes at with `?? null` + `is_string()`.
 *
 * Every field is normalised to a plain string: a key that was never stamped reads
 * back as `''` (not null), so callers compare against `''` or use the `is*()`
 * helpers and never juggle null. A file with no metadata record at all is
 * represented by {@see DashboardMetadata::read()} returning `null`, not by a
 * ManagedFile with empty fields.
 *
 * The mode is in the **canonical** vocabulary (`sync` / `link` / `unmapped`) — the
 * stored `reference` wire value is already translated back to `link` by
 * {@see DashboardMetadata::read()} before it reaches here.
 *
 * `folderUid` is a banked key (saga Ch2 Fork A): registered and readable now, but
 * only *written* by the subfolder mirror course. It reads back as `''` until then.
 *
 * There used to be an `apiVersion` beside it, banked for a v2/YAML serialization
 * course. That course is not happening — dashboards are JSON — so the key is gone
 * rather than left registered against a decision nobody is going to make.
 */
final class ManagedFile {
	public function __construct(
		public readonly string $uid,
		public readonly string $mode,
		public readonly string $version,
		public readonly string $syncedHash,
		public readonly string $mappingId,
		public readonly string $folderUid,
	) {
	}

	/** True when the file carries a Grafana dashboard uid — i.e. it is one of ours. */
	public function isManaged(): bool {
		return $this->uid !== '';
	}

	public function isSync(): bool {
		return $this->mode === Mapping::MODE_SYNC;
	}

	public function isLink(): bool {
		return $this->mode === Mapping::MODE_LINK;
	}

	public function isUnmapped(): bool {
		return $this->mode === DashboardMetadata::MODE_UNMAPPED;
	}
}
