<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\Files\File;
use OCP\Files\Node;

/**
 * Filename codec for Grafana dashboard files — the master's {@see
 * \OCA\N8nSync\Service\FilenameCodec}, re-cut for our own extension.
 *
 * Two on-disk shapes are supported:
 *
 *   1. Clean (default)         <name>.grafana
 *   2. Uid-suffixed (opt-in)   <name>.<uid>.grafana
 *
 * The clean shape is the default user-facing layout. The uid-suffixed shape is an
 * admin opt-in (`uid_in_filename` AppConfig flag) for environments where the Files
 * Metadata API exposure over WebDAV ever regresses, or for users who want the deep
 * link resolvable purely from the filename. Both carry the same metadata server-side
 * via the Files Metadata API — the filename is only a redundant carrier.
 *
 * ## ONE SEGMENT, BECAUSE NEXTCLOUD ONLY EVER READS ONE
 *
 * This was `.grafana.json` for the app's first two chapters, on the reasoning that the
 * real `.json` tail made the file open in a JSON editor off-Nextcloud. It cost more
 * than it bought, and the bill came due on `copy`:
 *
 *   - `IMimeTypeDetector::detectPath()` takes the LAST extension and nothing else
 *     (`strrchr`, verified in core). `Name.grafana.json` is `application/json` to
 *     Nextcloud, forever — so every file we wrote landed with the wrong mimetype and
 *     had to be corrected afterwards by a table-wide UPDATE, on **every write**.
 *   - Nextcloud's collision counter goes before the LAST extension, so a copy landing
 *     beside its source was named `Name.grafana (1).json` — a name that ends in
 *     `.json`, matches none of our predicates, and made the copy invisible to the app.
 *     Reading it back took a `canonicalise()` pass in front of every predicate here,
 *     and un-writing it took a deferred rename in a background job.
 *
 * With a single segment both problems stop existing rather than getting handled:
 * `Name.grafana` is detected as `application/grafana+json` by core's own detector, and
 * a colliding copy is born `Name (1).grafana` — already our spelling, because it is
 * Nextcloud's. The counter sits immediately before the extension, and {@see format()}
 * puts it in exactly the same place, so the two conventions are one convention.
 *
 * The `.json` tail is not free to give up: off-Nextcloud, a `.grafana` file needs a
 * one-time editor association to open as JSON. That is a per-machine setting made once,
 * weighed against a mimetype correction on every save and a copy the app could not see.
 *
 * ## TWO NAMES, AND THE DIFFERENCE MATTERS
 *
 * {@see parse()} returns both, because callers want opposite things from a suffixed
 * file:
 *
 *   `name`     the LOGICAL name, counter stripped — `Fleet Health`. What a pull matches
 *              a dashboard's title against, so a mirror already wearing `(1)` is
 *              recognised as the same logical file next time round.
 *   `display`  the name AS WRITTEN, counter and all — `Fleet Health (1)`. What the user
 *              sees, and therefore what the JSON `title` and the Grafana dashboard have
 *              to say whenever NEXTCLOUD is the one that named the file.
 *
 * Which of the two is authoritative is decided by WHERE THE GESTURE HAPPENED, and both
 * directions are exercised by a copy:
 *
 *   - Copied in Nextcloud → Nextcloud picked the name, counter included, so `display`
 *     is the name and it propagates to the JSON title and to Grafana. All three agree.
 *   - Copied in Grafana → Grafana permits two dashboards with one title and Nextcloud
 *     does not permit two files with one name, so the counter is added to the FILENAME
 *     ONLY and `name` is what still matches the dashboard. This is the single exception
 *     to *a name is one value living in three places*.
 *
 * Collision policy: when two dashboards in the same Grafana folder share a `title`
 * (Grafana permits this), the first file gets the plain name and the ones after it get
 * an NC-style ` (1)`, ` (2)`, … suffix — Nextcloud's own counter, which starts at one.
 * The chosen filename is what gets stored in metadata, so subsequent pulls are stable
 * and won't oscillate.
 *
 * This class is **pure logic**: no filesystem access, no DI dependencies, trivial to
 * unit test.
 */
final class FilenameCodec {
	/** Trailing extension of a dashboard file. */
	public const EXT = '.grafana';

	/**
	 * True when $name is a managed Grafana dashboard filename (ends in {@see EXT}).
	 * Pure string test — the single source of truth for "is this one of ours?".
	 */
	public static function isDashboardName(string $name): bool {
		// Require a non-empty stem so this agrees with parse() (which rejects a bare
		// ".grafana") — the two predicates must never disagree on "is this ours?".
		return strlen($name) > strlen(self::EXT) && str_ends_with($name, self::EXT);
	}

	/**
	 * True when $node is a managed Grafana dashboard file: a {@see File} whose name
	 * ends in {@see EXT}. The one predicate the listeners/services share instead of
	 * open-coding `$node instanceof File && str_ends_with(...)` everywhere.
	 *
	 * @psalm-assert-if-true File $node
	 */
	public static function isDashboardFile(?Node $node): bool {
		return $node instanceof File && self::isDashboardName($node->getName());
	}

	/**
	 * What a Grafana dashboard uid looks like in practice (verified against the live
	 * instance: e.g. `kel4vkt`, `af397c9y8enswf`, `nc-alpha-demo`). Mixed-case
	 * alphanumeric plus `-` and `_`; Grafana caps uids at 40 chars. The lower bound of
	 * 6 is deliberately lax — we want to recognise a uid segment, not validate it;
	 * Grafana is the source of truth.
	 *
	 * The character class explicitly excludes `.` so we can never confuse a uid with a
	 * name fragment that happened to contain dots.
	 */
	private const UID_RE = '/^[A-Za-z0-9_-]{6,40}$/';

	/** A trailing Nextcloud collision counter, e.g. the ` (2)` of `Fleet Health (2)`. */
	private const COUNTER_RE = '/^(?<base>.+) \((?<n>\d+)\)$/';

	/**
	 * Parse a basename (or full path; we ignore everything before the last slash) into
	 * its components. Returns null if the basename does not end in {@see EXT}.
	 *
	 * Both clean and uid-suffixed shapes are recognised; the uid field is `null` for
	 * the clean shape and a non-empty string for the suffixed shape.
	 *
	 * @return array{name:string, uid:?string, suffix:int, display:string}|null suffix is the collision counter (0 = canonical name, 1+ = "(N)" duplicate); `display` is the name with that counter still on it
	 */
	public static function parse(string $basename): ?array {
		$slash = strrpos($basename, '/');
		if ($slash !== false) {
			$basename = substr($basename, $slash + 1);
		}
		if (!str_ends_with($basename, self::EXT)) {
			return null;
		}
		$stem = substr($basename, 0, -strlen(self::EXT));
		if ($stem === '') {
			return null;
		}

		// THE COUNTER COMES OFF FIRST, because it is the last thing on the stem — that is
		// where Nextcloud puts it and where {@see format()} puts it. Reading it before the
		// uid keeps the uid segment intact on a duplicated file: `Board.abc123 (1).grafana`
		// is a uid-suffixed `Board` wearing a counter, not a file whose uid is `abc123 (1)`.
		$suffix = 0;
		$counter = '';
		if (preg_match(self::COUNTER_RE, $stem, $m) === 1) {
			$suffix = (int)$m['n'];
			$counter = ' (' . $m['n'] . ')';
			$stem = $m['base'];
		}

		// Then the uid-suffixed shape: `<name>.<uid>` where `<uid>` matches UID_RE. Walk
		// from the rightmost dot so a name containing dots (e.g. "v1.2 board") still parses.
		$uid = null;
		$name = $stem;
		$lastDot = strrpos($stem, '.');
		if ($lastDot !== false) {
			$candidate = substr($stem, $lastDot + 1);
			if (preg_match(self::UID_RE, $candidate)) {
				$uid = $candidate;
				$name = substr($stem, 0, $lastDot);
			}
		}
		if ($name === '') {
			return null;
		}

		// `name` is the logical name, so later pulls detect they're updating the same file;
		// `display` is what the user sees. See this class's docblock for which one a caller
		// wants — taking the wrong one is what let a copy reach Grafana under the
		// ORIGINAL's title.
		return ['name' => $name, 'uid' => $uid, 'suffix' => $suffix, 'display' => $name . $counter];
	}

	/**
	 * The name a managed file shows the user: its stem with any collision counter left
	 * on, and no uid segment or extension. Empty string when $basename is not one of
	 * ours.
	 *
	 * THE ONE-LINER FOR "WHAT IS THIS FILE CALLED", so callers that want the visible
	 * name don't reach into {@see parse()} and take `name` — the counter-stripped field
	 * — by mistake. That mistake is not theoretical: it is why a dashboard copied in
	 * Nextcloud arrived in Grafana still wearing the ORIGINAL's title, with the file,
	 * the JSON and Grafana disagreeing three ways about a name.
	 */
	public static function displayName(string $basename): string {
		$parsed = self::parse($basename);
		return $parsed !== null ? trim($parsed['display']) : '';
	}

	/**
	 * Build a filename for a dashboard.
	 *
	 * The counter goes LAST, immediately before the extension — the same place
	 * Nextcloud's own `getUniqueName()` puts it. That is the point of the single-segment
	 * extension: our spelling of a collision and Nextcloud's are the same spelling, so a
	 * copy the client names needs no correcting and a name we choose needs no defending.
	 *
	 * @param string $name Dashboard title from Grafana.
	 * @param string $uid Dashboard uid from Grafana.
	 * @param bool $uidInFilename If true, embed the uid segment.
	 * @param int $collisionIndex 0 = canonical filename, 1+ adds "(N)".
	 */
	public static function format(string $name, string $uid, bool $uidInFilename, int $collisionIndex = 0): string {
		$safe = self::sanitiseName($name);
		if ($safe === '') {
			// Fall back to uid so we never produce just ".grafana".
			$safe = $uid;
		}
		$stem = $safe;
		if ($uidInFilename) {
			$stem .= '.' . $uid;
		}
		if ($collisionIndex > 0) {
			$stem .= ' (' . $collisionIndex . ')';
		}
		return $stem . self::EXT;
	}

	/**
	 * Replace characters that are unsafe in NC/WebDAV filenames with `_`. Keep this
	 * conservative — we'd rather have a slightly munged but predictable name than
	 * fight every locale's edge cases.
	 *
	 * Specifically banned by NC default: `\ / : * ? " < > |` and control characters.
	 * We also collapse runs of whitespace so users don't end up with awkward
	 * "Foo   bar" filenames just because the Grafana title had a tab in it.
	 */
	private static function sanitiseName(string $name): string {
		$n = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$n = preg_replace('/[\\\\\/:\*\?"<>\|]/u', '_', $n) ?? '';
		$n = preg_replace('/\s+/u', ' ', $n) ?? '';
		$n = trim($n);
		// Strip a leading dot so the file isn't hidden on POSIX storages.
		if ($n !== '' && $n[0] === '.') {
			$n = '_' . substr($n, 1);
		}
		return $n;
	}
}
