<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * What Grafana holds going in, and what the mirror holds coming out — both as
 * tables.
 *
 * ## WHY
 *
 * A sync scenario used to name a magic filename and a count: *"a file named
 * Alpha Demo.grafana appears"* and *"holds exactly 1 dashboard file"*. Where
 * "Alpha Demo" came from was invisible — it is written by `preload-grafana.sh`,
 * which a reader of the spec has no reason to know exists. And a count is the
 * weakest possible statement about a tree: it passes whatever the file is called
 * and wherever it sits.
 *
 * So the pre-state is declared and the end state is the tree, the same shape
 * `kubed-io/nextcloud-penpot` settled on. The pre-state table that used to live
 * here — `the Grafana folder … already contains:` — could only ever describe ONE
 * FLAT folder, and is replaced by `Grafana holds these resources:`
 * ({@see ResourceSteps}), which describes a tree.
 *
 * ## AND THE METADATA, WHICH IS A POST-STATE
 *
 * Metadata is never a subject — it is what is true after the action, so it rides
 * the scenario that produced it. The vocabulary is closed on purpose: a table
 * that can say anything stops being readable.
 *
 *   the dashboard's uid   resolved from the file's own name. Presence is too weak
 *                         for an id — one that is merely non-empty could name any
 *                         dashboard, and naming THIS one is the point.
 *   the mapping's id      the mapping the scenario created.
 *   set                   present and non-empty. For opaque bookkeeping — a
 *                         Grafana version int and a body hash are the engine's,
 *                         and pinning either would assert its internals.
 *   absent                not stored at all.
 *   "<literal>"           an exact value, quoted. `link` reads as `link` here even
 *                         though the wire value is `reference`; that quirk is
 *                         spelt out once, in dashboards/view.feature.
 */
trait MirrorSteps {
	/** dashboard title => uid, as the scenario declared them. */
	private array $seededDashboards = [];

	/**
	 * @BeforeScenario
	 *
	 * NOW LOAD-BEARING, WHERE IT USED TO BE MERELY UNTIDY. This map only ever fed `the
	 * dashboard's uid`, which falls back to the cursor — so a leftover entry from an
	 * earlier scenario was unlikely to be consulted. It is now the FIRST answer for `the
	 * uid it had before …` and the roster `both dashboards are titled …` counts, and every
	 * `a dashboard file named X in Y` writes to it. Left uncleared, a second scenario
	 * arranging a file under a name an earlier one used would check its claims against a
	 * dashboard that no longer exists.
	 */
	public function resetSeededDashboards(): void {
		$this->seededDashboards = [];
	}

	/**
	 * The mirrored tree, as one assertion — every file the sync should have made,
	 * and nothing else.
	 *
	 * @Then /^the mapped folder "([^"]*)" holds:$/
	 */
	public function theMappedFolderHolds(string $folder, TableNode $table): void {
		$want = array_map(static fn (array $r): string => $r['file'], $table->getHash());
		sort($want);
		$got = $this->davListDashboardFiles($folder);
		sort($got);
		Assert::assertSame(
			$want,
			$got,
			"the mirror of '$folder' is not the tree the scenario describes.\n"
			. '  expected: ' . implode(', ', $want) . "\n"
			. '  actually: ' . implode(', ', $got),
		);
	}

	/**
	 * The same table, for the file the gesture just touched.
	 *
	 * A move or a rename decides the path itself, so a scenario cannot name one
	 * without restating the app's naming rules — and would then be asserting its own
	 * arithmetic rather than the app's behaviour.
	 *
	 * @Then the file holds:
	 */
	public function theFileHolds(TableNode $table): void {
		if ($this->currentFilePath === '') {
			throw new \RuntimeException('no file to inspect — a Given must arrange one');
		}
		$this->theMirrorHolds($this->currentFilePath, $table);
	}

	/**
	 * @Given the folder :folder holding no dashboards
	 *
	 * A plain Nextcloud folder. THE POINT IS WHAT IT LACKS: nothing lands in it, so
	 * nothing may stamp it — it is the control the pull must leave untouched while
	 * claiming the folders it does mirror.
	 */
	public function theFolderHoldingNoDashboards(string $folder): void {
		$this->davMkdir($folder);
	}

	/** @Then /^"([^"]*)" exists in Nextcloud$/ */
	public function existsInNextcloud(string $path): void {
		if (!$this->davExists($path)) {
			throw new \RuntimeException("'$path' does not exist in Nextcloud");
		}
	}

	/**
	 * @Then the file arrives in :folder, holding:
	 *
	 * ONE SENTENCE BECAUSE IT IS ONE IDEA — the file landed HERE, and it is still the
	 * same managed mirror. Split across two steps, a scenario could pass with the file
	 * in the right place and its identity stripped, which is the failure the
	 * in-Grafana move scenarios exist to catch.
	 *
	 * Delegates to {@see theMirrorHolds()} for the table, so the vocabulary stays in
	 * one place.
	 */
	public function theFileArrivesInHolding(string $folder, TableNode $table): void {
		$name = basename($this->currentFilePath);
		if (!in_array($name, $this->davListDashboardFiles($folder), true)) {
			throw new \RuntimeException("'$name' did not arrive in '$folder'");
		}
		$this->currentFilePath = $folder . '/' . $name;
		$this->theMirrorHolds($this->currentFilePath, $table);
	}

	/**
	 * @Then there is exactly one file for that dashboard
	 *
	 * Shared by `rename.feature` and `restore.feature` — three uses, one definition.
	 * Both are asking the same thing for the same reason: a gesture that re-creates
	 * rather than updates leaves the old mirror behind, and the giveaway is two files
	 * carrying one uid.
	 *
	 * Searches every folder the scenario has touched, because the whole point is to
	 * find a stray the scenario did not expect — looking only where the file should
	 * be would miss exactly the duplicate being hunted.
	 */
	public function thereIsExactlyOneFileForThatDashboard(): void {
		$found = [];
		foreach ($this->searchableFolders() as $folder) {
			foreach ($this->davListDashboardFiles($folder) as $name) {
				$path = $folder . '/' . $name;
				if ($this->davReadMetadata($path, self::META_UID) === $this->lastUid) {
					$found[] = $path;
				}
			}
		}
		if (count($found) !== 1) {
			throw new \RuntimeException(
				"expected exactly one file for dashboard '{$this->lastUid}', found "
				. count($found) . ($found === [] ? '' : ":\n  " . implode("\n  ", $found)),
			);
		}
	}

	/**
	 * Every folder this scenario could plausibly have left a mirror in.
	 *
	 * @return list<string>
	 */
	private function searchableFolders(): array {
		$folders = array_values($this->mappedFolders);
		foreach ([$this->currentFolder, $this->unmappedFolder] as $extra) {
			if ($extra !== '' && !in_array($extra, $folders, true)) {
				$folders[] = $extra;
			}
		}
		foreach ($this->createdFolders as $extra) {
			if (!in_array($extra, $folders, true)) {
				$folders[] = $extra;
			}
		}
		return $folders;
	}

	/**
	 * A mirror's metadata, as the end state of whatever just happened.
	 *
	 * @Then /^"([^"]*)" holds:$/
	 */
	public function theMirrorHolds(string $path, TableNode $table): void {
		// THE FILE THIS SCENARIO IS TALKING ABOUT, for whatever says `the file` next. A
		// gesture performed in GRAFANA cannot set that antecedent — nothing on the
		// Nextcloud side was touched by a hand — so `create.feature`'s "someone creates
		// the dashboard in Grafana" had to name the path here, and the very next line,
		// `the file holds "<contents>"`, read an empty cursor and reported the root
		// listing as a mirror that is not JSON.
		$this->currentFilePath = $path;

		$failures = [];
		foreach ($table->getRowsHash() as $property => $expected) {
			// `Modified` IS NOT A METADATA KEY, and it belongs in this table anyway: it
			// is state the file carries, read in the same glance as the rest. The times
			// are GRAFANA'S — a mirror wears the dashboard's clock, not the sync's — so
			// a rename that reaches Grafana must move it.
			// `Created` is the COPY'S OWN BIRTH. It belongs in this table for the same
			// reason `Modified` does — state the file carries, read in one glance — and
			// it earns its place on a copy especially: inheriting the original's date
			// would put the new dashboard's creation before it existed.
			if ($property === 'Created') {
				$problem = $this->checkCreatedRow($path, $expected);
				if ($problem !== null) {
					$failures[] = "  {$property}: {$problem}";
				}
				continue;
			}
			if ($property === 'Modified') {
				$problem = $this->checkModifiedRow($path, $expected);
				if ($problem !== null) {
					$failures[] = "  {$property}: {$problem}";
				}
				continue;
			}
			// THE THREE PLACES A NAME LIVES, side by side in one table on purpose. They
			// are supposed to be one value, and the only way to catch them disagreeing
			// is to read all three in the same glance — a copy shipped saying three
			// different things at once, and each of the three looked fine alone.
			if (in_array($property, ['filename', 'title in the file', 'title in Grafana'], true)) {
				$problem = $this->checkNameRow($path, $property, $expected);
				if ($problem !== null) {
					$failures[] = "  {$property}: {$problem}";
				}
				continue;
			}
			$actual = $this->davReadMetadata($path, $property);
			$problem = $this->checkMetadataRow($path, $property, $expected, $actual);
			if ($problem !== null) {
				$failures[] = "  {$property}: {$problem}";
			}
		}
		if ($failures !== []) {
			throw new \RuntimeException("'{$path}' is not in the state the scenario describes:\n" . implode("\n", $failures));
		}
	}

	/** The `Modified` row: the file's mtime against the dashboard's meta.updated. */
	/** The `Created` row: the file's creation time against the dashboard's meta.created. */
	private function checkCreatedRow(string $path, string $expected): ?string {
		if ($expected !== 'when the dashboard was created in Grafana') {
			return "unknown expectation '{$expected}'";
		}
		$uid = (string)$this->davReadMetadata($path, self::META_UID);
		if ($uid === '') {
			return 'the file carries no uid, so there is no dashboard to date it by';
		}
		$record = $this->grafanaGetDashboard($uid);
		if ($record === null) {
			return "dashboard '{$uid}' is gone from Grafana";
		}
		$created = strtotime((string)($record['meta']['created'] ?? ''));
		if (!is_int($created)) {
			return 'Grafana reported no meta.created to compare against';
		}
		$actual = $this->davReadTime($path, 'creationdate');
		return $actual === $created
			? null
			: 'the file was created ' . gmdate('c', (int)$actual) . ', the dashboard at ' . gmdate('c', $created)
				. $this->whenGrafanaWroteIt($uid);
	}

	private function checkModifiedRow(string $path, string $expected): ?string {
		if ($expected !== 'when the dashboard last changed in Grafana') {
			return "unknown expectation '{$expected}'";
		}
		$uid = (string)$this->davReadMetadata($path, self::META_UID);
		if ($uid === '') {
			return 'the file carries no uid, so there is no dashboard to date it by';
		}
		$record = $this->grafanaGetDashboard($uid);
		if ($record === null) {
			return "dashboard '{$uid}' is gone from Grafana";
		}
		$updated = strtotime((string)($record['meta']['updated'] ?? ''));
		if (!is_int($updated)) {
			return 'Grafana reported no meta.updated to compare against';
		}
		$mtime = $this->davReadTime($path, 'getlastmodified');
		return $mtime === $updated
			? null
			: 'the file is dated ' . gmdate('c', (int)$mtime) . ', the dashboard changed at ' . gmdate('c', $updated)
				. $this->whenGrafanaWroteIt($uid);
	}

	/**
	 * One of the three name rows. Always a quoted literal — a name is the one thing in
	 * this vocabulary a scenario really can spell out, and spelling it is the point.
	 *
	 * Every failure reports all three, because the interesting question is never "is
	 * this one wrong" but "which of the three disagreed with the other two", and that is
	 * unanswerable from a single value.
	 */
	private function checkNameRow(string $path, string $property, string $expected): ?string {
		if (!str_starts_with($expected, '"') || !str_ends_with($expected, '"')) {
			throw new \RuntimeException(
				"the table says {$property} is {$expected}; a name row takes a quoted literal.",
			);
		}
		$want = trim($expected, '"');
		// EVERY ARM NAMED, AND NO `default`. The caller decides which rows reach here, so
		// a `default` arm makes two lists that have to agree and silently reads the wrong
		// value the day they stop — a new row would be checked against Grafana's title
		// whatever it was supposed to mean, and the failure would blame the value rather
		// than the vocabulary. An unhandled row is a bug in this trait, so it says so.
		$actual = match ($property) {
			'filename' => basename($path),
			'title in the file' => $this->titleInTheFile($path),
			'title in Grafana' => $this->titleInGrafana($path),
			default => throw new \RuntimeException(
				"'{$property}' is routed to the name rows but has no reader — add one here.",
			),
		};
		return $actual === $want ? null : "expected '{$want}', found '{$actual}'" . $this->whatTheThreeNamesSay($path);
	}

	/** The `title` key of the file's own JSON — a sync spec and a link pointer both have one. */
	private function titleInTheFile(string $path): string {
		try {
			$spec = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return '<unreadable JSON: ' . $e->getMessage() . '>';
		}
		return ($spec instanceof \stdClass && isset($spec->title) && is_string($spec->title))
			? $spec->title
			: '<no title key>';
	}

	/** What Grafana calls the dashboard this file claims — found by uid, never by name. */
	private function titleInGrafana(string $path): string {
		$uid = (string)$this->davReadMetadata($path, self::META_UID);
		if ($uid === '') {
			return '<the file carries no uid, so it names no dashboard>';
		}
		$record = $this->grafanaGetDashboard($uid);
		return $record === null
			? "<dashboard '{$uid}' is gone from Grafana>"
			: (string)($record['dashboard']['title'] ?? '<no title>');
	}

	/** All three names as a trailing clause, so a failure shows which one broke ranks. */
	private function whatTheThreeNamesSay(string $path): string {
		return ' — the file is called ' . basename($path)
			. ', its JSON says ' . $this->titleInTheFile($path)
			. ', Grafana says ' . $this->titleInGrafana($path);
	}

	/**
	 * Everything Grafana knows about when a dashboard happened, as a trailing clause.
	 *
	 * BOTH of its clocks, not just the one being asserted: they are supposed to be the
	 * same instant on a dashboard nobody has edited, and a gap between them is itself
	 * the finding. Then the write history, because "the file and the dashboard
	 * disagree" has two causes that read identically — the stamp never happened, or a
	 * second write moved the clock after a correct stamp.
	 */
	private function whenGrafanaWroteIt(string $uid): string {
		$record = $this->grafanaGetDashboard($uid);
		$clock = static function (string $key) use ($record): string {
			$raw = (string)($record['meta'][$key] ?? '');
			$at = strtotime($raw);
			return $raw === '' ? 'none' : (is_int($at) ? gmdate('H:i:s', $at) : $raw);
		};
		$out = ' — Grafana says created ' . $clock('created') . ', updated ' . $clock('updated');
		$writes = $this->grafanaDashboardWrites($uid);
		return $writes === [] ? $out : $out . '; wrote it: ' . implode('; ', $writes);
	}

	/** One row. Returns a human sentence on failure, or null when it holds. */
	private function checkMetadataRow(string $path, string $property, string $expected, ?string $actual): ?string {
		// "the uid of the "Bubbles" Grafana folder" — compared against the uid GRAFANA
		// MINTED when the scenario's When created it, so the assertion cannot agree
		// with itself: the only way to know that uid is to have really created the
		// folder, and the only way to match it is for the pull to have really read it.
		if (preg_match('/^the uid of the "([^"]+)" Grafana folder$/', $expected, $m) === 1) {
			$want = $this->knownGrafanaFolders[$m[1]] ?? '';
			if ($want === '') {
				throw new \RuntimeException("no Grafana folder named '{$m[1]}' was created by this scenario");
			}
			return $actual === $want ? null : "expected the uid Grafana minted ({$want}), found '{$actual}'";
		}
		// The FOLDER twin of the uid-survival cases below: grafana_folder_uid was
		// captured by the arrange, and the claim is that the gesture did not touch it.
		//
		// DISPATCHED ON THE PROPERTY, not on the wording. "the uid it had before the
		// rename" is the same sentence whether it is asked of a dashboard or of the
		// folder holding it, and both are real questions in `folders/rename.feature` —
		// which asserts the folder kept its identity AND that nothing inside it was
		// re-minted. Keying only on the phrase meant the folder rows threw.
		if ($property === self::META_FOLDER_UID && str_starts_with($expected, 'the uid it had before')) {
			if ($this->lastFolderUid === '') {
				throw new \RuntimeException('the arrange captured no folder uid to compare against');
			}
			return $actual === $this->lastFolderUid
				? null
				: "expected the folder uid it already had ({$this->lastFolderUid}), found '{$actual}'";
		}

		// `link` is stored as `reference` — the literal string "link" is
		// is_callable(), which crashes core's PROPFIND. A table reads in the
		// vocabulary the admin chose; the wire value is view.feature's to explain.
		if ($property === self::META_MODE && $expected === '"link"') {
			$expected = '"reference"';
		}

		switch ($expected) {
			case "the dashboard's uid":
				// SEEDED FIRST, then the one this scenario just made. A pull scenario
				// declares its dashboards up front and the uid is knowable by title; a
				// create scenario has no title to look up, because the dashboard did not
				// exist until the gesture — there the uid the app stamped is the answer.
				$title = preg_replace('/\.grafana$/', '', basename($path)) ?? '';
				$want = $this->seededDashboards[$title] ?? $this->lastUid;
				if ($want === '') {
					throw new \RuntimeException("no dashboard called '{$title}' was declared or created by this scenario");
				}
				return $actual === $want ? null : "expected the uid of '{$title}' ({$want}), found '{$actual}'";
			case 'its own, not the one it arrived with':
				// A MINT, NOT A RESTORE: the file carried an id in and the app decided it
				// was not usable, so the answer is a fresh one. Asserting "different from
				// what it arrived with" is what tells the two apart.
				//
				// AGAINST `arrivedWithUid`, NOT `lastUid`. The uid the file arrived with is
				// gone from `lastUid` by the time this runs: `a matching dashboard is
				// created in Grafana` — the Then directly above this table in
				// `restore.feature` — reads the uid back off the file and stores it there.
				// So the comparison was the new uid against itself, always equal, and the
				// scenario failed no matter what the app did.
				if (($actual ?? '') === '') {
					return 'expected a uid of its own, found nothing';
				}
				if ($this->arrivedWithUid === '') {
					// LOUD, because the alternative is this claim quietly weakening to
					// `set` for any scenario whose arrange forgot to capture the uid.
					throw new \RuntimeException(
						"'{$expected}' needs the uid the file arrived with; no arrange step captured one",
					);
				}
				return $actual !== $this->arrivedWithUid
					? null
					: "it reused the uid it arrived with ({$actual}), but this gesture should mint a new one";
			case 'the uid of the renamed dashboard':
				// THE OTHER FILE, named by what happened to it rather than by its own name
				// — which it cannot be, because the whole point is that the app chose that
				// name and the scenario should not restate the app's arithmetic.
				if ($this->renamedUid === '') {
					throw new \RuntimeException("'{$expected}' needs a rename; no When performed one");
				}
				return $actual === $this->renamedUid
					? null
					: "expected the renamed dashboard's uid ({$this->renamedUid}), found '{$actual}'";
			case 'the uid it had before it was trashed':
			case 'the uid it had before the rename':
			case 'the uid it had before the move':
				// The same claim, made by a file coming BACK: it left with the recycle bin on,
				// so nothing was destroyed and nothing had to be forgotten. A returning file
				// that minted a fresh uid would mean the parking never preserved anything.
			case 'the uid it had before it left':
				// THE IDENTITY SURVIVED THE GESTURE. `set` would pass for any uid at all,
				// and the whole claim is that it is the SAME one — that the app moved a
				// thing rather than replacing it.
				if ($property !== self::META_UID) {
					// A folder's uid is not the dashboard uid the arrange captured. Fail
					// loudly rather than compare the wrong two values silently.
					throw new \RuntimeException(
						"'{$expected}' is only defined for " . self::META_UID . ", not {$property}",
					);
				}
				// THE UID THIS FILE'S OWN ARRANGE GAVE IT, falling back to the cursor. With
				// one dashboard on stage the two are the same value. With TWO — a rename
				// onto a name another dashboard already wears — `lastUid` is whichever was
				// arranged last, so the incumbent's claim to have kept its uid would be
				// checked against its neighbour's.
				$stem = preg_replace('/\.grafana$/', '', basename($path)) ?? '';
				$want = $this->seededDashboards[$stem] ?? $this->lastUid;
				if ($want === '') {
					throw new \RuntimeException('the arrange captured no uid to compare against');
				}
				return $actual === $want
					? null
					: "expected the uid it already had ({$want}), found '{$actual}'";
			case 'the uid the destination already had':
				// AN OVERWRITE REPLACES CONTENTS, NOT IDENTITY. The uid is the destination's
				// and the arrival inherits it, so this is the claim that one overwrite did
				// not quietly fork the mapping into two dashboards.
				//
				// Against the value the arrange pinned BEFORE the gesture, for the same
				// reason `its own, not the one it arrived with` is: the surviving file is
				// the one being read, so a baseline taken now would be compared with itself.
				if ($this->destinationUidBefore === '') {
					throw new \RuntimeException(
						"'{$expected}' needs the uid the destination held; no arrange step captured one",
					);
				}
				return $actual === $this->destinationUidBefore
					? null
					: "expected the uid the destination already had ({$this->destinationUidBefore}), found '{$actual}'";
			case "the mapping's id":
				$folder = trim(dirname($path), '/.');
				$want = $this->mappingIdForNcFolder($folder);
				return $actual === $want ? null : "expected the id of the mapping owning '{$folder}' ({$want}), found '{$actual}'";
			case "the mapping's mode":
				// `link` is stored as `reference`, so the folder answers this and the
				// scenario never has to know the wire value.
				$folder = trim(dirname($path), '/.');
				$want = $this->mappingModeForNcFolder($folder);
				return $actual === $want ? null : "expected the mode of the mapping owning '{$folder}' ({$want}), found '{$actual}'";
			case "its own, not the original's":
				// PRESENCE IS TOO WEAK HERE. A uid that is merely non-empty could be the
				// one it was copied from, and that is the entire anti-hijack claim.
				if (($actual ?? '') === '') {
					return 'expected a uid of its own, found nothing';
				}
				return $actual !== $this->lastUid
					? null
					: "it reused the original's uid ({$actual}) — two files would claim one dashboard";
			case 'set':
				return ($actual ?? '') !== '' ? null : 'expected a value, found nothing';
			case 'absent':
				return ($actual ?? '') === '' ? null : "expected it not to be stored, found '{$actual}'";
			default:
				// LITERALS ARE QUOTED, by the convention in this trait's docblock. An
				// unquoted value that reached here is a phrase the table vocabulary does
				// not implement — comparing it as a literal would assert the wrong thing
				// and report it as a value mismatch, which is how `the mapping's mode`
				// went unnoticed until it reached CI.
				if (!str_starts_with($expected, '"') || !str_ends_with($expected, '"')) {
					throw new \RuntimeException(
						"the table says '{$expected}', which is not a value this vocabulary knows."
						. ' Quote it to mean a literal, or add a case for it.',
					);
				}
				$literal = trim($expected, '"');
				return $actual === $literal ? null : "expected '{$literal}', found '{$actual}'";
		}
	}

	/**
	 * The mapping owning a Nextcloud folder, read from the live store rather than
	 * remembered at arrange time — a Background may declare several, and the file
	 * decides which one it belongs to by where it landed.
	 */
	/**
	 * The stored mode of the mapping owning a Nextcloud folder.
	 *
	 * `link` is stored as `reference` on the wire, so this returns what the file
	 * actually carries and a scenario never has to know the quirk.
	 */
	private function mappingModeForNcFolder(string $folder): string {
		$owner = $this->owningMappedFolder($folder);
		foreach ($this->listMappings() as $m) {
			if ((string)($m['nc_folder'] ?? '') === $owner) {
				$mode = (string)($m['mode'] ?? '');
				return $mode === 'link' ? 'reference' : $mode;
			}
		}
		throw new \RuntimeException("no mapping owns the Nextcloud folder '{$folder}'");
	}

	private function mappingIdForNcFolder(string $folder): string {
		$owner = $this->owningMappedFolder($folder);
		foreach ($this->listMappings() as $m) {
			if ((string)($m['nc_folder'] ?? '') === $owner) {
				return (string)($m['id'] ?? '');
			}
		}
		throw new \RuntimeException("no mapping owns the Nextcloud folder '{$folder}'");
	}

	/**
	 * The mapped folder that owns a path: itself, or the nearest ancestor a mapping
	 * names.
	 *
	 * A MAPPING OWNS EVERYTHING UNDER IT. Both lookups above matched the folder
	 * exactly, which held for as long as no scenario put a dashboard in a subfolder —
	 * and then answered "no mapping owns the Nextcloud folder 'Demo/Team'" about a
	 * file the `Demo` mapping had just mirrored there itself.
	 *
	 * Unmapped paths come back unchanged, so the callers' own failure still names the
	 * folder the scenario asked about rather than some ancestor of it.
	 */
	private function owningMappedFolder(string $folder): string {
		$folder = trim($folder, '/');
		$mapped = [];
		foreach ($this->listMappings() as $m) {
			$mapped[(string)($m['nc_folder'] ?? '')] = true;
		}
		for ($candidate = $folder; $candidate !== '' && $candidate !== '.';) {
			if (isset($mapped[$candidate])) {
				return $candidate;
			}
			$parent = dirname($candidate);
			$candidate = $parent === $candidate ? '' : trim($parent, '/.');
		}
		return $folder;
	}
}
