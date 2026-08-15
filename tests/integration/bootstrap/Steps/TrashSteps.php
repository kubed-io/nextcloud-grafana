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
		// SNAPSHOT BEFORE THE DELETE. Once the file is in the trash its original path
		// 404s, and davReadMetadata asserts a 207 — so any later read of the managed
		// keys fails as an opaque Registry type error rather than as an answer.
		$this->trashedMetadata = [];
		foreach ([self::META_UID, self::META_MODE, self::META_MAPPING] as $key) {
			$value = $this->davReadMetadata($this->currentFilePath, $key);
			if (($value ?? '') !== '') {
				$this->trashedMetadata[$key] = (string)$value;
			}
		}
		$this->davDelete($this->currentFilePath);
	}

	/**
	 * The bin is an ordinary Grafana folder, so a colleague can simply move a parked
	 * dashboard back — done through Grafana's own API, with no involvement from this
	 * app, exactly as it would happen in the UI.
	 *
	 * @Given its dashboard is back in the :title Grafana folder
	 */
	public function itsDashboardIsBackInTheGrafanaFolder(string $title): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '{$this->lastUid}' is not in Grafana to move");
		}
		$target = null;
		foreach ($this->grafanaListFolders() as $folder) {
			if ((string)($folder['title'] ?? '') === $title) {
				$target = (string)($folder['uid'] ?? '');
				break;
			}
		}
		if ($target === null) {
			throw new \RuntimeException("Grafana has no folder titled '$title'");
		}
		$res = $this->grafanaClient()->request('POST', '/api/dashboards/db', [
			'json' => [
				'dashboard' => ['id' => null] + ($record['dashboard'] ?? []),
				'folderUid' => $target,
				'overwrite' => true,
				'message' => "rescued out of the bin into '$title'",
			],
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException('the rescue move failed: ' . (string)$res->getBody());
		}
	}

	/** @Given its dashboard is gone from Grafana entirely */
	public function itsDashboardIsGoneFromGrafanaEntirely(): void {
		$this->grafanaDeleteDashboard($this->lastUid);
		if ($this->grafanaGetDashboard($this->lastUid) !== null) {
			throw new \RuntimeException("setup: dashboard '{$this->lastUid}' is still in Grafana");
		}
	}

	/**
	 * "Nothing was deleted" cannot be asserted directly, and the two ways to reach
	 * this state have different dashboards to compare — one alive, one already gone.
	 * So the claim is a pre/post comparison, captured as the purge is performed.
	 *
	 * @Then the dashboard is as it was before the purge
	 */
	public function theDashboardIsAsItWasBeforeThePurge(): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		$now = [
			'exists' => $record !== null,
			'folder' => (string)($record['meta']['folderUid'] ?? ''),
		];
		if ($now !== $this->grafanaBeforePurge) {
			throw new \RuntimeException(
				'the purge changed Grafana: was ' . json_encode($this->grafanaBeforePurge)
				. ', now ' . json_encode($now),
			);
		}
	}

	/** @When I purge it from the trash */
	public function iPurgeItFromTheTrash(): void {
		$record = $this->lastUid === '' ? null : $this->grafanaGetDashboard($this->lastUid);
		$this->grafanaBeforePurge = [
			'exists' => $record !== null,
			'folder' => (string)($record['meta']['folderUid'] ?? ''),
		];
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

	/**
	 * A GIVEN STATES WHAT IS TRUE, so it puts the file there rather than asserting
	 * someone else already did. Every use of this sentence is a pre-state; getting
	 * there requires a trash move, but that is the step's problem, not the
	 * scenario's.
	 *
	 * @Given the file is in the Nextcloud trash
	 */
	public function theFileIsInTheTrash(): void {
		$this->iMoveItToTheTrash();
		if ($this->trashbinPathFor($this->trashedFrom) === null) {
			throw new \RuntimeException('setup: the file is not in the Nextcloud trash');
		}
	}

	/**
	 * The far side of the same pre-state: trashing with the bin on parks the
	 * dashboard, and this names where it landed. One line per place.
	 *
	 * @Given its dashboard is parked in :folder
	 */
	public function itsDashboardIsParkedIn(string $folder): void {
		$this->theDashboardIsInTheGrafanaFolder($folder);
	}

	/**
	 * RECOVERABLE MEANS THE CONTENT SURVIVED, not merely that a row exists in the
	 * trash. A second step said the same thing "with its JSON intact" and did the
	 * real check — one claim in two wordings, the shorter being the weaker.
	 *
	 * @Then the file is recoverable from the Nextcloud trash
	 */
	public function theFileIsRecoverable(): void {
		$this->theFileIsRecoverableWithItsJson();
	}

	/**
	 * @Then it still holds no Grafana metadata
	 *
	 * The honest post-condition for trashing a file outside every mapping. It
	 * replaced "Grafana is not contacted", which asserted `lastUid === ''` — the
	 * ARRANGE, not the outcome, so it could never fail for the reason it claimed.
	 */
	public function itStillHoldsNoGrafanaMetadata(): void {
		$this->requireTrashEntry();
		if ($this->trashedMetadata !== []) {
			$pairs = [];
			foreach ($this->trashedMetadata as $key => $value) {
				$pairs[] = "$key='$value'";
			}
			throw new \RuntimeException(
				'the file the app does not manage carries ' . implode(', ', $pairs),
			);
		}
	}

	/** @Then the dashboard is still absent from Grafana */
	public function theDashboardIsStillAbsentFromGrafana(): void {
		if ($this->lastUid !== '' && $this->grafanaGetDashboard($this->lastUid) !== null) {
			throw new \RuntimeException(
				"dashboard '{$this->lastUid}' is back in Grafana — it went when the file was trashed",
			);
		}
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
	 * WHERE THE DASHBOARD IS, stated as a state rather than as the movement that
	 * put it there — a Then describes the world after, not the trip.
	 *
	 * Resolves the folder BY TITLE, which is the only thing that works for both
	 * kinds of folder a scenario names: a mapping's folder (whose uid the mapping
	 * table supplies verbatim) and the recycle bin (whose uid Grafana assigns when
	 * the app creates it from a configured name).
	 *
	 * @Then the dashboard is in the :title Grafana folder
	 */
	public function theDashboardIsInTheGrafanaFolder(string $title): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '{$this->lastUid}' no longer exists in Grafana");
		}
		// A SCENARIO-RECORDED UID FIRST, because `GET /api/folders` lists TOP-LEVEL
		// folders only — a nested one like "Drafts" is absent from it entirely, so the
		// scan below would report a perfectly healthy subfolder as missing. Steps that
		// locate a nested folder record what they found, and this reads that first.
		$want = $this->createdGrafanaFolders[$title] ?? null;
		if ($want === null) {
			foreach ($this->grafanaListFolders() as $folder) {
				if ((string)($folder['title'] ?? '') === $title) {
					$want = (string)($folder['uid'] ?? '');
					break;
				}
			}
		}
		if ($want === null) {
			throw new \RuntimeException("Grafana has no folder titled '$title'");
		}
		$got = (string)($record['meta']['folderUid'] ?? '');
		if ($got !== $want) {
			throw new \RuntimeException("the dashboard is in Grafana folder '$got', not '$title' ('$want')");
		}
	}

	/** @Then dashboard :label no longer exists in Grafana */
	public function dashboardNoLongerExists(string $label): void {
		Assert::assertNull($this->grafanaGetDashboard($this->lastUid), 'the dashboard still exists in Grafana');
	}

	/** @Then dashboard :label is in the :folder Grafana folder and still exists */
	public function dashboardIsInTheBinFolder(string $label, string $folder): void {
		$this->theDashboardIsInTheGrafanaFolder($folder);
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
		$this->setBinEnabled(false);
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
		$this->setBinFolder($folder);
		$this->setBinEnabled(true);
		$this->aManagedDashboardFile('sync');
		$this->iMoveItToTheTrash();
		$this->theDashboardIsInTheGrafanaFolder($folder);
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

	/**
	 * A Grafana folder no mapping points at.
	 *
	 * The MAPPED folders need no such Given — the Background's mapping tables name
	 * them, and the app provisions them. An unmapped one exists only because the
	 * scenario says so, exactly like the Nextcloud-side "Scratch".
	 *
	 * @Given a Grafana folder :title that is not mapped
	 */
	public function aGrafanaFolderThatIsNotMapped(string $title): void {
		// grafanaFolderUid creates it on demand and registers it for teardown.
		$this->grafanaFolderUid($title);
	}

	/**
	 * Move a dashboard in Grafana, WITH THE PULL FOLDED IN. The destination is named
	 * by title, so a mapped folder and an unmapped one are the same sentence — which
	 * is the point: the gesture is identical and only the end state differs.
	 *
	 * @When someone moves the dashboard into the :title Grafana folder
	 */
	public function someoneMovesTheDashboardIntoTheGrafanaFolder(string $title): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '{$this->lastUid}' is not in Grafana to move");
		}
		$target = null;
		foreach ($this->grafanaListFolders() as $folder) {
			if ((string)($folder['title'] ?? '') === $title) {
				$target = (string)($folder['uid'] ?? '');
				break;
			}
		}
		if ($target === null) {
			throw new \RuntimeException("Grafana has no folder titled '$title' to move into");
		}
		$res = $this->grafanaClient()->request('POST', '/api/dashboards/db', [
			'json' => [
				'dashboard' => ['id' => null] + ($record['dashboard'] ?? []),
				'folderUid' => $target,
				'overwrite' => true,
				'message' => "moved into '$title' by a Grafana user",
			],
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException('the Grafana move failed: ' . (string)$res->getBody());
		}
		$this->trashedFrom = $this->currentFilePath;
		$pull = $this->occ('grafana_sync:sync pull');
		if ($pull['exit'] !== 0) {
			throw new \RuntimeException("the pull after the Grafana move failed:\n{$pull['output']}");
		}
	}

	/**
	 * Deleting a dashboard in Grafana, WITH THE PULL FOLDED IN — nobody deletes a
	 * dashboard in order to run a sync.
	 *
	 * @When someone deletes the dashboard in Grafana
	 */
	public function someoneDeletesTheDashboardInGrafana(): void {
		if ($this->lastUid === '') {
			throw new \RuntimeException('no dashboard behind the file under test');
		}
		// The prune is a delete like any other, so Nextcloud's trash catches it — but
		// the trashbin lookup keys off the path the deleting step recorded, and here
		// the deleter is the reconciler rather than a gesture.
		$this->trashedFrom = $this->currentFilePath;
		$this->grafanaDeleteDashboard($this->lastUid);
		$res = $this->occ('grafana_sync:sync pull');
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("the pull after the Grafana delete failed:\n{$res['output']}");
		}
	}

	/**
	 * @When /^I move "([^"]*)" to the trash$/
	 *
	 * The NAMED twin of `I move it to the trash`, which follows the cursor. Both
	 * earn their place: the cursor form keeps a scenario from restating a path the
	 * app decided, and this one is for when the path IS the point — a specific file
	 * inside a folder, or the folder itself. It takes either kind of node, because to
	 * Nextcloud a delete is a delete, and `folders/delete.feature` says the same
	 * sentence about a folder.
	 *
	 * A REGEX REQUIRING QUOTES, NOT `:path`. Behat's placeholder matches a quoted
	 * string OR a bare token — so `:path` also matched the word **it**, shadowing the
	 * cursor step and breaking three scenarios that had passed for months by trying to
	 * delete a file named "it". The quotes are what make the two sentences distinct.
	 */
	public function iMoveToTheTrash(string $path): void {
		$this->trashedFrom = $path;
		$this->currentFilePath = $path;
		$this->davDelete($path);
	}

	/**
	 * @Then the Grafana folder :title is still under :parent
	 *
	 * Shared verbatim with `folders/delete.feature`. A folder outliving whatever left
	 * it is the same end state whether a dashboard was trashed or the last one moved
	 * out, so it is one sentence rather than two that differ by a clause.
	 */
	public function theGrafanaFolderIsStillUnder(string $title, string $parent): void {
		$parentUid = $this->grafanaFolderUidByTitle($parent);
		if ($parentUid === null) {
			throw new \RuntimeException("Grafana has no folder titled '$parent'");
		}
		// ASKED WITH parentUid, NOT FILTERED FROM THE FLAT LIST. Measured against a live
		// Grafana: `GET /api/folders` returns TOP-LEVEL folders only — a nested folder is
		// simply absent from it, so scanning that list for a child would report every
		// subfolder as missing no matter what Grafana holds.
		foreach ($this->grafanaChildFolders($parentUid) as $folder) {
			if ((string)($folder['title'] ?? '') === $title) {
				$this->lastGrafanaFolderUid = (string)($folder['uid'] ?? '');
				return;
			}
		}
		throw new \RuntimeException("Grafana has no folder '$title' under '$parent' — emptying is not deleting");
	}

	/** The folders directly under one parent. @return list<array<string,mixed>> */
	private function grafanaChildFolders(string $parentUid): array {
		$res = $this->grafanaClient()->request('GET', 'folders', [
			'query' => ['parentUid' => $parentUid, 'limit' => 1000],
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException(
				"listing Grafana folders under '$parentUid' failed: HTTP " . $res->getStatusCode(),
			);
		}
		$rows = json_decode((string)$res->getBody(), true);
		return is_array($rows) ? array_values($rows) : [];
	}

	/** @Then it holds no dashboards */
	public function itHoldsNoDashboards(): void {
		if ($this->lastGrafanaFolderUid === '') {
			throw new \RuntimeException('no Grafana folder in play — a previous Then must name one');
		}
		$found = $this->grafanaDashboardsInFolder($this->lastGrafanaFolderUid);
		if ($found !== []) {
			throw new \RuntimeException(
				'the Grafana folder still holds: ' . implode(', ', $found),
			);
		}
	}

	/**
	 * @Given :folder also holds dashboards Nextcloud never managed
	 *
	 * The bin is an ORDINARY Grafana folder anyone can put things in, which is the
	 * whole reason a purge must not clear it wholesale.
	 */
	public function alsoHoldsDashboardsNextcloudNeverManaged(string $folder): void {
		$uid = $this->grafanaFolderUidByTitle($folder);
		if ($uid === null) {
			throw new \RuntimeException("Grafana has no folder titled '$folder'");
		}
		$this->unmanagedInBin = [];
		foreach (['Stranger One', 'Stranger Two'] as $title) {
			$dashUid = 'nc-stranger-' . bin2hex(random_bytes(3));
			$this->grafanaCreateDashboard($dashUid, $title, $uid);
			$this->unmanagedInBin[] = $dashUid;
			$this->createdDashboardUids[] = $dashUid;
		}
	}

	/** @Then the dashboards Nextcloud never managed are still in :folder */
	public function theDashboardsNextcloudNeverManagedAreStillIn(string $folder): void {
		if ($this->unmanagedInBin === []) {
			throw new \RuntimeException('no unmanaged dashboards were arranged for this scenario');
		}
		$uid = $this->grafanaFolderUidByTitle($folder);
		$present = $uid === null ? [] : $this->grafanaDashboardsInFolder($uid);
		$missing = array_values(array_diff($this->unmanagedInBin, $present));
		if ($missing !== []) {
			throw new \RuntimeException(
				'the purge took dashboards it did not put there: ' . implode(', ', $missing),
			);
		}
	}

	/**
	 * A Grafana folder's dashboard uids, straight from Grafana.
	 *
	 * THE STATUS CHECK IS THE POINT. Without it a non-200 — a rejected token, a
	 * transient 500 — decodes to null, answers an empty list, and every assertion
	 * built on this passes: "it holds no dashboards" would be satisfied by a request
	 * that never succeeded, and "the strangers are still there" by one that never
	 * looked. Both are negative-ish assertions, which is exactly where a silent
	 * empty answer is indistinguishable from the thing being true.
	 *
	 * @return list<string>
	 */
	private function grafanaDashboardsInFolder(string $folderUid): array {
		$res = $this->grafanaClient()->request('GET', 'search', [
			'query' => ['type' => 'dash-db', 'folderUIDs' => $folderUid, 'limit' => 500],
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException(
				"searching Grafana folder '$folderUid' failed: HTTP " . $res->getStatusCode()
				. "\n" . (string)$res->getBody(),
			);
		}
		$rows = json_decode((string)$res->getBody(), true);
		$uids = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$uid = (string)($row['uid'] ?? '');
			if ($uid !== '') {
				$uids[] = $uid;
			}
		}
		return $uids;
	}

	private function grafanaFolderUidByTitle(string $title): ?string {
		foreach ($this->grafanaListFolders() as $folder) {
			if ((string)($folder['title'] ?? '') === $title) {
				return (string)($folder['uid'] ?? '');
			}
		}
		return null;
	}

	/** @Then the file is gone from :folder */
	public function theFileIsGoneFrom(string $folder): void {
		$name = basename($this->currentFilePath);
		if (in_array($name, $this->davListDashboardFiles($folder), true)) {
			throw new \RuntimeException("'$name' is still in '$folder' — its dashboard is gone from Grafana");
		}
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
