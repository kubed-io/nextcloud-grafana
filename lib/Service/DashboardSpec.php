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
	 * **A DASHBOARD CANNOT HAVE CHANGED BEFORE IT EXISTED**, so this is the LATER of the
	 * two clocks, and `created` alone when there is no `updated` at all.
	 *
	 * That reads like defensive padding and is not. Read a dashboard back in the moment
	 * after `POST /api/dashboards/db` and Grafana's two clocks — which are one instant on
	 * any dashboard nobody has edited — can come back a second apart, `updated` BEHIND
	 * `created`. They are two separate reads of "now" taken either side of a second
	 * boundary; a moment later the same request reports both as the same second, which is
	 * what makes it look impossible from the outside.
	 *
	 * Taking `updated` at face value there dates the mirror one second before the
	 * dashboard, forever — until some later pull happens to correct it. And it only
	 * misses when a write straddles a second, so it fails perhaps half the time, which is
	 * how it survived: it reads as flakiness in the test rather than a bug in the app.
	 *
	 * The copy scenarios are what caught it, because a copy is the one gesture where the
	 * mirror's own arrival time is ALSO a plausible-looking timestamp — it inherits the
	 * source file's, which belongs to a different dashboard entirely.
	 */
	public function lastChanged(): ?int {
		if ($this->updated === null || $this->created === null) {
			return $this->updated ?? $this->created;
		}
		return max($this->updated, $this->created);
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
