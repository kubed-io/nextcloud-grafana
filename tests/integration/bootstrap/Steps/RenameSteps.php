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

	/**
	 * A dashboard file with a NAME the scenario chose, in a folder it names.
	 *
	 * @Given a dashboard file named :filename in :folder
	 */
	public function aDashboardFileNamedIn(string $filename, string $folder): void {
		$stem = preg_replace('/\.grafana\.json$/', '', $filename) ?? $filename;

		// A LINK MAPPING CANNOT BE WRITTEN INTO — that refusal is a shipped feature,
		// not an obstacle to work around. So the mirror is seeded the only way a link
		// mirror ever really appears: the dashboard is made in Grafana and pulled.
		if (($this->mappingModes[$folder] ?? '') === 'link') {
			$this->seedMirrorViaPull($folder, $stem);
			return;
		}

		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		// Same settling as the unnamed arrange — a uid-carrying body and a captured
		// pre-state — because a scenario that names its file is no less entitled to a
		// realistic one. `copy.feature` needs both: it can only spell the collision name
		// it expects if it chose the original's name, and it can only prove the original
		// survived if something read it first.
		$this->captureOriginal($this->putDashboardFile($folder, $stem), $stem);
	}

	/**
	 * Put a dashboard in the mapping's Grafana folder and pull it down, leaving the
	 * cursor on the mirror that arrived.
	 *
	 * Shared by every arrange that needs a file in a LINK folder. Kept here rather
	 * than duplicated per feature: the reason it exists — you cannot write into a
	 * link — is one rule, so it deserves one implementation.
	 */
	private function seedMirrorViaPull(string $folder, string $title): void {
		$grafanaFolder = $this->grafanaFolderUidForMapping($folder);
		$uid = 'nc-seed-' . bin2hex(random_bytes(3));
		$this->grafanaCreateDashboard($uid, $title, $grafanaFolder);
		$this->createdDashboardUids[] = $uid;
		$this->theAdminPullsFromGrafana();

		$this->lastUid = $uid;
		$this->currentFolder = $folder;
		foreach ($this->davListDashboardFiles($folder) as $name) {
			$path = $folder . '/' . $name;
			if ($this->davReadMetadata($path, self::META_UID) === $uid) {
				$this->currentFilePath = $path;
				$this->originalPath = $path;
				return;
			}
		}
		throw new \RuntimeException("the pull did not mirror '$title' into the link folder '$folder'");
	}

	/** The Grafana folder uid a mapping points at, found by its Nextcloud folder. */
	private function grafanaFolderUidForMapping(string $ncFolder): string {
		foreach ($this->listMappingsForSync() as $mapping) {
			if ((string)($mapping['nc_folder'] ?? '') === $ncFolder) {
				return (string)($mapping['grafana_folder_uid'] ?? '');
			}
		}
		throw new \RuntimeException("no mapping targets the Nextcloud folder '$ncFolder'");
	}

	/**
	 * The three places a name lives, as three assertions.
	 *
	 * They are one value, but each line names WHERE it is being checked, so a
	 * failure says which of the three drifted without the reader decoding a
	 * compound sentence. The filename check re-resolves first: a rename made in
	 * Grafana, or made by editing the title, moves the file out from under the
	 * path the scenario last knew.
	 *
	 * @Then the file is named :filename
	 */
	public function theFileIsNamed(string $filename): void {
		if (!$this->davExists($this->currentFilePath)) {
			$moved = $this->currentFolder . '/' . $filename;
			if ($this->davExists($moved)) {
				$this->currentFilePath = $moved;
			}
		}
		$actual = basename($this->currentFilePath);
		if ($actual !== $filename || !$this->davExists($this->currentFilePath)) {
			throw new \RuntimeException("expected a file named '$filename', found '$actual'");
		}
	}

	/** @Then the JSON title is :title */
	public function theJsonTitleIs(string $title): void {
		$spec = json_decode($this->davGet($this->currentFilePath), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($spec)) {
			throw new \RuntimeException("the file at {$this->currentFilePath} is not JSON");
		}
		$actual = (string)($spec['title'] ?? '');
		if ($actual !== $title) {
			throw new \RuntimeException("the JSON title is '$actual', not '$title'");
		}
	}

	/** @When I rename the file to :filename */
	public function iRenameTheFileTo(string $filename): void {
		$dest = dirname($this->currentFilePath) . '/' . $filename;
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		$this->settleRename();
	}

	/** @When I rename the file */
	public function iRenameTheFile(): void {
		$this->iRenameTheFileTo('Renamed ' . bin2hex(random_bytes(3)) . '.grafana');
	}

	/** @When the file is renamed by any of the above means */
	public function theFileIsRenamedByAnyMeans(): void {
		// Exercise the filename→everywhere leg, the simpler of the two.
		$this->iRenameTheFileTo('Link Check ' . bin2hex(random_bytes(3)) . '.grafana');
	}

	/**
	 * @When I edit the file and change the JSON :field field to :value
	 * @When I change the JSON :field field to :value
	 */
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
		$expected = dirname($this->currentFilePath) . '/' . $value . '.grafana';
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

	/**
	 * @Then the dashboard is named :title in Grafana
	 * @Then the dashboard is renamed to :title in Grafana
	 */
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

	/**
	 * @When someone renames the dashboard to :title in Grafana
	 *
	 * An empty title is a real case, not a guard to skip: `rename.feature` asserts the
	 * file falls back to the uid rather than inventing "Untitled", which would collide
	 * the moment a second nameless dashboard appeared.
	 */
	public function someoneRenamesTheDashboardToInGrafana(string $title): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '{$this->lastUid}' does not exist in Grafana");
		}
		$spec = $record['dashboard'] ?? [];
		$spec['title'] = $title;
		$body = json_encode([
			'dashboard' => $spec,
			'folderUid' => (string)($record['meta']['folderUid'] ?? ''),
			'overwrite' => true,
			'message' => 'integration rename-in-grafana',
		], JSON_THROW_ON_ERROR);
		$res = $this->grafanaClient()->request('POST', 'dashboards/db', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => $body,
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException('renaming in Grafana failed: ' . (string)$res->getBody());
		}
		$this->theAdminPullsFromGrafana();
	}

	/**
	 * @Then the file is named after the dashboard's uid
	 *
	 * The honest fallback for a nameless dashboard — reversible, and it cannot collide.
	 */
	public function theFileIsNamedAfterTheDashboardsUid(): void {
		$folder = $this->currentFolder !== '' ? $this->currentFolder : dirname($this->currentFilePath);
		foreach ($this->davListDashboardFiles($folder) as $name) {
			if ($this->davReadMetadata($folder . '/' . $name, self::META_UID) === $this->lastUid) {
				if ($name !== $this->lastUid . '.grafana') {
					throw new \RuntimeException(
						"expected the file to be named '{$this->lastUid}.grafana', found '$name'",
					);
				}
				$this->currentFilePath = $folder . '/' . $name;
				return;
			}
		}
		throw new \RuntimeException("no file in '$folder' carries dashboard '{$this->lastUid}'");
	}
}
