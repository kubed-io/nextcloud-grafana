<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DashboardSpec} — the pair of clocks a mirror wears, and the two
 * ways Grafana says "no clock here" without saying null.
 *
 * Both were found by real failures rather than by reading the schema: a just-created
 * dashboard answers with `created` and an EMPTY `updated`, and an unset time elsewhere
 * in the same payload (`meta.expires`) is Go's zero value, which parses fine and would
 * date a mirror to year 1.
 */
#[CoversClass(DashboardSpec::class)]
final class DashboardSpecTest extends TestCase {
	private function spec(?int $updated, ?int $created): DashboardSpec {
		return new DashboardSpec((object)['uid' => 'd1'], $updated, $created);
	}

	public function testTheUpdateTimeIsWhenTheDashboardLastChanged(): void {
		self::assertSame(1771000900, $this->spec(1771000900, 1771000000)->lastChanged());
	}

	/**
	 * A dashboard with no update recorded has not changed since it was made, so its
	 * creation IS when it last changed. Reading `updated` alone here means null, and
	 * null means "leave the file's clock alone" — which leaves a fresh mirror wearing
	 * a timestamp that belongs to something else.
	 */
	public function testWithNoUpdateRecordedTheDashboardLastChangedWhenItWasCreated(): void {
		self::assertSame(1771000000, $this->spec(null, 1771000000)->lastChanged());
	}

	/**
	 * The two clocks are one instant on a dashboard nobody has edited, but a read taken
	 * in the moment after a create can catch them either side of a second boundary, with
	 * `updated` BEHIND `created`. Believing it dates the mirror before the dashboard
	 * existed — and only when a write straddles a second, so it reads as flakiness.
	 */
	public function testAnUpdateTimeBeforeTheCreationTimeIsNotBelieved(): void {
		self::assertSame(1771000001, $this->spec(1771000000, 1771000001)->lastChanged());
	}

	public function testWithNeitherClockThereIsNothingToStamp(): void {
		self::assertNull($this->spec(null, null)->lastChanged());
	}

	#[DataProvider('unusableTimes')]
	public function testAnUnusableTimeIsNoTimeAtAll(mixed $raw, string $why): void {
		self::assertNull(DashboardSpec::parseTime($raw), $why);
	}

	/** @return iterable<string, array{mixed, string}> */
	public static function unusableTimes(): iterable {
		yield 'absent' => [null, 'a missing key is not a timestamp'];
		yield 'empty' => ['', 'Grafana leaves it empty until the update clock exists'];
		yield 'not a string' => [1771000000, 'the wire format is ISO-8601, never an int'];
		yield 'unparseable' => ['whenever', 'a schema change degrades to the old behaviour'];
		yield 'go zero time' => ['0001-01-01T00:00:00Z', "Grafana's unset time, which strtotime reads happily"];
		yield 'the epoch itself' => ['1970-01-01T00:00:00Z', 'indistinguishable from unset, and no dashboard is that old'];
	}

	public function testARealTimestampSurvivesTheGuard(): void {
		self::assertSame(strtotime('2026-08-15T04:22:19Z'), DashboardSpec::parseTime('2026-08-15T04:22:19Z'));
	}

	public function testTheVersionIsReadOffTheSpecAsAString(): void {
		self::assertSame('7', (new DashboardSpec((object)['version' => 7], null, null))->version());
		self::assertSame('', (new DashboardSpec((object)[], null, null))->version());
	}
}
