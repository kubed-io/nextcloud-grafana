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
 * (Grafana permits this), the second through Nth file get an NC-style `(2)`, `(3)`,
 * … suffix. The chosen filename is what gets stored in metadata, so subsequent pulls
 * are stable and won't oscillate.
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
		// Require a non-empty stem so this agrees with parse() (which rejects a bare
		// ".grafana.json") — the two predicates must never disagree on "is this ours?".
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

	/**
	 * Parse a basename (or full path; we ignore everything before the last slash) into
	 * its components. Returns null if the basename does not end in {@see EXT}.
	 *
	 * Both clean and uid-suffixed shapes are recognised; the uid field is `null` for
	 * the clean shape and a non-empty string for the suffixed shape.
	 *
	 * @return array{name:string, uid:?string, suffix:int}|null suffix is the collision counter (0 = canonical name, 1+ = "(N)" duplicate)
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
		// so subsequent pulls can detect they're updating the same logical file.
		$suffix = 0;
		if (preg_match('/^(?<base>.+) \((?<n>\d+)\)$/', $name, $m)) {
			$suffix = (int)$m['n'];
			$name = $m['base'];
		}

		return ['name' => $name, 'uid' => $uid, 'suffix' => $suffix];
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
