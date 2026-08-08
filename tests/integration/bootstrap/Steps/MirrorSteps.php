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
			$this->grafanaCreateDashboard($uid, $title, $folderUid);
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
	 * A mirror's metadata, as the end state of whatever just happened.
	 *
	 * @Then /^"([^"]*)" holds:$/
	 */
	public function theMirrorHolds(string $path, TableNode $table): void {
		$failures = [];
		foreach ($table->getRowsHash() as $property => $expected) {
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
				$title = preg_replace('/\.grafana\.json$/', '', basename($path)) ?? '';
				$want = $this->seededDashboards[$title]
					?? throw new \RuntimeException("no dashboard called '{$title}' was declared by this scenario");
				return $actual === $want ? null : "expected the uid of '{$title}' ({$want}), found '{$actual}'";
			case 'set':
				return ($actual ?? '') !== '' ? null : 'expected a value, found nothing';
			case 'absent':
				return ($actual ?? '') === '' ? null : "expected it not to be stored, found '{$actual}'";
			default:
				$literal = trim($expected, '"');
				return $actual === $literal ? null : "expected '{$literal}', found '{$actual}'";
		}
	}
}
