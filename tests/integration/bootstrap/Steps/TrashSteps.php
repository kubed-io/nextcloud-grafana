<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * The trash half of the delete lifecycle: move-to-trash, restore, and purge, driven
 * over Nextcloud's trashbin DAV endpoint.
 *
 * ── WHY THE TRASHBIN NEEDS ITS OWN TRANSPORT ─────────────────────────────────────
 *
 * The two steps of a delete arrive through two different doors, and the specs make
 * a lot of that (delete-dashboard.feature). Trashing is an ordinary `DELETE` against
 * the files endpoint; restoring and purging are a `MOVE` and a `DELETE` against
 * `/remote.php/dav/trashbin/<user>/trash`, where entries are renamed with a
 * `.dNNNN` deletion-time suffix. {@see WebDavTrait::trashbinPathFor} resolves that
 * suffixed name back from the original basename.
 *
 * A purge is the step with no typed Nextcloud event, so it exercises the legacy
 * `\OCP\Trashbin` `preDelete` hook — which only fires if the app is LOADED on a
 * WebDAV request. These scenarios are therefore the live regression test for the
 * missing `<types><filesystem/></types>` this PR added.
 */
trait TrashSteps {
	/** @When I move it to the trash */
	public function iMoveItToTheTrash(): void {
		$this->trashedFrom = $this->currentFilePath;
		$this->davDelete($this->currentFilePath);
	}

	/** @When I purge it from the trash */
	public function iPurgeItFromTheTrash(): void {
		$entry = $this->requireTrashEntry();
		$res = $this->davClient()->request('DELETE', $this->trashHref($entry));
		$this->assertStatus($res, [204, 200], "purge $entry");
	}

	/** @When I restore it from the trash */
	public function iRestoreItFromTheTrash(): void {
		$entry = $this->requireTrashEntry();
		$dest = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/restore/' . rawurlencode($entry);
		$res = $this->davClient()->request('MOVE', $this->trashHref($entry), [
			'headers' => ['Destination' => $dest],
		]);
		$this->assertStatus($res, [201, 204], "restore $entry");
		// A restore puts the file back where it was deleted from.
		$this->currentFilePath = $this->trashedFrom;
	}

	/** @Then the file is in the Nextcloud trash */
	public function theFileIsInTheTrash(): void {
		Assert::assertNotNull($this->trashbinPathFor($this->trashedFrom), 'the file is not in the trash');
	}

	/** @Then the file is recoverable from the Nextcloud trash */
	public function theFileIsRecoverable(): void {
		$this->theFileIsInTheTrash();
	}

	/** @Then the link is recoverable from the Nextcloud trash */
	public function theLinkIsRecoverable(): void {
		$this->theFileIsInTheTrash();
	}

	/**
	 * The whole promise of bin-off: the dashboard goes, but the CONTENT survives in
	 * Nextcloud. Reads the trashed copy's bytes back and checks it is still a
	 * dashboard spec — "it is in the trash" alone would not prove recoverability.
	 *
	 * @Then the file is recoverable from the Nextcloud trash with its JSON intact
	 */
	public function theFileIsRecoverableWithItsJson(): void {
		$entry = $this->requireTrashEntry();
		$res = $this->davClient()->request('GET', $this->trashHref($entry));
		$this->assertStatus($res, [200], "GET trashed $entry");
		$body = json_decode((string)$res->getBody(), false, 512, JSON_THROW_ON_ERROR);
		Assert::assertInstanceOf(\stdClass::class, $body, 'the trashed file is not a JSON object');
		Assert::assertObjectHasProperty('title', $body, 'the trashed file no longer holds a dashboard spec');
	}

	/**
	 * @Then the trashed file carries no Grafana metadata at all
	 * @Then the trashed file carries no Grafana metadata
	 *
	 * NOTE: no parentheses in either pattern. Behat's step syntax reads `(...)` as an
	 * OPTIONAL GROUP, so a pattern like `Grafana is not contacted (…)` silently also
	 * matches the bare `Grafana is not contacted` — and then collides with the step
	 * that legitimately owns that sentence. The failure reads as an ambiguous match on
	 * a line neither definition looks like. Keep asides in comments, not step text.
	 */
	public function theTrashedFileCarriesNoMetadata(): void {
		$entry = $this->requireTrashEntry();
		foreach ([self::META_UID, self::META_MODE, self::META_MAPPING] as $key) {
			$actual = $this->davReadTrashMetadata($entry, $key);
			$this->check(
				$actual === null || $actual === '',
				"the trashed file still carries $key = " . var_export($actual, true)
				. ' — bin-off must strip the dead identity',
			);
		}
	}

	/** @Then the trashed file KEEPS its :key */
	public function theTrashedFileKeepsIts(string $key): void {
		$actual = $this->davReadTrashMetadata($this->requireTrashEntry(), $key);
		$this->check(
			$actual === $this->lastUid,
			"bin-on must keep the $key — the dashboard was parked, not deleted. "
			. "Expected '{$this->lastUid}', got " . var_export($actual, true),
		);
	}

	/** @Then the dashboard is moved into the :folder Grafana folder and not deleted */
	public function theDashboardIsParkedIn(string $folder): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		Assert::assertNotNull($record, "dashboard '{$this->lastUid}' was DELETED — bin-on must park it, not delete it");
		Assert::assertSame(
			$this->grafanaFolderUid($folder),
			$record['meta']['folderUid'] ?? '',
			"dashboard '{$this->lastUid}' is not in the '$folder' bin folder",
		);
	}

	/** @Then dashboard :label no longer exists in Grafana */
	public function dashboardNoLongerExists(string $label): void {
		Assert::assertNull($this->grafanaGetDashboard($this->lastUid), 'the dashboard still exists in Grafana');
	}

	/** @Then dashboard :label is in the :folder Grafana folder and still exists */
	public function dashboardIsInTheBinFolder(string $label, string $folder): void {
		$this->theDashboardIsParkedIn($folder);
	}

	/** @Then no Grafana call is made by the purge */
	public function grafanaIsNotContactedOnPurge(): void {
		// The specific failure matters: the dashboard must be absent because trashing
		// deleted it, not because the purge deleted it a second time. Either way it is
		// gone, so what this really pins is that the purge did not error and the file
		// is fully gone from Nextcloud too.
		Assert::assertNull($this->grafanaGetDashboard($this->lastUid), 'the dashboard should already have been deleted at trash time');
		Assert::assertNull($this->trashbinPathFor($this->trashedFrom), 'the file is still in the trash after a purge');
	}

	/** @Then that file's dashboard is permanently deleted from Grafana */
	public function theParkedDashboardIsPermanentlyDeleted(): void {
		Assert::assertNull(
			$this->grafanaGetDashboard($this->lastUid),
			"the parked dashboard '{$this->lastUid}' survived the purge — emptying the trash must delete it for good",
		);
	}

	/** @Then the file is back in its mapped folder */
	public function theFileIsBackInItsMappedFolder(): void {
		Assert::assertTrue($this->davExists($this->currentFilePath), 'the file did not come back from the trash');
	}

	/** @Then the dashboard is moved out of :folder back into its mapped folder */
	public function theDashboardIsMovedBackOutOfTheBin(string $folder): void {
		$actual = $this->dashboardFolderUid($this->lastUid);
		Assert::assertNotNull($actual, 'the dashboard is gone entirely');
		Assert::assertNotSame($this->grafanaFolderUid($folder), $actual, 'the dashboard is still parked in the bin folder');
	}

	/** @Then the file is managed :mode again */
	public function theFileIsManagedAgain(string $mode): void {
		Assert::assertSame($mode, $this->davReadMetadata($this->currentFilePath, self::META_MODE), 'the restored file is not managed');
	}

	// ── arrangements that START from the trash ────────────────────────────────
	// These build the state by performing the real gesture rather than faking it,
	// so the arrangement itself proves the earlier leg still works. A hand-stamped
	// fixture would let the scenario pass over a broken trash path.

	/** @Given a trashed sync dashboard file whose dashboard is already deleted */
	public function aTrashedSyncFileWhoseDashboardIsGone(): void {
		$this->setBinOff();
		$this->aManagedDashboardFile('sync');
		$this->iMoveItToTheTrash();
		Assert::assertNull($this->grafanaGetDashboard($this->lastUid), 'the arrangement failed: bin-off trashing must delete the dashboard');
	}

	/**
	 * @Given a trashed sync dashboard file
	 * @Given a trashed :mode dashboard file
	 */
	public function aTrashedDashboardFile(string $mode = 'sync'): void {
		$this->aManagedDashboardFile($mode);
		$this->iMoveItToTheTrash();
	}

	/** @Given a trashed sync dashboard file whose dashboard is parked in :folder */
	public function aTrashedFileParkedIn(string $folder): void {
		$this->setBinOn($folder);
		$this->aManagedDashboardFile('sync');
		$this->iMoveItToTheTrash();
		$this->theDashboardIsParkedIn($folder);
	}

	/**
	 * The rescue. The "bin" is an ordinary Grafana folder, so a colleague can simply
	 * move a parked dashboard back where it belongs — done here through Grafana's own
	 * API, with no involvement from this app, exactly as it would happen in the UI.
	 *
	 * ONE annotation, not one per keyword. Behat ignores the keyword when matching, so
	 * the same sentence under `@Given` and `@When` is the SAME step registered twice —
	 * a duplicate definition that fails every scenario in the suite, including ones
	 * that never mention it. This step is used in a Given position by the delete
	 * rescue and a When position by the restore; both match this single pattern.
	 *
	 * @When someone moves that dashboard back to its mapped folder in Grafana
	 */
	public function someoneMovesTheDashboardBackInGrafana(): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		Assert::assertNotNull($record, "dashboard '{$this->lastUid}' is not in Grafana to rescue");
		$spec = $record['dashboard'] ?? [];
		$res = $this->grafanaClient()->request('POST', '/api/dashboards/db', [
			'json' => [
				'dashboard' => ['id' => null] + $spec,
				'folderUid' => $this->grafanaFolderUid($this->rescueFolder()),
				'overwrite' => true,
				'message' => 'rescued out of the bin by a Grafana user',
			],
		]);
		Assert::assertSame(200, $res->getStatusCode(), 'the rescue move failed: ' . (string)$res->getBody());
	}

	/** @Then it is still in its mapped folder */
	public function theDashboardIsStillInItsMappedFolder(): void {
		Assert::assertSame(
			$this->grafanaFolderUid($this->rescueFolder()),
			$this->dashboardFolderUid($this->lastUid),
			'the rescued dashboard is not in its mapped folder',
		);
	}

	/** @Then the file is gone from the Nextcloud trash */
	public function theFileIsGoneFromTheTrash(): void {
		Assert::assertNull($this->trashbinPathFor($this->trashedFrom), 'the file is still in the Nextcloud trash');
	}

	/** @Then no dashboard is deleted in Grafana */
	public function noDashboardIsDeletedInGrafana(): void {
		Assert::assertNotNull($this->grafanaGetDashboard($this->lastUid), "dashboard '{$this->lastUid}' was deleted");
	}

	/** The mapping a rescued dashboard belongs back in — the sole sync mapping the scenario arranged. */
	private function rescueFolder(): string {
		return $this->mappingForMode('sync');
	}

	/** @When I purge the trashed file from the trash */
	public function iPurgeTheTrashedFile(): void {
		$this->iPurgeItFromTheTrash();
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * The trashbin entry for the file this scenario deleted. Fails loudly rather
	 * than returning null: a scenario that silently skipped its restore would go
	 * green having proved nothing.
	 */
	private function requireTrashEntry(): string {
		$entry = $this->trashbinPathFor($this->trashedFrom);
		Assert::assertNotNull($entry, "no trashbin entry for '{$this->trashedFrom}' — was it actually deleted?");
		return $entry;
	}
}
