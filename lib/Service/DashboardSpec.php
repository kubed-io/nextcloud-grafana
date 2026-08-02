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
	 * An ISO-8601 timestamp from Grafana (`2026-02-14T06:03:53Z`) as a Unix second.
	 *
	 * Null for anything absent, empty, or unparseable — so a schema change on Grafana's
	 * side degrades to "keep Nextcloud's own clock", which is merely the old behaviour,
	 * rather than to a mirror dated 1970.
	 */
	public static function parseTime(mixed $value): ?int {
		if (!is_string($value) || $value === '') {
			return null;
		}
		$ts = strtotime($value);
		return $ts === false ? null : $ts;
	}
}
