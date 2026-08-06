<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\ScheduleInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled pull's interval, as an admin types it.
 *
 * Every case below is something someone can actually enter in the Sync Settings
 * field — it is free text, so the parser meets whatever a person felt like.
 *
 * THE TWO THAT MATTER ARE THE FLOOR AND THE FALLBACK. A zero or sub-tick
 * interval would be a tight loop against the Grafana API, and an unparseable one
 * must not be read as zero. So a typo degrades to "runs hourly", never to
 * "hammers the server" — the failure mode has to be boring.
 */
#[CoversClass(ScheduleInterval::class)]
final class ScheduleIntervalTest extends TestCase {
	#[DataProvider('intervals')]
	public function testParsesWhatAnAdminMightType(string $raw, int $expected): void {
		self::assertSame($expected, ScheduleInterval::seconds($raw));
	}

	/** @return iterable<string, array{string, int}> */
	public static function intervals(): iterable {
		yield 'plain number means seconds' => ['90', 90];
		yield 'seconds unit' => ['90s', 90];
		yield 'minutes' => ['15m', 900];
		yield 'hours' => ['2h', 7200];
		yield 'days' => ['1d', 86400];

		// Free text, so tolerate how people actually write it.
		yield 'a space before the unit' => ['30 m', 1800];
		yield 'uppercase' => ['2H', 7200];
		yield 'surrounding whitespace' => ['  45m  ', 2700];

		// The floor: the cron tick is 60s, so less than that is "every tick".
		yield 'below the floor is clamped' => ['5s', 60];
		yield 'zero is clamped, never a tight loop' => ['0', 60];

		// The fallback: hourly, never zero.
		yield 'nonsense' => ['whenever', 3600];
		yield 'empty' => ['', 3600];
		yield 'whitespace only' => ['   ', 3600];
		yield 'a negative is not a number here' => ['-5m', 3600];
		yield 'a unit we do not know' => ['3w', 3600];
		yield 'a decimal' => ['1.5h', 3600];

		// THE CAP, and it is a correctness case rather than a taste one: without it
		// the multiplication overflows to float and the `: int` return raises a
		// TypeError, so a parseable-but-absurd value would CRASH the job's
		// constructor instead of degrading. Caught in review.
		yield 'absurdly large is capped, not crashed' => ['999999999999d', ScheduleInterval::MAXIMUM];
		yield 'beyond int range is capped, not crashed' => ['99999999999999999999', ScheduleInterval::MAXIMUM];
		yield 'exactly the cap' => ['30d', ScheduleInterval::MAXIMUM];
		yield 'just under the cap' => ['29d', 2505600];
	}
}
