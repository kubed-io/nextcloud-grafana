<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Three-way name agreement: filename stem ⇄ JSON `title` ⇄ Grafana title.
 *
 * ── WHY EVERY STEP HERE DRAINS A JOB ─────────────────────────────────────────────
 *
 * `NameSyncListener` only *decides and enqueues*; the write runs in
 * `ReconcileNameJob`. That is not an optimisation — during a rename the file is
 * locked, and a synchronous `putContent` throws. So a rename is inherently deferred,
 * and a step that asserted immediately after the MOVE would be racing a job that had
 * not started. Draining `ReconcileNameJob` (after the push job, so Grafana already
 * has the new title) is what makes the assertion deterministic rather than flaky.
 *
 * The order matters and is the same order the app relies on in production.
 */
trait RenameSteps {
	private const JOB_PUSH = 'OCA\\GrafanaSync\\BackgroundJob\\PushDashboardJob';
	private const JOB_RENAME = 'OCA\\GrafanaSync\\BackgroundJob\\ReconcileNameJob';

	/** @When I rename the file to :filename */
	public function iRenameTheFileTo(string $filename): void {
		$dest = dirname($this->currentFilePath) . '/' . $filename;
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		$this->settleRename();
	}

	/** @When I rename the file */
	public function iRenameTheFile(): void {
		$this->iRenameTheFileTo('Renamed ' . bin2hex(random_bytes(3)) . '.grafana.json');
	}

	/** @When the file is renamed by any of the above means */
	public function theFileIsRenamedByAnyMeans(): void {
		// Exercise the filename→everywhere leg, the simpler of the two.
		$this->iRenameTheFileTo('Link Check ' . bin2hex(random_bytes(3)) . '.grafana.json');
	}

	/** @When I edit the file and change the JSON :field field to :value */
	public function iEditTheJsonField(string $field, string $value): void {
		// Object decode: this PUTs the whole body back, and an assoc round-trip would
		// rewrite the spec's empty `{}` objects as `[]`. Editing the title must not
		// reshape the rest of the dashboard. Same rule the production paths follow.
		$spec = json_decode($this->davGet($this->currentFilePath), false, 512, JSON_THROW_ON_ERROR);
		Assert::assertInstanceOf(\stdClass::class, $spec, 'the dashboard file is not a JSON object');
		$spec->{$field} = $value;
		$this->davPut($this->currentFilePath, json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$this->settleRename();
		// After a filename-from-title reconcile the file moved; follow it.
		$expected = dirname($this->currentFilePath) . '/' . $value . '.grafana.json';
		if ($this->davExists($expected)) {
			$this->currentFilePath = $expected;
		}
	}

	/** @Then the JSON :field field inside the file becomes :value */
	public function theJsonFieldBecomes(string $field, string $value): void {
		$spec = json_decode($this->davGet($this->currentFilePath), true, 512, JSON_THROW_ON_ERROR);
		Assert::assertIsArray($spec, 'the dashboard file is not JSON');
		Assert::assertSame($value, (string)($spec[$field] ?? ''), "the JSON $field did not become '$value'");
	}

	/** @Then the dashboard is renamed to :title in Grafana */
	public function theDashboardIsRenamedInGrafana(string $title): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		Assert::assertNotNull($record, "Grafana has no dashboard '{$this->lastUid}'");
		Assert::assertSame($title, $record['dashboard']['title'] ?? null, "the Grafana title is not '$title'");
	}

	/** @Then the file is renamed to :filename */
	public function theFileIsRenamedTo(string $filename): void {
		$expected = dirname($this->currentFilePath) . '/' . $filename;
		Assert::assertTrue($this->davExists($expected), "expected the file at $expected");
		$this->currentFilePath = $expected;
	}

	/** @Then the :key metadata is unchanged */
	public function theMetadataIsUnchanged(string $key): void {
		Assert::assertSame(
			$this->lastUid,
			$this->davReadMetadata($this->currentFilePath, $key),
			"the $key changed across the rename — the link broke",
		);
	}

	/** @Then the dashboard keeps its name in Grafana */
	public function theDashboardKeepsItsName(): void {
		Assert::assertNotNull($this->grafanaGetDashboard($this->lastUid), 'the dashboard is gone');
	}

	/** @Then the rename succeeds */
	public function theRenameSucceeds(): void {
		Assert::assertTrue($this->davExists($this->currentFilePath), 'the renamed file is not there');
	}

	/**
	 * Run the deferred half of a rename: the push first (so Grafana has the new
	 * title), then the file-locked reconcile.
	 */
	private function settleRename(): void {
		$this->drainJobs(self::JOB_PUSH);
		$this->drainJobs(self::JOB_RENAME);
	}
}
