<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * "How often should the scheduled pull run?" — as typed by an admin, in seconds.
 *
 * ## WHY THIS IS NOT A METHOD ON THE JOB
 *
 * It was, briefly. Turning a free-text setting into a number is pure logic with
 * a fiddly edge — a typo must become "hourly", never "zero" — and it is exactly
 * the sort of thing worth a table of cases. But it lived inside
 * {@see \OCA\GrafanaSync\BackgroundJob\ScheduledPullJob}, whose constructor takes
 * a `final` SyncService that PHPUnit cannot mock, so testing the parsing meant
 * either loosening production code or not testing it.
 *
 * Neither was acceptable, and both were the wrong question: the parsing is not
 * the job's business. Here it is a pure function with no collaborators, the job
 * is left doing only what a job does, and the cases below are readable as a
 * table instead of as mock setup.
 *
 * The n8n sibling still has this inline. Worth pulling out there too, though it
 * is not urgent — this note is the reminder.
 */
final class ScheduleInterval {
	/**
	 * Nextcloud's cron tick granularity. Asking for less than this is asking for
	 * "every tick", not for what it says, so it is clamped rather than honoured.
	 */
	public const MINIMUM = 60;

	/** What an unparseable or absent interval falls back to. */
	public const DEFAULT = 3600;

	/**
	 * The longest interval worth honouring — 30 days.
	 *
	 * A cap exists for correctness, not taste. Without one, `(int)$digits *
	 * $multiplier` OVERFLOWS TO FLOAT for a large enough number (`999999999999d`
	 * is a thing someone can type into a free-text field), `max()` then returns a
	 * float, and the `: int` return type raises a TypeError under strict_types.
	 * A parseable-but-absurd value would CRASH the job's constructor instead of
	 * degrading — which is the exact opposite of what the fallback is for.
	 *
	 * Caught in review. The arithmetic below is done in float precisely so the
	 * comparison can happen before any int cast.
	 */
	public const MAXIMUM = 30 * 86400;

	/**
	 * A number with an optional unit (`s`/`m`/`h`/`d`), or a plain number meaning
	 * seconds. Case and internal spaces are tolerated, because the field is free
	 * text and `2H` and `30 m` are things people type.
	 *
	 * ANYTHING UNPARSEABLE IS HOURLY, NEVER ZERO. A zero interval is a tight loop
	 * against the Grafana API, so the failure mode of a typo has to be "runs less
	 * often than you meant", not "hammers the server".
	 *
	 * Every path returns a number between {@see MINIMUM} and {@see MAXIMUM}. There
	 * is no input — however long, however silly — that throws.
	 */
	public static function seconds(string $raw): int {
		$raw = strtolower(trim($raw));
		if ($raw === '') {
			return self::DEFAULT;
		}

		if (preg_match('/^(\d+)\s*([smhd]?)$/', $raw, $m) !== 1) {
			return self::DEFAULT;
		}

		$multiplier = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];

		// FLOAT MATH ON PURPOSE. An int multiplication overflows silently to float
		// for a large enough digit string, and the int cast that followed would
		// then be out of range. Multiplying as float keeps the value comparable,
		// so the clamp happens BEFORE anything is narrowed back to int.
		$seconds = (float)$m[1] * $multiplier;

		if ($seconds >= self::MAXIMUM) {
			return self::MAXIMUM;
		}

		return max(self::MINIMUM, (int)$seconds);
	}
}
