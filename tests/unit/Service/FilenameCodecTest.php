<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\FilenameCodec;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see FilenameCodec}. Pure string logic with zero Nextcloud
 * dependencies, so it runs in the standalone unit suite with nothing but PHP. The
 * uid-suffix round-trip and collision handling are what keep repeated pulls stable
 * instead of oscillating.
 */
#[CoversClass(FilenameCodec::class)]
final class FilenameCodecTest extends TestCase {
	public function testFormatCleanShapeOmitsUid(): void {
		$name = FilenameCodec::format('My Dashboard', 'af397c9y8enswf', false);
		self::assertSame('My Dashboard.grafana.json', $name);
	}

	public function testFormatUidSuffixedShapeEmbedsUid(): void {
		$name = FilenameCodec::format('My Dashboard', 'af397c9y8enswf', true);
		self::assertSame('My Dashboard.af397c9y8enswf.grafana.json', $name);
	}

	public function testFormatAddsCollisionSuffix(): void {
		$name = FilenameCodec::format('My Dashboard', 'af397c9y8enswf', false, 2);
		self::assertSame('My Dashboard (2).grafana.json', $name);
	}

	public function testFormatFallsBackToUidWhenNameSanitisesToEmpty(): void {
		// A name made entirely of control characters sanitises to "" (they are
		// stripped, not substituted) — we must never produce a bare ".grafana.json",
		// so the uid is used as the stem. (Banned path characters like "/" become "_"
		// and would NOT trigger this fallback.)
		$name = FilenameCodec::format("\x00\x01\x1f", 'af397c9y8enswf', false);
		self::assertSame('af397c9y8enswf.grafana.json', $name);
	}

	public function testFormatSanitisesUnsafeCharacters(): void {
		$name = FilenameCodec::format('a/b:c?d', 'af397c9y8enswf', false);
		self::assertSame('a_b_c_d.grafana.json', $name);
	}

	/**
	 * The headline property: a uid-suffixed filename round-trips back to the exact
	 * name + uid it was built from. This is what makes repeated pulls idempotent — the
	 * codec can recognise the file it wrote last time.
	 */
	#[DataProvider('roundTripNames')]
	public function testUidSuffixedRoundTrip(string $dashboardName, string $uid): void {
		$filename = FilenameCodec::format($dashboardName, $uid, true);
		$parsed = FilenameCodec::parse($filename);

		self::assertNotNull($parsed);
		self::assertSame($dashboardName, $parsed['name']);
		self::assertSame($uid, $parsed['uid']);
		self::assertSame(0, $parsed['suffix']);
	}

	/** @return iterable<string, array{string, string}> */
	public static function roundTripNames(): iterable {
		yield 'simple' => ['Daily Report', 'af397c9y8enswf'];
		yield 'name with dots' => ['v1.2 board', 'kel4vkt'];
		yield 'uid with dashes' => ['Backup', 'nc-alpha-demo'];
	}

	public function testParseCleanShapeHasNullUid(): void {
		$parsed = FilenameCodec::parse('My Dashboard.grafana.json');

		self::assertNotNull($parsed);
		self::assertSame('My Dashboard', $parsed['name']);
		self::assertNull($parsed['uid']);
		self::assertSame(0, $parsed['suffix']);
	}

	public function testParseExtractsCollisionSuffix(): void {
		$parsed = FilenameCodec::parse('My Dashboard (3).grafana.json');

		self::assertNotNull($parsed);
		self::assertSame('My Dashboard', $parsed['name']);
		self::assertSame(3, $parsed['suffix']);
	}

	public function testParseIgnoresLeadingPath(): void {
		$parsed = FilenameCodec::parse('/dashboards/observe/Daily Report.af397c9y8enswf.grafana.json');

		self::assertNotNull($parsed);
		self::assertSame('Daily Report', $parsed['name']);
		self::assertSame('af397c9y8enswf', $parsed['uid']);
	}

	#[DataProvider('nonMatchingBasenames')]
	public function testParseReturnsNullForNonGrafanaFiles(string $basename): void {
		self::assertNull(FilenameCodec::parse($basename));
	}

	/** @return iterable<string, array{string}> */
	public static function nonMatchingBasenames(): iterable {
		yield 'plain json' => ['notes.json'];
		yield 'wrong extension' => ['dashboard.grafana.txt'];
		yield 'bare extension only' => ['.grafana.json'];
		yield 'yaml cut (course 6, not this codec)' => ['board.grafana.yaml'];
		yield 'empty' => [''];
	}

	#[DataProvider('dashboardNameCases')]
	public function testIsDashboardName(string $name, bool $expected): void {
		self::assertSame($expected, FilenameCodec::isDashboardName($name));
	}

	/** @return iterable<string, array{string, bool}> */
	public static function dashboardNameCases(): iterable {
		yield 'clean shape' => ['My Dashboard.grafana.json', true];
		yield 'uid-suffixed shape' => ['My Dashboard.af397c9y8enswf.grafana.json', true];
		yield 'collision suffix' => ['My Dashboard (2).grafana.json', true];
		yield 'bare extension (empty stem)' => ['.grafana.json', false]; // agrees with parse(), which rejects an empty stem
		yield 'plain json' => ['notes.json', false];
		yield 'wrong extension' => ['dashboard.grafana.txt', false];
		yield 'yaml cut' => ['board.grafana.yaml', false];
		yield 'no extension' => ['dashboard', false];
		yield 'empty' => ['', false];
	}

	public function testIsDashboardFileTrueForManagedFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Daily Report.grafana.json');
		self::assertTrue(FilenameCodec::isDashboardFile($file));
	}

	public function testIsDashboardFileFalseForWrongExtension(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('notes.json');
		self::assertFalse(FilenameCodec::isDashboardFile($file));
	}

	public function testIsDashboardFileFalseForNonFileNode(): void {
		// A Folder is a Node but not a File — the predicate rejects it even if the name
		// happens to end in the extension (a folder named like a dashboard).
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn('weird.grafana.json');
		self::assertFalse(FilenameCodec::isDashboardFile($folder));
	}

	public function testIsDashboardFileFalseForNull(): void {
		self::assertFalse(FilenameCodec::isDashboardFile(null));
	}

	// ── Nextcloud's own collision spelling ─────────────────────────────────────

	/**
	 * THE BUG THIS CLASS COULD NOT SEE. Copying a dashboard beside its source makes
	 * NEXTCLOUD pick the name, and it counts before the last extension — so the file
	 * did not end in `.grafana.json` and every predicate answered "not ours". Measured
	 * live: no metadata, no dashboard in Grafana, and a body still carrying the
	 * ORIGINAL's uid, which is the most dangerous shape a stray copy can take.
	 */
	public function testNextcloudsCollisionNameIsStillOneOfOurs(): void {
		self::assertTrue(FilenameCodec::isDashboardName('ZZ-Smoke-Move.grafana (1).json'));
	}

	/** And it parses to the same logical name our own spelling would. */
	public function testBothCollisionSpellingsParseTheSame(): void {
		$theirs = FilenameCodec::parse('Board.grafana (2).json');
		$ours = FilenameCodec::parse('Board (2).grafana.json');

		self::assertNotNull($theirs);
		self::assertSame($ours, $theirs);
	}

	/** A name that merely contains a bracketed number is not a collision name. */
	public function testAParenthesisedNumberInTheNameIsNotACounter(): void {
		$parsed = FilenameCodec::parse('Cluster (eu-west-1).grafana.json');

		self::assertNotNull($parsed);
		self::assertSame('Cluster (eu-west-1)', $parsed['name']);
	}

	/** Not ours at all — the counter is there but the type is not. */
	public function testAnUnrelatedFileWithACounterIsNotOurs(): void {
		self::assertFalse(FilenameCodec::isDashboardName('Budget.xlsx (1).json'));
		self::assertFalse(FilenameCodec::isDashboardName('notes (1).json'));
	}
}
