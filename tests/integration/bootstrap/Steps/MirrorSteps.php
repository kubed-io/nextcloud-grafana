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
 * Alpha Demo.grafana.json appears"* and *"holds exactly 1 dashboard file"*. Where
 * "Alpha Demo" came from was invisible — it is written by `preload-grafana.sh`,
 * which a reader of the spec has no reason to know exists. And a count is the
 * weakest possible statement about a tree: it passes whatever the file is called
 * and wherever it sits.
 *
 * So the pre-state is declared (`the Grafana folder … already contains:`) and the
 * end state is the tree (`the mapped folder holds:`), the same shape
 * `kubed-io/nextcloud-penpot` settled on. Seeding is find-or-overwrite, so a
 * scenario may name a dashboard the preload already wrote and still read as the
 * pre-state it is.
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
	 * The Grafana side of the pre-state, as one table.
	 *
	 * @Given /^the Grafana folder "([^"]*)" already contains:$/
	 */
	public function theGrafanaFolderAlreadyContains(string $folderUid, TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$title = $row['dashboard'];
			$uid = $row['uid'];
			// A `tags` column seeds the dashboard WITH those tags. Without this branch
			// the column would be silently ignored, and a tag-import assertion would
			// pass against a dashboard that never carried a tag.
			$tags = trim((string)($row['tags'] ?? ''));
			if ($tags !== '') {
				$this->grafanaCreateTaggedDashboard($uid, $title, $folderUid, $this->parseTags($tags));
			} else {
				$this->grafanaCreateDashboard($uid, $title, $folderUid);
			}
			$this->seededDashboards[$title] = $uid;
		}
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
		$failures = [];
		foreach ($table->getRowsHash() as $property => $expected) {
			// `Modified` IS NOT A METADATA KEY, and it belongs in this table anyway: it
			// is state the file carries, read in the same glance as the rest. The times
			// are GRAFANA'S — a mirror wears the dashboard's clock, not the sync's — so
			// a rename that reaches Grafana must move it.
			if ($property === 'Modified') {
				$problem = $this->checkModifiedRow($path, $expected);
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
			: 'the file is dated ' . gmdate('c', (int)$mtime) . ', the dashboard changed at ' . gmdate('c', $updated);
	}

	/** One row. Returns a human sentence on failure, or null when it holds. */
	private function checkMetadataRow(string $path, string $property, string $expected, ?string $actual): ?string {
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
				$title = preg_replace('/\.grafana\.json$/', '', basename($path)) ?? '';
				$want = $this->seededDashboards[$title] ?? $this->lastUid;
				if ($want === '') {
					throw new \RuntimeException("no dashboard called '{$title}' was declared or created by this scenario");
				}
				return $actual === $want ? null : "expected the uid of '{$title}' ({$want}), found '{$actual}'";
			case 'its own, not the one it arrived with':
				// A MINT, NOT A RESTORE: the file carried an id in and the app decided it
				// was not usable, so the answer is a fresh one. Asserting "different from
				// what it arrived with" is what tells the two apart.
				if (($actual ?? '') === '') {
					return 'expected a uid of its own, found nothing';
				}
				return $actual !== $this->lastUid
					? null
					: "it reused the uid it arrived with ({$actual}), but this gesture should mint a new one";
			case 'the uid it had before it was trashed':
			case 'the uid it had before the rename':
			case 'the uid it had before the move':
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
				if ($this->lastUid === '') {
					throw new \RuntimeException('the arrange captured no uid to compare against');
				}
				return $actual === $this->lastUid
					? null
					: "expected the uid it already had ({$this->lastUid}), found '{$actual}'";
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
		foreach ($this->listMappings() as $m) {
			if ((string)($m['nc_folder'] ?? '') === $folder) {
				$mode = (string)($m['mode'] ?? '');
				return $mode === 'link' ? 'reference' : $mode;
			}
		}
		throw new \RuntimeException("no mapping owns the Nextcloud folder '{$folder}'");
	}

	private function mappingIdForNcFolder(string $folder): string {
		foreach ($this->listMappings() as $m) {
			if ((string)($m['nc_folder'] ?? '') === $folder) {
				return (string)($m['id'] ?? '');
			}
		}
		throw new \RuntimeException("no mapping owns the Nextcloud folder '{$folder}'");
	}
}
