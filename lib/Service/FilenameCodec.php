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
 * \OCA\N8nSync\Service\FilenameCodec}, re-cut for our compound extension.
 *
 * Two on-disk shapes are supported:
 *
 *   1. Clean (default)         <name>.grafana.json
 *   2. Uid-suffixed (opt-in)   <name>.<uid>.grafana.json
 *
 * The clean shape is the default user-facing layout. The uid-suffixed shape is an
 * admin opt-in (`uid_in_filename` AppConfig flag) for environments where the Files
 * Metadata API exposure over WebDAV ever regresses, or for users who want the deep
 * link resolvable purely from the filename. Both carry the same metadata server-side
 * via the Files Metadata API — the filename is only a redundant carrier.
 *
 * The compound `.grafana.json` extension is a locked decision (AGENTS.md): the real
 * `.json` tail means the OS opens the file in a JSON editor outside Nextcloud, while
 * the `.grafana.` segment is the hook NC keys the custom mimetype / icon / actions
 * off inside the UI. (The v2/YAML cut adds `.grafana.yaml` in Course 6; this codec is
 * the classic JSON cut for now.)
 *
 * Collision policy: when two dashboards in the same Grafana folder share a `title`
 * (Grafana permits this), the first file gets the plain name and the ones after it get
 * an NC-style ` (1)`, ` (2)`, … suffix — Nextcloud's own counter, which starts at one.
 * The chosen filename is what gets stored in metadata, so subsequent pulls are stable
 * and won't oscillate.
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
 * This class is **pure logic**: no filesystem access, no DI dependencies, trivial to
 * unit test.
 */
final class FilenameCodec {
	/** Trailing extension of the classic JSON cut. */
	public const EXT = '.grafana.json';

	/**
	 * True when $name is a managed Grafana dashboard filename (ends in {@see EXT}).
	 * Pure string test — the single source of truth for "is this one of ours?".
	 */
	public static function isDashboardName(string $name): bool {
		$name = self::canonicalise($name);
		// Require a non-empty stem so this agrees with parse() (which rejects a bare
		// ".grafana.json") — the two predicates must never disagree on "is this ours?".
		return strlen($name) > strlen(self::EXT) && str_ends_with($name, self::EXT);
	}

	/**
	 * Fold NEXTCLOUD'S collision spelling into ours.
	 *
	 * There are two conventions for "that name is taken", and only one was ever read.
	 * Ours puts the counter on the logical name — `Board (1).grafana.json` — and
	 * {@see parse()} strips it. Nextcloud puts it before the LAST extension, because
	 * to Nextcloud the extension is `.json` and the basename is `Board.grafana`:
	 *
	 *     Board.grafana (1).json
	 *
	 * That does not end in `.grafana.json`, so every predicate in this app answered
	 * "not ours" — measured on the live instance, where copying a dashboard into a
	 * folder that already held it produced exactly that name, no metadata, no
	 * dashboard in Grafana, and a file still carrying the ORIGINAL's uid in its body.
	 * A copy the app cannot see is the most dangerous shape it can take: it looks like
	 * a dashboard to the user and points at somebody else's dashboard underneath.
	 *
	 * We do not get to choose this name — Nextcloud picks it, on our files, whenever a
	 * copy lands beside its source. So it has to be read. Rewriting it to our own
	 * spelling here means every caller downstream keeps working unchanged, and
	 * {@see parse()} then strips the counter exactly as it does for our own form.
	 */
	public static function canonicalise(string $name): string {
		if (preg_match('/^(.+)\.grafana \((\d+)\)\.json$/', $name, $m) !== 1) {
			return $name;
		}
		$stem = $m[1];
		$counter = ' (' . $m[2] . ')';

		// THE UID SEGMENT STAYS LAST. {@see format()} composes the opt-in shape as
		// `<name> (N).<uid>.grafana.json` — counter on the NAME, uid immediately before
		// the extension — and {@see parse()} looks for the uid at the last dot. Appending
		// the counter blindly would produce `Board.<uid> (1).grafana.json`, where the uid
		// segment reads as `<uid> (1)`, matches nothing, and the identity is silently
		// lost on exactly the gesture most likely to need it.
		$lastDot = strrpos($stem, '.');
		if ($lastDot !== false && preg_match(self::UID_RE, substr($stem, $lastDot + 1)) === 1) {
			return substr($stem, 0, $lastDot) . $counter . substr($stem, $lastDot) . self::EXT;
		}
		return $stem . $counter . self::EXT;
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
		$basename = self::canonicalise($basename);
		if (!str_ends_with($basename, self::EXT)) {
			return null;
		}
		$stem = substr($basename, 0, -strlen(self::EXT));
		if ($stem === '') {
			return null;
		}

		// Try uid-suffixed shape first: `<name>.<uid>` where `<uid>` matches UID_RE.
		// Walk from the rightmost dot so a name containing dots (e.g. "v1.2 board")
		// still parses.
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

		// Strip an optional " (N)" collision suffix off the *end* of the resolved name
		// so subsequent pulls can detect they're updating the same logical file. The
		// unstripped form is kept as `display`, because a counter Nextcloud put there is
		// part of the name the user sees — see this class's docblock for which of the
		// two a caller wants.
		$display = $name;
		$suffix = 0;
		if (preg_match('/^(?<base>.+) \((?<n>\d+)\)$/', $name, $m)) {
			$suffix = (int)$m['n'];
			$name = $m['base'];
		}

		return ['name' => $name, 'uid' => $uid, 'suffix' => $suffix, 'display' => $display];
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
	 * True when $name is one of ours but spelled NEXTCLOUD'S way — `Board.grafana (1).json`
	 * rather than `Board (1).grafana.json`.
	 *
	 * {@see canonicalise()} folds that spelling on the way IN so every predicate reads
	 * it. This is the write-side question: should the file on disk be renamed? Reading
	 * the name is enough to make the app work; renaming it is what makes the counter
	 * land where a user of this app expects to see it, on the dashboard's name rather
	 * than inside its extension.
	 */
	public static function isNextcloudSpelling(string $name): bool {
		return self::canonicalise($name) !== $name;
	}

	/**
	 * Build a filename for a dashboard.
	 *
	 * @param string $name Dashboard title from Grafana.
	 * @param string $uid Dashboard uid from Grafana.
	 * @param bool $uidInFilename If true, embed the uid segment.
	 * @param int $collisionIndex 0 = canonical filename, 1+ adds "(N)".
	 */
	public static function format(string $name, string $uid, bool $uidInFilename, int $collisionIndex = 0): string {
		$safe = self::sanitiseName($name);
		if ($safe === '') {
			// Fall back to uid so we never produce just ".grafana.json".
			$safe = $uid;
		}
		$stem = $safe;
		if ($collisionIndex > 0) {
			$stem .= ' (' . $collisionIndex . ')';
		}
		if ($uidInFilename) {
			$stem .= '.' . $uid;
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
