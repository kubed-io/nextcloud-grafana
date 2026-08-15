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
		self::assertSame('My Dashboard.grafana', $name);
	}

	public function testFormatUidSuffixedShapeEmbedsUid(): void {
		$name = FilenameCodec::format('My Dashboard', 'af397c9y8enswf', true);
		self::assertSame('My Dashboard.af397c9y8enswf.grafana', $name);
	}

	public function testFormatAddsCollisionSuffix(): void {
		$name = FilenameCodec::format('My Dashboard', 'af397c9y8enswf', false, 2);
		self::assertSame('My Dashboard (2).grafana', $name);
	}

	/**
	 * The counter goes LAST, after the uid — immediately before the extension, which is
	 * the only place Nextcloud ever puts one. Pinned as a literal because it is the rule
	 * the rest of this file's collision tests are derived from.
	 */
	public function testFormatPutsTheCollisionSuffixAfterTheUid(): void {
		$name = FilenameCodec::format('My Dashboard', 'af397c9y8enswf', true, 2);
		self::assertSame('My Dashboard.af397c9y8enswf (2).grafana', $name);
	}

	public function testFormatFallsBackToUidWhenNameSanitisesToEmpty(): void {
		// A name made entirely of control characters sanitises to "" (they are
		// stripped, not substituted) — we must never produce a bare ".grafana",
		// so the uid is used as the stem. (Banned path characters like "/" become "_"
		// and would NOT trigger this fallback.)
		$name = FilenameCodec::format("\x00\x01\x1f", 'af397c9y8enswf', false);
		self::assertSame('af397c9y8enswf.grafana', $name);
	}

	public function testFormatSanitisesUnsafeCharacters(): void {
		$name = FilenameCodec::format('a/b:c?d', 'af397c9y8enswf', false);
		self::assertSame('a_b_c_d.grafana', $name);
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
		$parsed = FilenameCodec::parse('My Dashboard.grafana');

		self::assertNotNull($parsed);
		self::assertSame('My Dashboard', $parsed['name']);
		self::assertNull($parsed['uid']);
		self::assertSame(0, $parsed['suffix']);
	}

	public function testParseExtractsCollisionSuffix(): void {
		$parsed = FilenameCodec::parse('My Dashboard (3).grafana');

		self::assertNotNull($parsed);
		self::assertSame('My Dashboard', $parsed['name']);
		self::assertSame(3, $parsed['suffix']);
	}

	public function testParseIgnoresLeadingPath(): void {
		$parsed = FilenameCodec::parse('/dashboards/observe/Daily Report.af397c9y8enswf.grafana');

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
		yield 'bare extension only' => ['.grafana'];
		yield 'the retired compound extension' => ['board.grafana.json'];
		yield 'empty' => [''];
	}

	#[DataProvider('dashboardNameCases')]
	public function testIsDashboardName(string $name, bool $expected): void {
		self::assertSame($expected, FilenameCodec::isDashboardName($name));
	}

	/** @return iterable<string, array{string, bool}> */
	public static function dashboardNameCases(): iterable {
		yield 'clean shape' => ['My Dashboard.grafana', true];
		yield 'uid-suffixed shape' => ['My Dashboard.af397c9y8enswf.grafana', true];
		yield 'collision suffix' => ['My Dashboard (2).grafana', true];
		yield 'bare extension (empty stem)' => ['.grafana', false]; // agrees with parse(), which rejects an empty stem
		yield 'plain json' => ['notes.json', false];
		yield 'wrong extension' => ['dashboard.grafana.txt', false];
		yield 'the retired compound extension' => ['board.grafana.json', false];
		yield 'no extension' => ['dashboard', false];
		yield 'empty' => ['', false];
	}

	public function testIsDashboardFileTrueForManagedFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Daily Report.grafana');
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
		$folder->method('getName')->willReturn('weird.grafana');
		self::assertFalse(FilenameCodec::isDashboardFile($folder));
	}

	public function testIsDashboardFileFalseForNull(): void {
		self::assertFalse(FilenameCodec::isDashboardFile(null));
	}

	// ── one collision spelling, shared with Nextcloud ──────────────────────────

	/**
	 * How Nextcloud names a file whose name is taken: `getUniqueName()` inserts the
	 * counter immediately before the LAST extension, counting from one. Modelled here
	 * rather than asserted against literals so the claim below is about the RULE — the
	 * tests are worthless if they only agree with names somebody typed out by hand.
	 */
	private static function asNextcloudWouldName(string $taken, int $n): string {
		$dot = strrpos($taken, '.');
		self::assertNotFalse($dot, "'$taken' has no extension for a counter to go before");
		return substr($taken, 0, $dot) . ' (' . $n . ')' . substr($taken, $dot);
	}

	/**
	 * THE WHOLE POINT OF THE SINGLE-SEGMENT EXTENSION: a copy is born with the name this
	 * codec would have chosen, so there is nothing to read around and nothing to rename.
	 *
	 * Under the compound `.grafana.json` the two disagreed — Nextcloud produced
	 * `Board.grafana (1).json`, which does not end in the extension, so every predicate
	 * here answered "not ours". Measured live: a copy with no metadata, no dashboard in
	 * Grafana, and a body still carrying the ORIGINAL's uid, which is the most dangerous
	 * shape a stray copy can take.
	 */
	#[DataProvider('collidingNames')]
	public function testNextcloudNamesACopyExactlyAsThisCodecWould(string $name, string $uid, bool $uidInFilename): void {
		$first = FilenameCodec::format($name, $uid, $uidInFilename);

		self::assertSame(
			FilenameCodec::format($name, $uid, $uidInFilename, 1),
			self::asNextcloudWouldName($first, 1),
			'the client and the codec must spell a collision the same way',
		);
	}

	/** @return iterable<string, array{string, string, bool}> */
	public static function collidingNames(): iterable {
		yield 'clean shape' => ['Fleet Health', 'af397c9y8enswf', false];
		yield 'uid-suffixed shape' => ['Board', 'af397c9y8enswf', true];
		yield 'a name containing a dot' => ['v1.2 board', 'kel4vkt', false];
	}

	/** And the name the client picked is one of ours, with the counter read off it. */
	public function testACopyTheClientNamedParsesStraightBack(): void {
		$copy = self::asNextcloudWouldName(FilenameCodec::format('Fleet Health', 'kel4vkt', false), 1);

		self::assertTrue(FilenameCodec::isDashboardName($copy));
		$parsed = FilenameCodec::parse($copy);
		self::assertNotNull($parsed);
		self::assertSame('Fleet Health', $parsed['name'], 'the logical name a pull matches on');
		self::assertSame('Fleet Health (1)', $parsed['display'], 'the name the user reads');
		self::assertSame(1, $parsed['suffix']);
	}

	/**
	 * THE UID-SUFFIXED SHAPE SURVIVES IT, and only because the counter is read off
	 * FIRST. The client appends it after the uid — there is nowhere else for it to go —
	 * so a parse that looked for the uid first would see `af397c9y8enswf (1)`, match
	 * nothing, and drop the identity on the very gesture most likely to need it.
	 */
	public function testACopiedUidSuffixedNameKeepsItsUid(): void {
		$copy = self::asNextcloudWouldName(FilenameCodec::format('Board', 'af397c9y8enswf', true), 1);

		$parsed = FilenameCodec::parse($copy);
		self::assertNotNull($parsed);
		self::assertSame('af397c9y8enswf', $parsed['uid']);
		self::assertSame('Board', $parsed['name']);
		self::assertSame('Board (1)', $parsed['display']);
	}

	/**
	 * THE RETIRED COMPOUND EXTENSION IS NOT OURS ANY MORE, and that is deliberate — but it
	 * is also the sharpest edge here, so it is pinned rather than left implied. A file
	 * still wearing the old extension is not half-managed: it is invisible, and the
	 * dashboard behind it is unreachable through this app forever.
	 */
	public function testTheRetiredCompoundExtensionIsNoLongerRecognised(): void {
		self::assertFalse(FilenameCodec::isDashboardName('Fleet Health.grafana.json'));
		self::assertNull(FilenameCodec::parse('Fleet Health.grafana.json'));
		self::assertFalse(FilenameCodec::isDashboardName('Fleet Health.grafana (1).json'));
	}

	/**
	 * A title round-trips through the filename unchanged.
	 *
	 * @param string $name
	 */
	#[DataProvider('safeNames')]
	public function testATitleSurvivesTheRoundTrip(string $name): void {
		$parsed = FilenameCodec::parse(FilenameCodec::format($name, 'af397c9y8enswf', false));

		self::assertNotNull($parsed, "'$name' did not parse back");
		self::assertSame($name, $parsed['name']);
	}

	/** @return iterable<string, array{string}> */
	public static function safeNames(): iterable {
		yield 'ordinary' => ['New Name'];
		yield 'a dot that is not a uid' => ['v1.2 board'];
		yield 'punctuation and unicode' => ['Latency — p99 · eu-west'];
		yield 'brackets that are not a counter' => ['Cluster (eu-west-1)'];
		yield 'a trailing number, unbracketed' => ['Latency 99'];
	}

	/**
	 * NAMES THE GRAMMAR CANNOT READ BACK — a known, unfixed ambiguity, pinned here so
	 * it is a documented limit rather than a surprise.
	 *
	 * The filename is the only carrier, and these titles are genuinely
	 * indistinguishable from the grammar's own markers: a dot is where {@see
	 * FilenameCodec::parse()} looks for a uid, `" (N)"` is how {@see
	 * FilenameCodec::format()} spells a collision, and `grafana` happens to satisfy
	 * `UID_RE` (7 alphanumerics) so even half the extension reads as an id.
	 *
	 * IT IS NOT COSMETIC. `NameSyncListener` keeps the filename, the JSON title and the
	 * Grafana title in agreement, so a title that parses back short reads as a rename
	 * the user never made — and the app renames their dashboard in Grafana to the
	 * truncated form. Asserting the WRONG value on purpose: this is what it does today,
	 * and the assertion flips the moment somebody fixes it.
	 *
	 * @param string $name
	 * @param string $readBackAs
	 */
	#[DataProvider('namesTheGrammarSwallows')]
	public function testAKnownAmbiguityTruncatesTheTitle(string $name, string $readBackAs): void {
		$parsed = FilenameCodec::parse(FilenameCodec::format($name, 'af397c9y8enswf', false));

		self::assertNotNull($parsed);
		self::assertSame($readBackAs, $parsed['name'], 'the known ambiguity changed shape');
	}

	/** @return iterable<string, array{string, string}> */
	public static function namesTheGrammarSwallows(): iterable {
		yield 'a real uid as the last segment' => ['Board.af397c9y8enswf', 'Board'];
		yield 'our own collision spelling' => ['Report (1)', 'Report'];
		yield 'half the extension' => ['Board.grafana', 'Board'];
	}

	/** A name that merely contains a bracketed number is not a collision name. */
	public function testAParenthesisedNumberInTheNameIsNotACounter(): void {
		$parsed = FilenameCodec::parse('Cluster (eu-west-1).grafana');

		self::assertNotNull($parsed);
		self::assertSame('Cluster (eu-west-1)', $parsed['name']);
	}

	// ── the two names a suffixed file has ──────────────────────────────────────

	/**
	 * `name` and `display` differ by exactly the counter, and callers want opposite
	 * ones: a pull matches on the LOGICAL name so a mirror already wearing `(1)` is
	 * recognised next time, while anything showing the user a name wants it as written.
	 */
	public function testASuffixedFileHasBothALogicalNameAndADisplayedOne(): void {
		$parsed = FilenameCodec::parse('Fleet Health (1).grafana');

		self::assertNotNull($parsed);
		self::assertSame('Fleet Health', $parsed['name'], 'the logical name a pull matches on');
		self::assertSame('Fleet Health (1)', $parsed['display'], 'the name the user reads');
		self::assertSame(1, $parsed['suffix']);
	}

	/** With no counter the two are the same string, so callers can use either safely. */
	public function testAnUnsuffixedFilesTwoNamesAreTheSame(): void {
		$parsed = FilenameCodec::parse('Fleet Health.grafana');

		self::assertNotNull($parsed);
		self::assertSame($parsed['name'], $parsed['display']);
	}

	/**
	 * THE ONE-LINER THAT STOPS THE MISTAKE. Reaching into `parse()` and taking `name`
	 * is how a dashboard copied in Nextcloud reached Grafana still wearing the
	 * ORIGINAL's title — the counter, which was the entire difference between the two
	 * names, had been stripped on the way past.
	 */
	public function testDisplayNameKeepsTheCounter(): void {
		self::assertSame('Fleet Health (1)', FilenameCodec::displayName('Fleet Health (1).grafana'));
		self::assertSame('Fleet Health', FilenameCodec::displayName('Fleet Health.grafana'));
	}

	/** Not one of ours — no name to display, and no exception either. */
	public function testDisplayNameOfSomethingElseIsEmpty(): void {
		self::assertSame('', FilenameCodec::displayName('Budget.xlsx'));
	}

	/** Not ours at all — the counter is there but the type is not. */
	public function testAnUnrelatedFileWithACounterIsNotOurs(): void {
		self::assertFalse(FilenameCodec::isDashboardName('Budget (1).xlsx'));
		self::assertFalse(FilenameCodec::isDashboardName('notes (1).json'));
	}
}
