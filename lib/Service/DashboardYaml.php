<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\Vendor\Symfony\Component\Yaml\Exception\ParseException;
use OCA\GrafanaSync\Vendor\Symfony\Component\Yaml\Yaml;

/**
 * A version-2 file's YAML, as shape-exact as the JSON.
 *
 * The rule {@see DashboardBody::encodeSync()} spells out for JSON holds here for the
 * same reason: a dashboard is full of empty objects, and a codec that turns `{}` into
 * `[]` rewrites the dashboard on the way back. So maps parse as `\stdClass`
 * (`PARSE_OBJECT_FOR_MAP`) and dump as maps.
 *
 * ## WHY THE DUMP CONVERTS OBJECTS TO ARRAYS FIRST
 *
 * `DUMP_COMPACT_NESTED_MAPPING` is what writes a list of maps the way people write it —
 * `- kind: Panel` — instead of a lone `-` with the map on the next line, which is what
 * every element, query and annotation of a dashboard would otherwise look like. Symfony
 * applies it to PHP ARRAYS only, never to objects, so the dump hands it arrays.
 *
 * Two objects stay objects, because as arrays they would change meaning:
 *  - an EMPTY one, which as `[]` is a list;
 *  - one whose keys read as a list (`{"0": a, "1": b}`, a value mapping), which PHP
 *    stores as `[0 => a, 1 => b]` and Symfony then dumps as a sequence.
 *
 * Pure and static, like {@see DashboardBody}: same input, same bytes, which is what lets
 * the pull's byte comparison stay quiet on an unchanged dashboard.
 */
final class DashboardYaml {
	private const DUMP = Yaml::DUMP_OBJECT_AS_MAP
		| Yaml::DUMP_COMPACT_NESTED_MAPPING
		| Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
		| Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

	private const PARSE = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;

	/**
	 * More values than any dashboard holds — the largest live one is ~20,000 — so a
	 * document past it is an alias expansion, not a dashboard.
	 */
	private const MAX_NODES = 1_000_000;

	public static function dump(\stdClass $doc): string {
		self::assertLoaded();
		$yaml = Yaml::dump(self::forDump($doc), PHP_INT_MAX, 2, self::DUMP);
		// Symfony ends a document that closes on a literal block without a newline. A
		// file ends with one; `|-` strips it from the value, so nothing it holds moves.
		return str_ends_with($yaml, "\n") ? $yaml : $yaml . "\n";
	}

	/**
	 * Whatever the YAML holds — an object for a map, a list, a scalar. Deciding whether
	 * that is a dashboard is the caller's business ({@see DashboardDocument::decode()}).
	 *
	 * @throws \RuntimeException naming YAML, so a user reading the notification knows
	 *                           which syntax to look at
	 */
	public static function parse(string $yaml): mixed {
		self::assertLoaded();
		try {
			$parsed = Yaml::parse($yaml, self::PARSE);
		} catch (ParseException $e) {
			throw new \RuntimeException('The dashboard file is not valid YAML: ' . $e->getMessage(), 0, $e);
		}
		// AN ALIAS BOMB PARSES CHEAPLY AND EXPANDS LATER. Aliases share their arrays
		// copy-on-write, so a few hundred bytes parse in a few megabytes — and the first
		// deep copy or encode of the result takes gigabytes. Counting the values with a
		// ceiling costs at most the ceiling, so it is refused while that is still true.
		$budget = self::MAX_NODES;
		self::spend($parsed, $budget);
		return $parsed;
	}

	/**
	 * A broken deployment (no `composer install`, so no `lib/Vendor`) fails here as an
	 * ordinary RuntimeException every caller already handles, rather than as a
	 * class-not-found Error that escapes them — `Application` deliberately loads the
	 * library only if it is there.
	 */
	private static function assertLoaded(): void {
		if (!class_exists(Yaml::class)) {
			throw new \RuntimeException('The YAML library is missing from this install of grafana_sync (lib/Vendor); reinstall the app.');
		}
	}

	private static function spend(mixed $value, int &$budget): void {
		if (--$budget < 0) {
			throw new \RuntimeException('The dashboard file is too large once its YAML aliases are expanded.');
		}
		if ($value instanceof \stdClass) {
			$value = get_object_vars($value);
		}
		if (is_array($value)) {
			foreach ($value as $child) {
				self::spend($child, $budget);
			}
		}
	}

	private static function forDump(mixed $value): mixed {
		if ($value instanceof \stdClass) {
			// array-key, not string: PHP stores a numeric property name (`{"0": …}`) as an
			// int key here, which is exactly the case the list check below exists for.
			/** @var array<array-key, mixed> $fields */
			$fields = array_map(self::forDump(...), get_object_vars($value));
			// Empty, or keyed like a list: only an object stays a map.
			return ($fields === [] || array_is_list($fields)) ? (object)$fields : $fields;
		}
		return is_array($value) ? array_map(self::forDump(...), $value) : $value;
	}
}
