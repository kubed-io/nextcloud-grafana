<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * One read of `GET /api/dashboards/uid/{uid}`, in the two halves the caller needs.
 *
 * Grafana answers that endpoint with `{meta: {...}, dashboard: {...}}` — the spec we
 * mirror to disk, and the facts *about* the dashboard that only the server knows. The
 * client used to `return $record->dashboard` and drop `meta` on the floor one line
 * before anyone could use it, which is why "when did this dashboard actually change?"
 * looked like it needed a second request (saga Ch2, Course 8). It never did.
 *
 * Keeping the pair in one object is what makes that impossible to regress: the
 * timestamps cannot be forgotten by a future caller, because they arrive with the spec
 * rather than beside it.
 *
 * The two clocks are Unix seconds, or null when Grafana sent nothing usable — null
 * means "leave that clock alone", never "stamp the epoch".
 */
final class DashboardSpec {
	public function __construct(
		/** The `dashboard` object, decoded as stdClass so empty `{}` survives the round trip. */
		public readonly \stdClass $spec,
		/** `meta.updated` — when the dashboard last changed in Grafana. */
		public readonly ?int $updated,
		/** `meta.created` — when it was created in Grafana. */
		public readonly ?int $created,
	) {
	}

	/** The dashboard's own `version`, as the string {@see DashboardMetadata} banks. */
	public function version(): string {
		$raw = $this->spec->version ?? null;
		return is_scalar($raw) ? (string)$raw : '';
	}

	/**
	 * When the dashboard last changed — the clock a mirror wears.
	 *
	 * `meta.updated` whenever Grafana has one, **and `meta.created` when it does not**,
	 * because a dashboard with no update recorded has not changed since it was made.
	 *
	 * THAT SECOND HALF IS NOT DEFENSIVE PADDING; IT IS THE ONLY CORRECT ANSWER IN A
	 * WINDOW WE LIVE IN. Measured in CI: read a dashboard back immediately after
	 * `POST /api/dashboards/db` and Grafana answers with `meta.created` set and
	 * `meta.updated` empty — the row the update clock is read from is not visible yet.
	 * A second later the same read carries `updated`, equal to `created`.
	 *
	 * Reading `updated` alone in that window means null, which {@see MirrorTimes}
	 * correctly treats as "leave that clock alone" — so the file kept whatever mtime it
	 * arrived with while the creation clock was stamped from the same read. A copy is
	 * where that shows: it inherits the SOURCE file's mtime, which is a real timestamp
	 * from a different dashboard, so nothing looks broken. The copy scenarios found it.
	 */
	public function lastChanged(): ?int {
		return $this->updated ?? $this->created;
	}

	/**
	 * An ISO-8601 timestamp from Grafana (`2026-02-14T06:03:53Z`) as a Unix second.
	 *
	 * Null for anything absent, empty, or unparseable — so a schema change on Grafana's
	 * side degrades to "keep Nextcloud's own clock", which is merely the old behaviour,
	 * rather than to a mirror dated 1970.
	 *
	 * A NON-POSITIVE RESULT IS ALSO NULL, and that is Grafana-specific rather than
	 * tidiness: Grafana serialises an unset time as Go's zero value,
	 * `0001-01-01T00:00:00Z` (its `meta.expires` carries one on every dashboard).
	 * `strtotime` parses that perfectly well into year 1, so without this an unset
	 * clock would not degrade to "leave it alone" — it would stamp the mirror with a
	 * date two thousand years in the past.
	 */
	public static function parseTime(mixed $value): ?int {
		if (!is_string($value) || $value === '') {
			return null;
		}
		$ts = strtotime($value);
		return ($ts === false || $ts <= 0) ? null : $ts;
	}
}
