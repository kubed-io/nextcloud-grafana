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
	/**
	 * The name the app's own "New dashboard" menu entry gives a new file, and the
	 * `title` its starter body carries — one string, kept in step with
	 * `src/files.js`'s STARTER_DASHBOARD so the arrange is the real gesture rather
	 * than a lookalike.
	 */
	private const NEW_DASHBOARD_NAME = 'New dashboard';

	// ── arrangement ───────────────────────────────────────────────────────────

	/** @Given a folder mapped as :mode to the Grafana folder :name */
	public function aFolderMappedAsToTheGrafanaFolder(string $mode, string $name): void {
		$this->forceInlineWriteback();
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
		$stem = preg_replace('/\.grafana$/', '', $filename) ?? $filename;
		$this->makeManagedFile($this->soleMappingName(), $stem);
	}

	/** @Given a managed :mode dashboard file with a known :key */
	public function aManagedDashboardFileWithAKnown(string $mode, string $key): void {
		$this->makeManagedFile($this->soleMappingName(), 'Linked ' . bin2hex(random_bytes(3)));
	}

	/** @Given an untracked :ext file */
	public function anUntrackedFile(string $ext): void {
		$this->currentFilePath = $this->unmappedFolder() . '/Loose ' . bin2hex(random_bytes(3)) . '.grafana';
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
		$stem = preg_replace('/\.grafana$/', '', $filename) ?? $filename;
		$this->currentFolder = $folder;
		$this->putDashboardFile($folder, $stem);

		// CAPTURE THE UID THE APP MINTED. Creating is the one gesture where the
		// dashboard did not exist until now, so nothing else in the scenario can know
		// its id — and every later "the dashboard ..." assertion resolves through it.
		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		if ($uid !== '') {
			$this->lastUid = $uid;
			$this->createdDashboardUids[] = $uid;
		}
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

	/**
	 * THE APP'S OWN "New dashboard" GESTURE, which is what creating actually is.
	 *
	 * The user picks a folder and nothing else: `src/files.js` writes
	 * `New dashboard.grafana` (uniquified against the folder) holding the starter
	 * body, whose `title` is the same string. There is no name to type and no
	 * contents to supply — a file that arrives already named and full came from a
	 * move, a copy, or an edit, and those are different gestures with their own
	 * features.
	 *
	 * The "+ New" menu is a browser affordance over an ordinary WebDAV PUT; the
	 * server cannot tell the two apart, and it is the PUT that fires the listener.
	 *
	 * @When I create a new dashboard in :folder via the Files "New" menu
	 */
	public function iCreateANewDashboardIn(string $folder): void {
		$this->currentFolder = $folder;
		$this->putDashboardFile($folder, self::NEW_DASHBOARD_NAME);

		// CAPTURE THE UID THE APP MINTED. Creating is the one gesture where the
		// dashboard did not exist until now, so nothing else in the scenario can know
		// its id — and every later "the dashboard ..." assertion resolves through it.
		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		if ($uid !== '') {
			$this->lastUid = $uid;
			$this->createdDashboardUids[] = $uid;
		}
	}

	/**
	 * The same gesture, asked of a folder that must refuse it.
	 *
	 * @When I try to create a new dashboard in :folder via the Files "New" menu
	 */
	public function iTryToCreateANewDashboardIn(string $folder): void {
		$this->currentFolder = $folder;
		$this->attemptedCreatePath = $folder . '/' . self::NEW_DASHBOARD_NAME . '.grafana';
		$this->lastCreateStatus = $this->davPutStatus(
			$this->attemptedCreatePath,
			$this->dashboardBody(self::NEW_DASHBOARD_NAME),
		);
	}

	/**
	 * @Then the creation is refused with a message
	 *
	 * Both halves, because a guard that answers 403 after the bytes have landed is
	 * the failure this rule exists to prevent: a `.grafana` sitting in a link folder
	 * looking managed, and never being.
	 */
	public function theCreationIsRefused(): void {
		Assert::assertFalse(
			in_array($this->lastCreateStatus, [201, 204], true),
			"the file was created (HTTP {$this->lastCreateStatus}) but should have been refused",
		);
		Assert::assertFalse(
			$this->davExists($this->attemptedCreatePath),
			"a file arrived at {$this->attemptedCreatePath} despite the refusal",
		);
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
		$stem = preg_replace('/\.grafana$/', '', $filename) ?? $filename;
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
		// ONE IMPLEMENTATION, and it is the named one. This step used to carry its own
		// copy, and the two drifted: the named arrange skipped the pre-state capture on
		// a link, so which arrange a scenario happened to use decided whether "the
		// original is unchanged" compared against anything at all.
		$this->aDashboardFileNamedIn('Source ' . bin2hex(random_bytes(3)) . '.grafana', $folder);
	}

	/**
	 * Bring a freshly-written dashboard file to the state a real sync leaves it in, and
	 * record the pre-state every "the original is unchanged" claim compares against.
	 *
	 * A REAL MIRROR CARRIES ITS UID INSIDE THE FILE, and these arranges used to leave a
	 * hand-written body that never did. A dashboard spec has a `uid` key, so the moment
	 * a sync writes the file the uid is in there — and an upsert keys on the body's uid.
	 * Seeding the tidier fixture meant a copy could only ever be tested against a body
	 * that could not hijack anything, and the hijack it was meant to catch shipped.
	 *
	 * Shared by both dashboard-file arranges rather than living in one of them: which
	 * arrange a scenario happens to use should never decide whether its fixture is
	 * realistic.
	 */
	private function captureOriginal(string $path, string $title): void {
		$this->originalPath = $path;
		// Managed only if it landed in a mapping; outside one there is no uid, and
		// that is the arrange for half these scenarios rather than a failure.
		$this->lastUid = (string)$this->davReadMetadata($path, self::META_UID);
		if ($this->lastUid !== '') {
			$this->davPut($path, $this->dashboardBody($title, $this->lastUid));
		}
		$this->originalBody = $this->davGet($path);
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

	/**
	 * @When I copy the file into :folder
	 *
	 * THE COPY KEEPS ITS OWN NAME, and that is the whole gesture. This step used to
	 * invent a fresh random name for the destination, which meant the suite copied a
	 * dashboard into the folder it was already in and NEVER ONCE COLLIDED — so every
	 * question about what a colliding copy is called went unasked, and the answers
	 * shipped wrong in all three places at once.
	 *
	 * Nextcloud's own collision name is computed here rather than left to the server,
	 * because the server does not compute one: WebDAV COPY onto an existing path is a
	 * 412, full stop. It is the FILES CLIENT that picks a free name and then copies to
	 * it — `getUniqueName()` from `@nextcloud/files`, which counts from 1 and inserts
	 * the counter before the LAST extension. With a single-segment extension that is
	 * `Fleet Health (1).grafana`, the same name `FilenameCodec::format()` builds. It
	 * was `Fleet Health.grafana (1).json` under the retired compound extension, which
	 * is what the app had to read around. Confirmed against the live instance, which
	 * is where the shape in these scenarios came from.
	 *
	 * So the suite plays the client. Emulating it is not a shortcut around the real
	 * behaviour — it IS the real behaviour, and the app's job starts the moment that
	 * name lands.
	 */
	public function iCopyTheFileInto(string $folder): void {
		$this->davMkdir($folder);
		$before = $this->davListDashboardFiles($folder);
		$source = $this->originalPath !== '' ? $this->originalPath : $this->currentFilePath;
		$this->davCopy($source, $this->filesClientCopyName($folder, basename($source)));
		$this->settleCopy();
		$this->copyTarget = $this->theOneNewDashboardFileIn($folder, $before);
	}

	/**
	 * The single dashboard file that appeared in $folder, given what was there before.
	 *
	 * FOUND AFTERWARDS, NOT ASSUMED. Diffing the folder finds the copy wherever it
	 * landed, so the step never has to know the naming rule the scenario is there to
	 * check — which is the point: an assertion that computes the name it expects from
	 * the same rule the app used cannot fail when the rule is wrong. It was also load-
	 * bearing under the compound extension, where the app renamed a copy after the fact
	 * and the path the COPY was made at was not the path it ended up at.
	 *
	 * Insisting on EXACTLY ONE is the point: a copy that somehow produced two files, or
	 * none, fails here with what the folder actually holds rather than further down as a
	 * confusing 404.
	 *
	 * @param list<string> $before
	 */
	private function theOneNewDashboardFileIn(string $folder, array $before): string {
		$now = $this->davListDashboardFiles($folder);
		$new = array_values(array_diff($now, $before));
		if (count($new) !== 1) {
			throw new \RuntimeException(
				"expected exactly one new dashboard file in '$folder', found " . count($new)
				. ".\n  before: " . implode(', ', $before)
				. "\n  after:  " . implode(', ', $now),
			);
		}
		return $folder . '/' . $new[0];
	}

	/**
	 * The destination the Files app would COPY to: the source's own name, with the
	 * client's ` (N)` counter before the last extension if that name is taken.
	 */
	private function filesClientCopyName(string $folder, string $basename): string {
		$ext = strrchr($basename, '.');
		$stem = $ext === false ? $basename : substr($basename, 0, -strlen($ext));
		$ext = $ext === false ? '' : $ext;

		$candidate = $basename;
		for ($n = 1; $this->davExists($folder . '/' . $candidate); $n++) {
			$candidate = $stem . ' (' . $n . ')' . $ext;
			if ($n > 100) {
				throw new \RuntimeException("no free name for '$basename' in '$folder'");
			}
		}
		return $folder . '/' . $candidate;
	}

	/**
	 * Run the deferred half of a copy.
	 *
	 * A copy's own hook holds locks on the file it just made, so the app cannot rename
	 * that file or rewrite its JSON inside the request — it hands both to
	 * {@see \OCA\GrafanaSync\BackgroundJob\ReconcileNameJob}, exactly as a rename does.
	 * Draining the queue here is what lets the scenario assert the END state rather than
	 * a half-finished one; the deferral itself is `rename.feature`'s subject, not this
	 * file's.
	 */
	private function settleCopy(): void {
		$this->drainJobs(self::JOB_RENAME);
		$this->drainJobs(self::JOB_PUSH);
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
		$this->copyTarget = $this->mappedFolder($mapping) . '/Copy ' . bin2hex(random_bytes(3)) . '.grafana';
		$this->davCopy($this->currentFilePath, $this->copyTarget);
	}

	/** @When I copy the file to a folder that is not mapped */
	public function iCopyTheFileToAnUnmappedFolder(): void {
		$this->copyTarget = $this->unmappedFolder() . '/Copy ' . bin2hex(random_bytes(3)) . '.grafana';
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
		$stem = preg_replace('/\.grafana$/', '', basename($this->currentFilePath)) ?? '';
		$this->lastUid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		$this->theDashboardIsNamedInTheGrafanaFolder($stem, $folder);
	}

	/** @When I rename the file within the :mapping folder */
	public function iRenameTheFileWithinTheFolder(string $mapping): void {
		$dest = $this->mappedFolder($mapping) . '/Renamed ' . bin2hex(random_bytes(3)) . '.grafana';
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

	// ── the guard gestures: asked for, and refused ────────────────────────────
	//
	// ONE SHAPE FOR ALL THREE. A guard's scenario is "I asked, and the app said no
	// and the world did not move" — so each `try to` step keeps the RAW status
	// instead of asserting success, and the matching `Then` proves it was not a
	// success code. Reading the message body is deliberately not asserted: the
	// Sabre guards answer 403 with `<s:message>` and the typed listeners answer 403
	// bare, and a scenario that demanded the richer of the two would be testing
	// which route the gesture happened to take rather than whether it was refused.

	/** @When I try to copy the file into :folder */
	public function iTryToCopyTheFileInto(string $folder): void {
		$this->davMkdir($folder);
		// THE FOLDER'S CONTENTS BEFORE THE ATTEMPT, not the path the copy would take.
		// One row of this Outline copies a link into the folder it is already in, where
		// the "destination path" IS the source file — so asserting that path is empty
		// asserts the original was destroyed, which is the opposite of the claim.
		// Counting what the folder holds says the real thing: nothing was added.
		$this->attemptedCopyFolder = $folder;
		$this->filesBeforeCopy = $this->davListDashboardFiles($folder);
		$dest = $folder . '/' . basename($this->currentFilePath);
		$this->lastCopyStatus = $this->davCopyStatus($this->currentFilePath, $dest);
	}

	// ── WHY THESE THROW INSTEAD OF USING Assert::assertSame/assertNull ──────────
	//
	// A PHPUnit assertion that fails inside Behat and needs to EXPORT a value dies
	// with `PHPUnit\TextUI\Configuration\Registry::get(): Return value must be of
	// type Configuration, null returned` — the exporter reads a configuration
	// registry that only a PHPUnit run initialises. Behat then prints that TypeError
	// instead of the assertion message, so the failure says nothing at all.
	//
	// Measured the expensive way: three CI cycles on these very steps, each one
	// reporting a type error where the reason should have been. `assertTrue` and
	// `assertFalse` never export, so they are safe; anything that compares VALUES is
	// not. These carry their own messages instead.

	/** @Then the copy is refused with a message */
	public function theCopyIsRefused(): void {
		Assert::assertFalse(
			in_array($this->lastCopyStatus, [201, 204], true),
			"the copy succeeded (HTTP {$this->lastCopyStatus}) but should have been refused",
		);
	}

	/**
	 * @Then no file is added to :folder
	 *
	 * The other half of the refusal, and the one that would catch a guard that
	 * answers 403 AFTER the bytes have landed.
	 */
	public function noFileIsAddedTo(string $folder): void {
		$after = $this->davListDashboardFiles($folder);
		$added = array_values(array_diff($after, $this->filesBeforeCopy));
		Assert::assertTrue(
			$added === [],
			"'$folder' gained " . implode(', ', $added) . ' despite the refusal',
		);
	}

	/** @When I try to move it to the trash */
	public function iTryToMoveItToTheTrash(): void {
		$this->lastDeleteStatus = $this->davDeleteStatus($this->currentFilePath);
	}

	/** @Then the trash is refused with a message */
	public function theTrashIsRefused(): void {
		Assert::assertFalse(
			in_array($this->lastDeleteStatus, [200, 204], true),
			"the delete succeeded (HTTP {$this->lastDeleteStatus}) but should have been refused",
		);
	}

	/**
	 * A rename IS a MOVE over WebDAV — same verb, same status, different intent.
	 *
	 * @When I try to rename the file to :filename
	 */
	public function iTryToRenameTheFileTo(string $filename): void {
		$dest = dirname($this->currentFilePath) . '/' . $filename;
		$this->lastMoveStatus = $this->davMoveStatus($this->currentFilePath, $dest);
	}

	/**
	 * A stem of nothing but spaces. Nextcloud refuses a fully EMPTY filename itself,
	 * so the case the app has to answer for is the one core lets through.
	 *
	 * @When I try to rename the file to a name that is only whitespace
	 */
	public function iTryToRenameTheFileToWhitespace(): void {
		$this->iTryToRenameTheFileTo(' .grafana');
	}

	/** @Then the rename is refused with a message */
	public function theRenameIsRefused(): void {
		Assert::assertFalse(
			in_array($this->lastMoveStatus, [201, 204], true),
			"the rename succeeded (HTTP {$this->lastMoveStatus}) but should have been refused",
		);
	}

	/** @Then the move is refused with a message */
	public function theMoveIsRefused(): void {
		Assert::assertFalse(
			in_array($this->lastMoveStatus, [201, 204], true),
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
		$this->currentFolder = $folder;

		$path = $folder . '/' . $title . '.grafana';
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

		// The dashboard landing is what stamped the FOLDER — capture that uid now, so
		// "the uid it had before the delete" has a before to compare with.
		$this->lastFolderUid = (string)$this->davReadMetadata($folder, 'grafana_folder_uid');
		if ($this->lastFolderUid === '') {
			throw new \RuntimeException("'$folder' was never stamped with a Grafana folder uid — the arrange is broken");
		}
	}

	/**
	 * @Then the copy holds:
	 *
	 * The cursor form of `"<path>" holds:` for the file {@see iCopyTheFileInto()} just
	 * made — a scenario cannot name that path without restating the collision rules the
	 * app owns, which is the arithmetic-vs-behaviour trap the table exists to avoid.
	 */
	public function theCopyHolds(TableNode $table): void {
		if ($this->copyTarget === '') {
			throw new \RuntimeException('no copy was made — a When must arrange one');
		}
		$this->theMirrorHolds($this->copyTarget, $table);
	}

	/**
	 * @Then the copy is a new dashboard in the :title Grafana folder
	 *
	 * TWO CLAIMS IN ONE SENTENCE, because separating them would let a scenario pass on
	 * a copy that reused the original's dashboard: it has to be a DIFFERENT uid, and
	 * that uid has to really exist in Grafana, in the folder the copy landed in.
	 */
	public function theCopyIsANewDashboardInTheGrafanaFolder(string $title): void {
		$copyUid = (string)$this->davReadMetadata($this->copyTarget, self::META_UID);
		if ($copyUid === '') {
			throw new \RuntimeException('the copy carries no grafana_uid, so no dashboard was registered for it');
		}
		if ($copyUid === $this->lastUid) {
			throw new \RuntimeException(
				"the copy reused the original's dashboard ($copyUid) instead of minting one",
			);
		}
		$this->createdDashboardUids[] = $copyUid;

		$record = $this->grafanaGetDashboard($copyUid);
		if ($record === null) {
			throw new \RuntimeException("the copy claims dashboard '$copyUid', which does not exist in Grafana");
		}
		$want = $this->createdGrafanaFolders[$title] ?? $this->grafanaFolderUidByTitle($title);
		$got = (string)($record['meta']['folderUid'] ?? '');
		if ($got !== $want) {
			throw new \RuntimeException("the copy's dashboard is in '$got', expected '$want'");
		}
	}

	/**
	 * @When someone copies its dashboard in Grafana, keeping the title
	 *
	 * Grafana's own "Save as copy": a NEW uid carrying the same spec — and THE SAME
	 * TITLE, which is the hard case and the only one worth arranging.
	 *
	 * This step used to append " Copy" to the title. That is what Grafana's dialog
	 * PRE-FILLS, not what it enforces: the field is editable, and two `POST
	 * /api/dashboards/db` with one title, one folderUid and `overwrite:false` both
	 * return 200 with different uids (verified against Grafana 13.0.2). By renaming the
	 * copy, the arrange guaranteed no collision could ever reach Nextcloud — so the one
	 * rule this scenario exists to pin, that Nextcloud's filename gives way where
	 * Grafana's title does not, was never once exercised.
	 *
	 * Then a pull, because the mirror arriving is the behaviour under test and the sync
	 * that carries it is not.
	 */
	public function someoneCopiesItsDashboardInGrafana(): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '{$this->lastUid}' does not exist in Grafana");
		}
		$spec = $record['dashboard'] ?? [];
		$this->grafanaCopyUid = 'nc-copy-' . bin2hex(random_bytes(3));
		$spec['uid'] = $this->grafanaCopyUid;
		$spec['id'] = null;

		$res = $this->grafanaClient()->request('POST', 'dashboards/db', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode([
				'dashboard' => $spec,
				'folderUid' => (string)($record['meta']['folderUid'] ?? ''),
				'overwrite' => false,
				'message' => 'integration copy-in-grafana',
			], JSON_THROW_ON_ERROR),
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException('copying in Grafana failed: ' . (string)$res->getBody());
		}
		$this->createdDashboardUids[] = $this->grafanaCopyUid;
		$this->theAdminPullsFromGrafana();
	}

	/**
	 * @Then the copy arrives as its own file in :folder
	 *
	 * ITS OWN FILE — found by the uid Grafana minted, not by name. A pull that wrote
	 * the copy over the original would leave one file and pass a name-based check.
	 */
	public function theCopyArrivesAsItsOwnFileIn(string $folder): void {
		foreach ($this->davListDashboardFiles($folder) as $name) {
			$path = $folder . '/' . $name;
			if ($this->davReadMetadata($path, self::META_UID) === $this->grafanaCopyUid) {
				$this->copyTarget = $path;
				return;
			}
		}
		throw new \RuntimeException(
			"no file in '$folder' mirrors the copied dashboard '{$this->grafanaCopyUid}'",
		);
	}

	/**
	 * @Then that file holds:
	 *
	 * The file the previous Then located — kept distinct from `the copy holds:` so a
	 * scenario cannot assert against a copy it never found.
	 */
	public function thatFileHolds(TableNode $table): void {
		if ($this->copyTarget === '') {
			throw new \RuntimeException('no copy has been located by a previous step');
		}
		$this->theMirrorHolds($this->copyTarget, $table);
	}

	/**
	 * @Then :folder holds one file per dashboard, named:
	 *
	 * THE WHOLE FOLDER, AS A SET. Naming the files individually would say nothing about
	 * how many there are, and the failure a suffix bug produces is usually a MISSING
	 * file rather than a misnamed one — two dashboards collapsing onto one mirror.
	 *
	 * "One file per dashboard" is the second half, and it is checked by uid: three files
	 * with the right three names could still be three views of one dashboard, which is
	 * the shape a copy-that-hijacks leaves behind.
	 */
	public function holdsOneFilePerDashboardNamed(string $folder, TableNode $table): void {
		$want = array_map(static fn (array $row): string => trim($row[0]), $table->getRows());
		sort($want);
		$got = $this->davListDashboardFiles($folder);
		sort($got);
		if ($got !== $want) {
			throw new \RuntimeException(
				"'$folder' does not hold the files the scenario describes:\n  expected: "
				. implode(', ', $want) . "\n  found:    " . implode(', ', $got),
			);
		}

		$uids = [];
		foreach ($got as $name) {
			$uid = (string)$this->davReadMetadata($folder . '/' . $name, self::META_UID);
			if ($uid === '') {
				throw new \RuntimeException("'$folder/$name' carries no uid, so it mirrors no dashboard");
			}
			if (isset($uids[$uid])) {
				throw new \RuntimeException(
					"'$name' and '{$uids[$uid]}' both claim dashboard '$uid' — one dashboard, two mirrors",
				);
			}
			$uids[$uid] = $name;
			$this->createdDashboardUids[] = $uid;
		}
		$this->namedFolder = $folder;
		$this->namedFiles = $got;
	}

	/**
	 * @Then all three dashboards are still titled :title in Grafana
	 *
	 * THE COUNTER STOPS AT THE FILENAME. Nextcloud cannot hold three files with one
	 * name, so it numbers them — but that is Nextcloud's constraint, and pushing it back
	 * into Grafana would rename dashboards nobody asked to rename. Grafana is allowed
	 * its duplicates and keeps them.
	 *
	 * ## WHY "STILL" IS DOING REAL WORK IN THAT SENTENCE
	 *
	 * Landing the three names correctly is the easy half; KEEPING them is where the pull
	 * was wrong. It asked for the unsuffixed name on every mirror on every tick, so both
	 * duplicates were told, over and over, to go and take a name the first one was
	 * sitting on. It only "worked" because the rename threw and the catch logged
	 * `rename skipped (collision?)` — and the moment anything made that move succeed, the
	 * mirrors would start swapping names underneath the user.
	 *
	 * So this settles the folder before believing it: read, sync once more, and check
	 * that neither the titles nor the names moved. The sync is a MECHANISM and stays
	 * here; a scenario that said "and sync again" out loud would be describing the
	 * app's plumbing instead of what the user ends up with.
	 */
	public function allThreeDashboardsAreStillTitledInGrafana(string $title): void {
		$this->assertAllTitled($title, 'as they landed');
		$namesBefore = $this->namedFiles;

		$this->theAdminPullsFromGrafana();

		$namesAfter = $this->davListDashboardFiles($this->namedFolder);
		sort($namesAfter);
		if ($namesAfter !== $namesBefore) {
			throw new \RuntimeException(
				"the names did not survive another sync:\n  were: " . implode(', ', $namesBefore)
				. "\n  now:  " . implode(', ', $namesAfter),
			);
		}
		$this->assertAllTitled($title, 'after another sync');
	}

	/** Every file the scenario named claims a dashboard Grafana still calls $title. */
	private function assertAllTitled(string $title, string $when): void {
		$wrong = [];
		foreach ($this->namedFiles as $name) {
			$uid = (string)$this->davReadMetadata($this->namedFolder . '/' . $name, self::META_UID);
			$record = $this->grafanaGetDashboard($uid);
			$got = $record === null ? '<gone from Grafana>' : (string)($record['dashboard']['title'] ?? '');
			if ($got !== $title) {
				$wrong[] = "$name → '$got'";
			}
		}
		if ($wrong !== []) {
			throw new \RuntimeException(
				"$when, these are no longer titled '$title' in Grafana: " . implode('; ', $wrong)
				. ' — a Nextcloud filename counter reached Grafana, which is the one place it must not go',
			);
		}
	}
}
