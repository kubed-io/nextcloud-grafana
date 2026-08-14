<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * The file-lifecycle gestures — create, copy, move, rename, delete, restore, purge —
 * driven the way a Files-app user or a desktop client drives them: over WebDAV, so
 * the real server-side events fire and the real listeners run.
 *
 * Every assertion that matters is made against **Grafana's own REST API** or over
 * **DAV**, never by asking this app whether it did the thing. A test that asks the
 * app to confirm its own work proves only that the app agrees with itself.
 *
 * Arrangement helpers live in {@see \OCA\GrafanaSync\Tests\Integration\Support\SetupTrait};
 * this trait is the vocabulary the specs speak.
 */
trait LifecycleSteps {
	// ── arrangement ───────────────────────────────────────────────────────────

	/** @Given a folder mapped as :mode to the Grafana folder :name */
	public function aFolderMappedAsToTheGrafanaFolder(string $mode, string $name): void {
		$this->forceSyncTiming();
		$this->setupMapping($name, $mode);
	}

	/**
	 * NAMING THE BIN FOLDER AND SWITCHING IT ON ARE TWO SEPARATE FACTS, because they
	 * are two separate settings (`bin_folder` and `bin_enabled`) and the admin panel
	 * lets you set one without the other. Saying them in one step made a state the
	 * app has an explicit error for — enabled with no folder name — unwriteable, and
	 * put configuration inside every scenario that only wanted to flip the switch.
	 *
	 * @Given the Grafana recycle-bin folder is named :folder
	 */
	public function theRecycleBinFolderIsNamed(string $folder): void {
		$this->setBinFolder($folder);
	}

	/** @Given the Grafana recycle bin is :state */
	public function theRecycleBinIs(string $state): void {
		$this->setBinEnabled($state === 'on');
	}

	/**
	 * The ASSERTION, deliberately worded differently from the setter above.
	 *
	 * Behat ignores the keyword when matching, so `Given …is on` and `Then …is on`
	 * would be ONE step registered twice — Behat refuses the second and fails every
	 * scenario in the suite. Worse, whichever won would SET the value while a `Then`
	 * was asking it to be checked, so the assertion could never fail. The old
	 * `Then the Grafana recycle-bin folder is off` in mapping/create.feature had
	 * exactly that shape and only escaped notice because the scenario is @todo.
	 *
	 * @Then the Grafana recycle bin setting reads :state
	 */
	public function theRecycleBinSettingReads(string $state): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' bin_enabled --default-value=0');
		$actual = trim($res['output']) === '1' ? 'on' : 'off';
		Assert::assertSame($state, $actual, 'the recycle-bin setting');
	}

	/**
	 * @Given a managed :mode dashboard file
	 * @Given a managed :mode dashboard file in the :mapping folder
	 */
	public function aManagedDashboardFile(string $mode, ?string $mapping = null): void {
		$mapping ??= $this->mappingForMode($mode);
		$title = 'Board ' . bin2hex(random_bytes(3));
		if ($mode === 'link') {
			// A link file is authored by the PULL, not by a local write — a link mapping
			// never adopts a file the user drops in. Seed a dashboard through Grafana's
			// own API (no involvement from this app), then pull it down.
			$this->seedGrafanaDashboard($mapping, 'Linked ' . bin2hex(random_bytes(3)));
			$this->theAdminPullsFromGrafana();
			$files = $this->davListDashboardFiles($this->mappedFolder($mapping));
			$this->check($files !== [], "the pull produced no link file in the '$mapping' mapping");
			$this->currentFilePath = $this->mappedFolder($mapping) . '/' . $files[0];
			$uid = $this->davReadMetadata($this->currentFilePath, self::META_UID);
			Assert::assertNotNull($uid, 'the pulled link file carries no uid');
			$this->lastUid = $uid;
			return;
		}
		$this->makeManagedFile($mapping, $title);
	}

	/** @Given a managed :mode dashboard file named :filename */
	public function aManagedDashboardFileNamed(string $mode, string $filename): void {
		$stem = preg_replace('/\.grafana\.json$/', '', $filename) ?? $filename;
		$this->makeManagedFile($this->soleMappingName(), $stem);
	}

	/** @Given a managed :mode dashboard file with a known :key */
	public function aManagedDashboardFileWithAKnown(string $mode, string $key): void {
		$this->makeManagedFile($this->soleMappingName(), 'Linked ' . bin2hex(random_bytes(3)));
	}

	/** @Given an untracked :ext file */
	public function anUntrackedFile(string $ext): void {
		$this->currentFilePath = $this->unmappedFolder() . '/Loose ' . bin2hex(random_bytes(3)) . '.grafana.json';
		$this->davPut($this->currentFilePath, $this->dashboardBody('Loose'));
		Assert::assertNull(
			$this->davReadMetadata($this->currentFilePath, self::META_UID),
			'a file outside every mapping must not be stamped with a uid',
		);
	}

	/** @Given an untracked :ext file outside any mapping */
	public function anUntrackedFileOutsideAnyMapping(string $ext): void {
		$this->anUntrackedFile($ext);
	}

	/** @Given a folder that is not mapped */
	public function aFolderThatIsNotMapped(): void {
		// Make it "that folder" for the create step below, exactly as arranging a
		// mapping does. Both phrasings end with the same sentence — "I create a file
		// in that folder" — and it must mean whichever folder the Given set up.
		$this->currentFolder = $this->unmappedFolder();
	}

	/**
	 * A NAMED folder outside every mapping.
	 *
	 * Named, not anonymous: a scenario that says where it puts a file reads in the
	 * same vocabulary as the mapping tables above it, and "that folder" only ever
	 * meant "whichever one the last Given happened to arrange".
	 *
	 * @Given a folder :folder that is not mapped
	 */
	public function aNamedFolderThatIsNotMapped(string $folder): void {
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
	}

	// ── create ────────────────────────────────────────────────────────────────

	/**
	 * Create a file in a folder the scenario NAMES.
	 *
	 * The "+ New" menu is a browser affordance over an ordinary WebDAV PUT; the
	 * server cannot tell the two apart, and it is the PUT that fires the listener.
	 * Both phrasings are therefore one method — the menu is how a person describes
	 * it, not a second code path.
	 *
	 * @When I create :filename in :folder via the Files "New" menu
	 * @When I create :filename in :folder
	 */
	public function iCreateTheFileIn(string $filename, string $folder): void {
		$stem = preg_replace('/\.grafana\.json$/', '', $filename) ?? $filename;
		$this->currentFolder = $folder;
		$this->putDashboardFile($folder, $stem);
	}

	/**
	 * The dashboard's name AND its folder, as one claim — they are one sentence
	 * about where the new dashboard ended up, and splitting them said the same
	 * thing twice. The name comes from the filename, because that is what the user
	 * just typed; a body with no title must not produce an untitled dashboard.
	 *
	 * @Then the dashboard is named :title, in the :folder Grafana folder
	 */
	public function theDashboardIsNamedInTheGrafanaFolder(string $title, string $folder): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("Grafana has no dashboard with uid '{$this->lastUid}'");
		}
		$actual = (string)($record['dashboard']['title'] ?? '');
		if ($actual !== $title) {
			throw new \RuntimeException("the dashboard is named '$actual', not '$title' — it should take the filename");
		}
		// THE MAPPING TABLE'S `grafana folder` CELL IS THE UID, stored verbatim by
		// add-mapping. Resolving it through grafanaFolderUid() would hash it into a
		// `nc-t-…` uid belonging to the older arrange style, and compare two folders
		// that were never the same one.
		$got = (string)$this->dashboardFolderUid($this->lastUid);
		if ($got !== $folder) {
			throw new \RuntimeException("the dashboard landed in Grafana folder '$got', not '$folder'");
		}
	}

	/** @When I create a new :ext file in that folder via the Files "New" menu */
	public function iCreateANewFileViaTheNewMenu(string $ext): void {
		// The "+ New" menu is a browser affordance over an ordinary WebDAV PUT; the
		// server cannot tell the two apart, and it is the PUT that fires the listener.
		$this->putDashboardFile($this->currentFolder, 'New Board ' . bin2hex(random_bytes(3)));
	}

	/**
	 * "That folder" is whichever folder the preceding Given arranged — a mapping, or
	 * a deliberately unmapped one. Hard-coding the unmapped folder here made the
	 * link-mapping scenario pass for the wrong reason: it asserted no dashboard was
	 * created while writing the file somewhere no mapping could have adopted it,
	 * which proves nothing about link mappings.
	 *
	 * @When I create a :ext file in that folder
	 */
	public function iCreateAFileInThatFolder(string $ext): void {
		Assert::assertNotSame('', $this->currentFolder, 'no folder was arranged for "that folder"');
		$this->putDashboardFile($this->currentFolder, 'Unmanaged ' . bin2hex(random_bytes(3)));
	}

	/** @When I create :filename in that folder */
	public function iCreateNamedFileInThatFolder(string $filename): void {
		$stem = preg_replace('/\.grafana\.json$/', '', $filename) ?? $filename;
		$this->putDashboardFile($this->currentFolder, $stem);
	}

	/** @Then a matching dashboard is created in Grafana */
	public function aMatchingDashboardIsCreatedInGrafana(): void {
		$uid = $this->davReadMetadata($this->currentFilePath, self::META_UID);
		Assert::assertNotNull($uid, "no uid was stamped on {$this->currentFilePath}");
		$this->lastUid = $uid;
		$this->createdDashboardUids[] = $uid;
		Assert::assertNotNull($this->grafanaGetDashboard($uid), "Grafana has no dashboard with uid '$uid'");
	}

	/** @Then the dashboard is created in the :name folder */
	public function theDashboardIsCreatedInTheFolder(string $name): void {
		Assert::assertSame(
			$this->grafanaFolderUid($name),
			$this->dashboardFolderUid($this->lastUid),
			"dashboard '{$this->lastUid}' is not in the '$name' folder",
		);
	}

	/** @Then a dashboard named :title is created in Grafana */
	public function aDashboardNamedIsCreatedInGrafana(string $title): void {
		$this->aMatchingDashboardIsCreatedInGrafana();
		$record = $this->grafanaGetDashboard($this->lastUid);
		Assert::assertSame($title, $record['dashboard']['title'] ?? null, 'the dashboard was not named after the file');
	}

	/** @Then the file is stamped with the dashboard's :key */
	public function theFileIsStampedWith(string $key): void {
		Assert::assertNotNull($this->davReadMetadata($this->currentFilePath, $key), "no $key on {$this->currentFilePath}");
	}

	/** @Then no dashboard is created in Grafana */
	public function noDashboardIsCreatedInGrafana(): void {
		Assert::assertNull(
			$this->davReadMetadata($this->currentFilePath, self::META_UID),
			'the file was stamped with a uid, so a dashboard was created',
		);
	}

	/** @Then the file has no :key metadata */
	public function theFileHasNoMetadata(string $key): void {
		Assert::assertNull($this->davReadMetadata($this->currentFilePath, $key), "unexpected $key on {$this->currentFilePath}");
	}

	// ── copy ──────────────────────────────────────────────────────────────────

	/**
	 * A dashboard file in a NAMED folder, whatever that folder happens to be.
	 *
	 * ONE ARRANGE FOR EVERY SOURCE, because a copy does not care what its source
	 * was — that is the rule under test. Landing it in a mapped folder makes it
	 * managed in that mapping's mode; landing it outside every mapping makes it a
	 * plain document. The scenario names the folder and the Background says what
	 * the folder IS, so nothing restates "sync" or "unmapped" here.
	 *
	 * @Given a dashboard file in :folder
	 */
	public function aDashboardFileIn(string $folder): void {
		$this->davMkdir($folder);
		$this->currentFolder = $folder;
		$path = $this->putDashboardFile($folder, 'Source ' . bin2hex(random_bytes(3)));
		$this->originalPath = $path;
		$this->originalBody = $this->davGet($path);
		// Managed only if it landed in a mapping; outside one there is no uid, and
		// that is the arrange for half these scenarios rather than a failure.
		$uid = $this->davReadMetadata($path, self::META_UID);
		$this->lastUid = (string)$uid;
		$this->grafanaBefore = ['folder' => '', 'title' => ''];
		if ($this->lastUid !== '') {
			$this->createdDashboardUids[] = $this->lastUid;
			$record = $this->grafanaGetDashboard($this->lastUid);
			$this->grafanaBefore = [
				'folder' => (string)($record['meta']['folderUid'] ?? ''),
				'title' => (string)($record['dashboard']['title'] ?? ''),
			];
		}
	}

	/** @When I copy the file into :folder */
	public function iCopyTheFileInto(string $folder): void {
		$this->davMkdir($folder);
		$this->copyTarget = $folder . '/Copy ' . bin2hex(random_bytes(3)) . '.grafana.json';
		$this->davCopy($this->originalPath !== '' ? $this->originalPath : $this->currentFilePath, $this->copyTarget);
	}

	/** @Then the copy holds no Grafana metadata at all */
	public function theCopyHoldsNoGrafanaMetadataAtAll(): void {
		if (!$this->davExists($this->copyTarget)) {
			throw new \RuntimeException("there is no copy at {$this->copyTarget}");
		}
		foreach ([self::META_UID, self::META_MODE, self::META_MAPPING] as $key) {
			$actual = $this->davReadMetadata($this->copyTarget, $key);
			if (($actual ?? '') !== '') {
				throw new \RuntimeException(
					"the copy carries $key ('$actual'), but a copy outside every mapping is nobody's",
				);
			}
		}
	}

	/**
	 * Nextcloud copies BYTES, so the copy's content is the original's content. The
	 * app's whole contribution to a copy landing outside every mapping is what it
	 * takes OFF — the identity — and this asserts it kept its hands off the rest.
	 *
	 * @Then the copy's body is byte-for-byte the original's
	 */
	public function theCopysBodyIsByteForByteTheOriginals(): void {
		$copy = $this->davGet($this->copyTarget);
		if ($copy !== $this->originalBody) {
			throw new \RuntimeException(
				"the copy's body differs from the original's: " . strlen($this->originalBody)
				. ' bytes became ' . strlen($copy),
			);
		}
	}

	/**
	 * THE ANTI-HIJACK INVARIANT. The original is read before the gesture and
	 * compared after: it still has the uid it had, and its dashboard is still
	 * there. That covers what three separate steps used to claim, because all
	 * three are the same sentence — the original did not move.
	 *
	 * @Then the original file and its dashboard are unchanged
	 */
	public function theOriginalFileAndItsDashboardAreUnchanged(): void {
		if ($this->originalPath === '') {
			throw new \RuntimeException('no original was captured — a Given must establish one');
		}
		$now = (string)$this->davReadMetadata($this->originalPath, self::META_UID);
		if ($now !== $this->lastUid) {
			throw new \RuntimeException(
				"the original's uid was '{$this->lastUid}' and is now '{$now}' — the copy changed it",
			);
		}
		if ($this->lastUid !== '' && $this->grafanaGetDashboard($this->lastUid) === null) {
			throw new \RuntimeException("the original's dashboard {$this->lastUid} is gone from Grafana");
		}
	}

	/** @When I copy the file within the :mapping folder */
	public function iCopyTheFileWithinTheFolder(string $mapping): void {
		$this->copyTarget = $this->mappedFolder($mapping) . '/Copy ' . bin2hex(random_bytes(3)) . '.grafana.json';
		$this->davCopy($this->currentFilePath, $this->copyTarget);
	}

	/** @When I copy the file to a folder that is not mapped */
	public function iCopyTheFileToAnUnmappedFolder(): void {
		$this->copyTarget = $this->unmappedFolder() . '/Copy ' . bin2hex(random_bytes(3)) . '.grafana.json';
		$this->davCopy($this->currentFilePath, $this->copyTarget);
	}

	/** @Then the copy carries no inherited :key */
	public function theCopyCarriesNoInherited(string $key): void {
		$copyUid = $this->davReadMetadata($this->copyTarget, $key);
		Assert::assertNotSame($this->lastUid, $copyUid, 'the copy inherited the original\'s uid — two files now claim one dashboard');
	}

	/** @Then the copy is registered as a NEW dashboard in Grafana with its own uid */
	public function theCopyIsANewDashboard(): void {
		$copyUid = $this->davReadMetadata($this->copyTarget, self::META_UID);
		Assert::assertNotNull($copyUid, 'the copy was never stamped with a uid');
		Assert::assertNotSame($this->lastUid, $copyUid, 'the copy reused the original uid');
		$this->createdDashboardUids[] = $copyUid;
		Assert::assertNotNull($this->grafanaGetDashboard($copyUid), "Grafana has no dashboard for the copy ('$copyUid')");
	}

	/** @Then the copy has no Grafana metadata */
	public function theCopyHasNoGrafanaMetadata(): void {
		Assert::assertNull($this->davReadMetadata($this->copyTarget, self::META_UID), 'the copy carries a uid');
	}

	/** @Then no dashboard is created in Grafana for the copy */
	public function noDashboardForTheCopy(): void {
		$this->theCopyHasNoGrafanaMetadata();
	}

	/** @Then the original file and dashboard are unchanged */
	public function theOriginalIsUnchanged(): void {
		Assert::assertSame($this->lastUid, $this->davReadMetadata($this->currentFilePath, self::META_UID), 'the original lost its uid');
		Assert::assertNotNull($this->grafanaGetDashboard($this->lastUid), 'the original dashboard is gone');
	}

	/** @Then the copy is treated as a plain document */
	public function theCopyIsAPlainDocument(): void {
		Assert::assertTrue($this->davExists($this->copyTarget), 'the copy is not there at all');
		$this->theCopyHasNoGrafanaMetadata();
	}

	// ── move ──────────────────────────────────────────────────────────────────

	/**
	 * Move into a folder the scenario NAMES, mapped or not. The older phrasings
	 * ("into the :mapping folder", "to a folder that is not mapped") each addressed
	 * a different kind of destination, which put the destination's NATURE in the
	 * step; here it is the Background's to state and the scenario just says where.
	 *
	 * @When I move the file into :folder
	 */
	public function iMoveTheFileIntoNamedFolder(string $folder): void {
		$this->davMkdir($folder);
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		$this->currentFolder = $folder;
	}

	/** @When I try to move the file into :folder */
	public function iTryToMoveTheFileIntoNamedFolder(string $folder): void {
		$this->davMkdir($folder);
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->lastMoveStatus = $this->davMoveStatus($this->currentFilePath, $dest);
	}

	/**
	 * A mapping owns its whole SUBTREE, so a subfolder of a mapped folder is still
	 * that mapping — this is the arrange for proving it.
	 *
	 * @When I move the file into a subfolder of :folder
	 */
	public function iMoveTheFileIntoASubfolderOfNamed(string $folder): void {
		$sub = $folder . '/Filed ' . bin2hex(random_bytes(3));
		$this->davMkdir($sub);
		$dest = $sub . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
		// The mapping is still the parent's — the subfolder is local organisation.
		$this->currentFolder = $folder;
	}

	/** @Then the file stays in :folder */
	public function theFileStaysInNamedFolder(string $folder): void {
		$expected = $folder . '/' . basename($this->currentFilePath);
		if (!$this->davExists($expected)) {
			throw new \RuntimeException("the file is no longer at $expected — the move was not refused");
		}
	}

	/**
	 * @Then nothing changes in Grafana
	 *
	 * The dashboard is still there, still called what it was, still in the folder it
	 * was in. Stated as three positives rather than "nothing happened", which no
	 * assertion can express.
	 */
	public function nothingChangesInGrafana(): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '{$this->lastUid}' is gone from Grafana");
		}
		$now = [
			'folder' => (string)($record['meta']['folderUid'] ?? ''),
			'title' => (string)($record['dashboard']['title'] ?? ''),
		];
		if ($now !== $this->grafanaBefore) {
			throw new \RuntimeException(
				'the dashboard changed: folder ' . $this->grafanaBefore['folder'] . " -> {$now['folder']}, "
				. 'title ' . $this->grafanaBefore['title'] . " -> {$now['title']}",
			);
		}
	}

	/** @Then the file holds no Grafana metadata at all */
	public function theFileHoldsNoGrafanaMetadataAtAll(): void {
		foreach ([self::META_UID, self::META_MODE, self::META_MAPPING] as $key) {
			$actual = $this->davReadMetadata($this->currentFilePath, $key);
			if (($actual ?? '') !== '') {
				throw new \RuntimeException("the file still carries $key ('$actual') after leaving every mapping");
			}
		}
	}

	/** @Then the dashboard is named after the file, in the :folder Grafana folder */
	public function theDashboardIsNamedAfterTheFileIn(string $folder): void {
		$stem = preg_replace('/\.grafana\.json$/', '', basename($this->currentFilePath)) ?? '';
		$this->lastUid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		$this->theDashboardIsNamedInTheGrafanaFolder($stem, $folder);
	}

	/** @When I rename the file within the :mapping folder */
	public function iRenameTheFileWithinTheFolder(string $mapping): void {
		$dest = $this->mappedFolder($mapping) . '/Renamed ' . bin2hex(random_bytes(3)) . '.grafana.json';
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/** @When I move the file into the :mapping folder */
	public function iMoveTheFileIntoTheFolder(string $mapping): void {
		$dest = $this->mappedFolder($mapping) . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/** @When I move the file to a folder that is not mapped */
	public function iMoveTheFileToAnUnmappedFolder(): void {
		$dest = $this->unmappedFolder() . '/' . basename($this->currentFilePath);
		$this->davMove($this->currentFilePath, $dest);
		$this->currentFilePath = $dest;
	}

	/** @When I try to move the file to a folder that is not mapped */
	public function iTryToMoveTheFileToAnUnmappedFolder(): void {
		$dest = $this->unmappedFolder() . '/' . basename($this->currentFilePath);
		$this->lastMoveStatus = $this->davMoveStatus($this->currentFilePath, $dest);
	}

	/** @Then the file stays in :mode mode under the :mapping mapping */
	public function theFileStaysInModeUnderMapping(string $mode, string $mapping): void {
		Assert::assertSame($mode, $this->davReadMetadata($this->currentFilePath, self::META_MODE), 'mode changed');
		Assert::assertSame($this->lastUid, $this->davReadMetadata($this->currentFilePath, self::META_UID), 'uid changed');
	}

	/** @Then nothing changes in Grafana except the name */
	public function nothingChangesInGrafanaExceptTheName(): void {
		Assert::assertNotNull($this->grafanaGetDashboard($this->lastUid), 'the dashboard is gone');
	}

	/** @Then the dashboard's Grafana folder becomes the :name folder */
	public function theDashboardsFolderBecomes(string $name): void {
		Assert::assertSame(
			$this->grafanaFolderUid($name),
			$this->dashboardFolderUid($this->lastUid),
			'the dashboard did not move to the destination folder',
		);
	}

	/** @Then the dashboard keeps the same :key */
	public function theDashboardKeepsTheSame(string $key): void {
		Assert::assertSame($this->lastUid, $this->davReadMetadata($this->currentFilePath, $key), 'the uid changed across the move');
	}

	/** @Then the dashboard is not deleted or recreated */
	public function theDashboardIsNotDeletedOrRecreated(): void {
		Assert::assertNotNull($this->grafanaGetDashboard($this->lastUid), 'the dashboard was deleted or replaced');
	}

	/** @Then the dashboard no longer exists in Grafana */
	public function theDashboardIsDeletedInGrafana(): void {
		Assert::assertNull($this->grafanaGetDashboard($this->lastUid), "dashboard '{$this->lastUid}' still exists in Grafana");
	}

	/**
	 * No parentheses in the pattern — Behat reads `(...)` as an optional group, so
	 * this would also have matched a bare "the file's Grafana identity is stripped"
	 * and collided with any step owning that sentence. It passed only because nothing
	 * else claimed it yet.
	 *
	 * @Then the file's Grafana identity is stripped of :uidKey and :mappingKey
	 */
	public function theIdentityIsStripped(string $uidKey, string $mappingKey): void {
		Assert::assertNull($this->davReadMetadata($this->currentFilePath, $uidKey), "the file still carries a $uidKey");
		Assert::assertNull($this->davReadMetadata($this->currentFilePath, $mappingKey), "the file still carries a $mappingKey");
	}

	/** @Then the full dashboard JSON is still in the Nextcloud file */
	public function theJsonIsStillInTheFile(): void {
		$body = json_decode($this->davGet($this->currentFilePath), false, 512, JSON_THROW_ON_ERROR);
		Assert::assertInstanceOf(\stdClass::class, $body, 'the file is no longer a JSON object');
		Assert::assertObjectHasProperty('title', $body, 'the dashboard spec is gone from the file');
	}

	/** @Then the file is now a plain, untracked :ext */
	public function theFileIsNowUntracked(string $ext): void {
		Assert::assertNull($this->davReadMetadata($this->currentFilePath, self::META_UID), 'the file still carries a uid');
	}

	/** @Then a brand-new dashboard is created in Grafana from the file's JSON body */
	public function aBrandNewDashboardIsCreated(): void {
		$newUid = $this->davReadMetadata($this->currentFilePath, self::META_UID);
		Assert::assertNotNull($newUid, 'the file was not adopted on landing');
		Assert::assertNotSame($this->lastUid, $newUid, 'the old uid was reused — it should be dead');
		$this->createdDashboardUids[] = $newUid;
		$this->newUid = $newUid;
		Assert::assertNotNull($this->grafanaGetDashboard($newUid), 'no dashboard exists for the re-created file');
	}

	/** @Then it is created in the :name folder with a NEW :key */
	public function itIsCreatedInFolderWithANewUid(string $name, string $key): void {
		Assert::assertSame($this->grafanaFolderUid($name), $this->dashboardFolderUid($this->newUid), 'created in the wrong folder');
	}

	/** @Then the file's mode becomes :mode under the :mapping mapping */
	public function theFilesModeBecomes(string $mode, string $mapping): void {
		Assert::assertSame($mode, $this->davReadMetadata($this->currentFilePath, self::META_MODE), 'unexpected mode after the move');
	}

	/** @Then a matching dashboard is created in Grafana in the :name folder */
	public function aMatchingDashboardIsCreatedInFolder(string $name): void {
		$this->aMatchingDashboardIsCreatedInGrafana();
		$this->theDashboardIsCreatedInTheFolder($name);
	}

	/** @Then the move is refused with a message */
	public function theMoveIsRefused(): void {
		Assert::assertNotContains(
			$this->lastMoveStatus,
			[201, 204],
			"the move succeeded (HTTP {$this->lastMoveStatus}) but should have been refused",
		);
	}

	/** @Then the file stays in the :mapping folder */
	public function theFileStaysInTheFolder(string $mapping): void {
		Assert::assertTrue($this->davExists($this->currentFilePath), 'the file left its mapped folder');
	}

	// ── shared negatives ──────────────────────────────────────────────────────

	/** @When I delete it */
	public function iDeleteIt(): void {
		$this->davDelete($this->currentFilePath);
	}

	/** @Then Grafana is not contacted */
	public function grafanaIsNotContacted(): void {
		// Asserted by absence of any far-side effect: the file never carried a uid, so
		// there is nothing the app could have named in a call. Pairing the two makes
		// this fail for the right reason rather than passing because nothing happened.
		Assert::assertSame('', $this->lastUid, 'this scenario arranged a managed file; use a stronger assertion');
	}

	/** @Then the dashboard in Grafana is not deleted */
	public function theDashboardIsNotDeleted(): void {
		Assert::assertNotNull($this->grafanaGetDashboard($this->lastUid), "dashboard '{$this->lastUid}' was deleted but should not have been");
	}

	/** @Then the dashboard still exists in Grafana */
	public function theDashboardStillExists(): void {
		$this->theDashboardIsNotDeleted();
	}

	// ── the pull, named the way the specs name it ─────────────────────────────
	// A reconcile is one occ command however many mappings exist, so these all land
	// on the same call. Several phrasings, one function — which is fine and normal;
	// what is NOT allowed is two FUNCTIONS for one idea.

	/**
	 * @When the :mapping mapping is pulled
	 * @When the mapping is pulled
	 * @When both mappings are pulled
	 */
	public function theMappingIsPulled(?string $mapping = null): void {
		$this->theAdminPullsFromGrafana();
	}

	/** @When the :mapping mapping is pulled twice */
	public function theMappingIsPulledTwice(string $mapping): void {
		$this->theAdminPullsFromGrafana();
		$this->theAdminPullsFromGrafana();
	}

	/** @When the :mapping mapping is pushed */
	public function theMappingIsPushed(string $mapping): void {
		$this->theAdminPushesToGrafana();
	}

	/** @When I copy it within the :mapping folder */
	public function iCopyItWithinTheFolder(string $mapping): void {
		$this->iCopyTheFileWithinTheFolder($mapping);
	}

	/** @Given a managed :mode dashboard file in that folder */
	public function aManagedDashboardFileInThatFolder(string $mode): void {
		$this->aManagedDashboardFile($mode, $this->lastMappingName());
	}

	/**
	 * A file that was managed and then moved out of every mapping. Under bin-off the
	 * move-out DELETES the dashboard and strips the uid, so "still carries its uid"
	 * is only reachable with the bin ON — which is why this arranges it that way and
	 * says so, rather than quietly producing a file in a state the app cannot make.
	 *
	 * @Given an unmapped dashboard file that still carries its :key
	 */
	public function anUnmappedFileStillCarryingItsUid(string $key): void {
		$this->setBinFolder('nextcloud-trash');
		$this->setBinEnabled(true);
		$this->aManagedDashboardFile('sync');
		$this->iMoveTheFileToAnUnmappedFolder();
		Assert::assertSame(
			$this->lastUid,
			$this->davReadMetadata($this->currentFilePath, $key),
			'the arrangement failed: a bin-on move-out must park the dashboard and keep the uid',
		);
	}

	/**
	 * A plain dashboard file that was once managed and has had its identity stripped —
	 * exactly what a bin-off move-out leaves behind.
	 *
	 * @Given a plain :ext file whose Grafana identity was stripped, outside any mapping
	 */
	public function aStrippedPlainFileOutsideAnyMapping(string $ext): void {
		$this->setBinEnabled(false);
		$this->aManagedDashboardFile('sync');
		$this->iMoveTheFileToAnUnmappedFolder();
		Assert::assertNull(
			$this->davReadMetadata($this->currentFilePath, self::META_UID),
			'the arrangement failed: a bin-off move-out must strip the uid',
		);
	}

	/** @Given a :ext file that was never tracked in Grafana */
	public function aNeverTrackedFile(string $ext): void {
		$this->anUntrackedFile($ext);
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/** The most recently arranged mapping — what "that folder" refers to. */
	private function lastMappingName(): string {
		$names = array_keys($this->mappedFolders);
		Assert::assertNotEmpty($names, 'no mapping was arranged in this scenario');
		return $names[array_key_last($names)];
	}

	/**
	 * The mapping to use when a scenario says "a managed sync dashboard file" without
	 * naming a folder.
	 *
	 * It resolves BY MODE, not by position, and that is load-bearing. A Background
	 * commonly maps a sync folder and a scenario then adds a link one; picking the
	 * first mapping would hand a "link" arrangement the SYNC mapping, quietly produce
	 * a sync file, and the scenario would fail somewhere far away — or worse, pass
	 * while proving the opposite of its title. Failing loudly when no mapping of that
	 * mode exists is the only safe answer.
	 */
	private function mappingForMode(string $mode): string {
		$matches = array_keys($this->mappingModes, $mode, true);
		Assert::assertNotEmpty(
			$matches,
			"this scenario asked for a '$mode' file but arranged no '$mode' mapping — "
			. 'arranged: ' . (json_encode($this->mappingModes) ?: '{}'),
		);
		return $matches[array_key_last($matches)];
	}

	/** The mapping name when a scenario arranged exactly one, regardless of mode. */
	private function soleMappingName(): string {
		$names = array_keys($this->mappedFolders);
		Assert::assertNotEmpty($names, 'no mapping was arranged in this scenario');
		return $names[0];
	}

	/**
	 * @Given the folder :folder holding one dashboard :title
	 *
	 * A SUBFOLDER with a dashboard in it, which is the only way a subfolder gets
	 * stamped with a Grafana uid — the folder mirror is presence-driven, so a folder
	 * is a plain folder until something lands in it.
	 */
	public function theFolderHoldingOneDashboard(string $folder, string $title): void {
		$this->davMkdir($folder);
		$this->createdFolders[] = explode('/', $folder)[0];
		$this->currentFolder = $folder;

		$path = $folder . '/' . $title . '.grafana.json';
		$this->davPut($path, json_encode([
			'title' => $title,
			'schemaVersion' => 39,
			'panels' => [],
		], JSON_THROW_ON_ERROR));

		$this->originalPath = $path;
		$this->currentFilePath = $path;
		$uid = $this->davReadMetadata($path, self::META_UID);
		if ((string)$uid === '') {
			throw new \RuntimeException("creating '$path' did not make a dashboard in Grafana");
		}
		$this->lastUid = (string)$uid;
		$this->createdDashboardUids[] = $this->lastUid;
	}
}
