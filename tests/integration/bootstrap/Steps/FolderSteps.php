<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;

/**
 * The gestures a FOLDER has of its own: its tags, and its name.
 *
 * ## A FOLDER IS NAMED BY ITS NEXTCLOUD PATH, ON BOTH SIDES
 *
 * Every step here takes the Nextcloud path — `Demo/Team`, `Pointers/Team` — and
 * resolves the Grafana folder behind it, rather than making a scenario carry a uid
 * it has no way of knowing. Grafana mints a nested folder's uid itself, so a
 * scenario that named one could only be repeating a value the arrange handed it.
 *
 * Only the MAPPING's own folder is translated on the way across; nothing beneath it
 * renames, which is why the rest of the path is carried over as it stands. See
 * `features/AGENTS.md#only-a-mapping-renames-a-folder`.
 *
 * ## AND A FOLDER'S TAGS ARE AN ANNOTATION THIS APP INVENTED
 *
 * Grafana tags dashboards natively and folders not at all; a mirrored folder's tags
 * live in `nextcloud.kubed.io/tags` on the app-platform folder resource. They are
 * read back through Grafana's own API rather than through this app, so a passing
 * assertion means the value genuinely landed on the far object instead of the app
 * agreeing with itself about what it would have written.
 *
 * ## AND EVERY FAILURE HERE THROWS RATHER THAN ASSERTS
 *
 * PHPUnit builds a failure message through an exporter that reads its own
 * configuration registry, and under Behat that registry is never initialised —
 * so a FAILING assertion reports `Registry::get(): ... null returned` instead of
 * what went wrong. It does not flake; it replaces every real message with the same
 * meaningless one, and it cost two CI rounds to see that.
 *
 * A thrown `RuntimeException` carries the sentence a person needs and nothing else
 * has to be initialised for it. {@see MirrorSteps} reached the same conclusion.
 */
trait FolderSteps {
	/**
	 * @Given /^the folder "([^"]*)" whose tags are "([^"]*)"$/
	 *
	 * THE PRE-STATE IS SET IN GRAFANA AND PULLED, never written into Nextcloud. Tagging
	 * the Nextcloud side would be the very gesture two of these scenarios are about, so
	 * an arrange that used it would have performed the behaviour before the `When`.
	 */
	public function theFolderWhoseTagsAre(string $ncPath, string $tags): void {
		$uid = $this->grafanaFolderUidForNcPath($ncPath, true);
		$this->grafanaSetFolderTags($uid, $this->parseTags($tags));
		// PINNED FOR `is untouched in Grafana`, which needs a BEFORE to mean anything.
		// Read back afterwards it would only ever agree with itself.
		$this->folderTagsBefore[$ncPath] = $this->parseTags($tags);
		$this->pullEveryMapping();

		if (!$this->davExists($ncPath)) {
			throw new \RuntimeException("the sync did not bring '$ncPath' into Nextcloud, so there is no folder to tag");
		}
		$this->currentFolder = $ncPath;
		$this->originalPath = $ncPath;
		$this->currentGrafanaFolder = $uid;
	}

	/**
	 * @When /^the tags on "([^"]*)" are changed to "([^"]*)" in Grafana$/
	 *
	 * The pull is folded in, as it is for every other in-Grafana gesture: nobody
	 * re-tags a folder in order to run a sync.
	 */
	public function theTagsOnAreChangedToInGrafana(string $ncPath, string $tags): void {
		$this->grafanaSetFolderTags($this->grafanaFolderUidForNcPath($ncPath), $this->parseTags($tags));
		$this->pullEveryMapping();
	}

	/** Grafana folder tags as an arrange left them, for asserting nothing moved. */
	private array $folderTagsBefore = [];

	/** @BeforeScenario */
	public function resetFolderTagsBefore(): void {
		$this->folderTagsBefore = [];
	}

	/**
	 * @Then /^the folder "([^"]*)" is untouched in Grafana$/
	 *
	 * STRONGER THAN "has no tags". The gesture under test tries to ADD a tag, so a
	 * far side that had been cleared would satisfy "no tags" while still proving the
	 * app had written where it should not. Untouched means exactly what the arrange
	 * put there — no more, no less, nothing at all having happened.
	 */
	public function theFolderIsUntouchedInGrafana(string $ncPath): void {
		if (!isset($this->folderTagsBefore[$ncPath])) {
			throw new \RuntimeException("nothing arranged the tags on '$ncPath', so 'untouched' has no meaning");
		}
		$this->assertSameTags(
			$this->folderTagsBefore[$ncPath],
			$this->grafanaFolderTags($this->grafanaFolderUidForNcPath($ncPath)),
			"the tags on the Grafana folder mirroring '$ncPath'",
		);
	}

	/** @Then /^the folder "([^"]*)" is tagged "([^"]*)" in Grafana$/ */
	public function theFolderIsTaggedInGrafana(string $ncPath, string $tags): void {
		$this->assertSameTags(
			$this->parseTags($tags),
			$this->grafanaFolderTags($this->grafanaFolderUidForNcPath($ncPath)),
			"the tags on the Grafana folder mirroring '$ncPath'",
		);
	}

	// ── renaming ──────────────────────────────────────────────────────────────

	/**
	 * @Given /^the folder "([^"]*)" holding a dashboard$/
	 *
	 * The dashboard is what puts the folder in Grafana at all — a folder is mirrored
	 * because something in it is (`folders/create.feature`) — so this arrange is the
	 * shortest honest way to get a mirrored subfolder to rename.
	 *
	 * BOTH UIDS ARE CAPTURED HERE, because after the gesture there is no way to learn
	 * what they were: the whole claim is that they did not change, and a value read
	 * afterwards would agree with itself.
	 */
	public function theFolderHoldingADashboard(string $ncPath): void {
		$this->davMkdir($ncPath);
		$this->putDashboardFile($ncPath, 'Fleet Health');

		$this->lastUid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		if ($this->lastUid === '') {
			throw new \RuntimeException("no dashboard was created for the file in '$ncPath'");
		}
		$this->createdDashboardUids[] = $this->lastUid;

		$this->lastFolderUid = (string)$this->davReadMetadata($ncPath, self::META_FOLDER_UID);
		if ($this->lastFolderUid === '') {
			throw new \RuntimeException(
				"'$ncPath' carries no Grafana folder uid, so it was never mirrored and cannot be renamed",
			);
		}
		$this->currentFolder = $ncPath;
	}

	/** @When /^I rename "([^"]*)" to "([^"]*)"$/ */
	public function iRenameTo(string $from, string $to): void {
		$this->davMove($from, $to);
		$this->currentFolder = $to;
	}

	/**
	 * @When /^someone renames the "([^"]*)" Grafana folder to "([^"]*)"$/
	 *
	 * Named by the NEXTCLOUD path it mirrors, for the reason in the trait docblock: a
	 * nested folder's uid is Grafana's to mint, so a scenario cannot name one.
	 */
	public function someoneRenamesTheGrafanaFolderTo(string $ncPath, string $name): void {
		$this->grafanaRenameFolder($this->grafanaFolderUidForNcPath($ncPath), $name);
		$this->pullEveryMapping();
	}

	/** @Then /^"([^"]*)" is gone from Nextcloud$/ */
	public function isGoneFromNextcloud(string $path): void {
		if ($this->davExists($path)) {
			throw new \RuntimeException(
				"'$path' is still in Nextcloud — the rename left the old name standing beside the new one",
			);
		}
	}

	/**
	 * @Then /^the dashboard inside "([^"]*)" holds:$/
	 *
	 * THE FOLDER'S IDENTITY IS NOT ENOUGH. A mirror that kept the folder's uid while
	 * re-minting the dashboards underneath would satisfy half the claim and break
	 * every link anyone had saved, so the rename scenarios ask both.
	 */
	public function theDashboardInsideHolds(string $folder, TableNode $table): void {
		$files = $this->davListDashboardFiles($folder);
		if (count($files) !== 1) {
			throw new \RuntimeException(
				"'$folder' should hold exactly one dashboard file; it holds: " . (implode(', ', $files) ?: '(nothing)'),
			);
		}
		$this->theMirrorHolds($folder . '/' . $files[0], $table);
	}

	// ── the state both sides are already in ───────────────────────────────────

	/**
	 * @Given /^the following items in the mappings:$/
	 *
	 * ## NEXTCLOUD PATHS ONLY, AND THAT IS THE POINT
	 *
	 * An item inside a mapping is on BOTH sides — that is what a mapping means — so
	 * spelling out the Grafana half as well would be saying the same thing twice and
	 * inviting the two halves to drift apart in the spec. A `.grafana` file in a
	 * mapped folder implies its dashboard; a folder implies the Grafana folder
	 * mirroring it. Only the mapping's own folder is ever named differently, and that
	 * translation is this step's job rather than the reader's.
	 *
	 * ## AND IT REPLACED `Grafana and Nextcloud are in sync`
	 *
	 * Which was a `Given` that ran a sync — an ACTION dressed as a state, and the same
	 * sync-now the features are meant to be testing elsewhere. Every Background that
	 * used it was quietly performing behaviour before the `When`. This declares the
	 * state instead; how it becomes true is the harness's business, and the scenario
	 * says nothing about a sync ever having run.
	 *
	 * SEEDED IN GRAFANA, because a link mapping refuses authoring from Nextcloud. An
	 * arrange that wrote the files locally would work in a sync mapping and be refused
	 * in a link one, for reasons that have nothing to do with what the scenario tests.
	 *
	 * Columns: `path` (required) and `tags` (optional).
	 */
	public function theFollowingItemsInTheMappings(TableNode $table): void {
		// RESET, AND RECORD WHAT THIS TABLE SEEDED. The Background declares the
		// neighbourhood and the scenario declares its subject, in that order — so the
		// last table to run is the one a `Then` means by "those dashboards", and the
		// gestures need no pinning step of their own. `I move … to the trash` already
		// exists in TrashSteps and must not be redeclared to add one.
		$this->originalDashboardUids = [];
		$touchedMapping = false;
		foreach ($table->getHash() as $row) {
			$path = ltrim($this->requirePath($row), '/');
			$tags = $this->parseTags(trim((string)($row['tags'] ?? '')));

			if (!$this->looksLikeFolder($path) && !str_ends_with($path, '.grafana')) {
				// An ordinary file is nobody's mirror; it just lives there.
				$this->davMkdir(ltrim($this->parentOf($path), '/'));
				$this->davPut($path, "declared by a Background, and not a dashboard\n");
				continue;
			}

			$touchedMapping = true;
			if ($this->looksLikeFolder($path)) {
				$uid = $this->grafanaFolderUidForNcPath($path, true);
				if ($tags !== []) {
					$this->grafanaSetFolderTags($uid, $tags);
				}
				continue;
			}

			$title = substr($this->leafOf($path), 0, -strlen('.grafana'));
			$folderUid = $this->grafanaFolderUidForNcPath($this->parentOf($path), true);
			$uid = 'gs-' . substr(sha1($path), 0, 16);
			if ($tags !== []) {
				$this->grafanaCreateTaggedDashboard($uid, $title, $folderUid, $tags);
			} else {
				$this->grafanaCreateDashboard($uid, $title, $folderUid);
			}
			$this->createdDashboardUids[] = $uid;
			$this->seededDashboards[$title] = $uid;
			$this->originalDashboardUids[] = $uid;
		}

		if ($touchedMapping) {
			$this->pullEveryMapping();
		}
	}

	/**
	 * @Then /^the mappings hold:$/
	 *
	 * ONE TABLE FOR AN ARBITRARY SET, rather than an `And … holds:` per item. A copy
	 * of a folder holding three dashboards makes four claims of the same shape, and
	 * four near-identical blocks read as four ideas when they are one.
	 *
	 * `identity` is asked of whichever key the path implies — `grafana_folder_uid`
	 * for a folder, `grafana_uid` for a file — because the path already says which it
	 * is and a `type` column would only repeat it.
	 */
	public function theMappingsHold(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$path = ltrim($this->requirePath($row), '/');
			$want = trim((string)($row['identity'] ?? ''));
			if ($want === '') {
				throw new \RuntimeException("the row for '$path' has no identity to check");
			}
			if (!$this->davExists($path)) {
				throw new \RuntimeException("'$path' does not exist");
			}
			$key = $this->looksLikeFolder($path) ? self::META_FOLDER_UID : self::META_UID;

			// A FILE'S "a new id" IS ABOUT A SET, not about the cursor. The shared
			// vocabulary compares against the ONE uid a scenario last touched, which is
			// the right question when one file was copied and the wrong one when a
			// folder of them was: each copy must differ from EVERY original, and there
			// is no single original to be the answer.
			if ($key === self::META_UID && $want === 'a new id') {
				if ($this->originalDashboardUids === []) {
					throw new \RuntimeException('the arrange captured no original uids to differ from');
				}
				$uid = (string)$this->davReadMetadata($path, self::META_UID);
				if ($uid === '') {
					throw new \RuntimeException("'$path' carries no uid, so the copy never became a dashboard");
				}
				if (in_array($uid, $this->originalDashboardUids, true)) {
					throw new \RuntimeException(
						"'$path' reused an original's uid ($uid) — two files would claim one dashboard",
					);
				}
				continue;
			}

			$this->theMirrorHolds($path, new TableNode([[$key, $want]]));
		}
	}

	// ── copying ───────────────────────────────────────────────────────────────

	/** The source folder's Grafana uid and its dashboards' uids, captured before a copy. */
	private array $originalDashboardUids = [];

	/** @BeforeScenario */
	public function resetOriginalDashboardUids(): void {
		$this->originalDashboardUids = [];
	}

	/**
	 * @When /^I copy "([^"]*)" to "([^"]*)"$/
	 *
	 * BOTH SETS OF UIDS ARE READ FIRST, because the whole claim is that the copy did
	 * not take them — and after the gesture there is nothing to compare against. A
	 * value read afterwards would only agree with itself.
	 */
	public function iCopyTo(string $from, string $to): void {
		$this->pinOriginalsOf($from);
		$this->davCopy($from, $to);
		$this->currentFolder = $to;
	}

	/** @When /^I try to copy "([^"]*)" to "([^"]*)"$/ */
	public function iTryToCopyTo(string $from, string $to): void {
		$this->pinOriginalsOf($from);
		$this->lastCopyStatus = $this->davCopyStatus($from, $to);
	}

	/**
	 * @Then /^"([^"]*)" holds the same files "([^"]*)" does$/
	 *
	 * By NAME, which is all a copy owes: the identities are the subject of the next
	 * assertion and must be different, so comparing anything else here would be
	 * asking the copy to be the original.
	 */
	public function holdsTheSameFilesAs(string $copy, string $original): void {
		$want = $this->davListDashboardFiles($original);
		$got = $this->davListDashboardFiles($copy);
		sort($want);
		sort($got);
		// COMPARED AS TEXT, NOT AS ARRAYS, and joined on a newline rather than a
		// comma. PHPUnit shortens an array longer than ten entries when it builds a
		// failure diff and reads that threshold from a registry that is unconfigured
		// outside a PHPUnit run — measured, and it reports the registry error INSTEAD
		// of the assertion. A newline is the separator because a filename can contain
		// a comma and cannot contain one of these.
		if ($want !== $got) {
			throw new \RuntimeException(
				"'$copy' does not hold the same files as '$original'\n"
				. '  expected: ' . implode(', ', $want) . "\n"
				. '  actually: ' . implode(', ', $got),
			);
		}
		if ($got === []) {
			throw new \RuntimeException("'$original' holds no dashboard files, so the copy proves nothing");
		}
	}

	/** @Then /^the dashboards in "([^"]*)" hold no Grafana metadata at all$/ */
	public function theDashboardsInHoldNoGrafanaMetadata(string $folder): void {
		$files = $this->davListDashboardFiles($folder);
		if ($files === []) {
			throw new \RuntimeException("'$folder' holds no dashboard files");
		}
		foreach ($files as $name) {
			foreach ([self::META_UID, self::META_MAPPING, self::META_MODE] as $key) {
				$value = (string)$this->davReadMetadata($folder . '/' . $name, $key);
				if ($value !== '') {
					throw new \RuntimeException(
						"'$folder/$name' carries $key='$value', but nothing outside a mapping is managed",
					);
				}
			}
		}
	}

	/** @Then /^"([^"]*)" holds no folder named "([^"]*)"$/ */
	public function holdsNoFolderNamed(string $parent, string $name): void {
		if ($this->davExists(trim($parent, '/') . '/' . $name)) {
			throw new \RuntimeException("'$parent' holds a folder named '$name' — the refusal let it through");
		}
	}

	/**
	 * Pin what the source holds, so the copy can be compared against it afterwards.
	 */
	private function pinOriginalsOf(string $folder): void {
		$this->lastFolderUid = (string)$this->davReadMetadata($folder, self::META_FOLDER_UID);
		$this->originalDashboardUids = [];
		// AT EVERY DEPTH. `davListDashboardFiles` is one level, and a folder gesture is
		// recursive by nature — a purge of a folder holding `Sub/Deep.grafana` pinned
		// nothing at all and then complained it had nothing to look for.
		foreach ($this->davTreeUnder($folder) as $path) {
			if (!str_ends_with($path, '.grafana')) {
				continue;
			}
			$uid = (string)$this->davReadMetadata(ltrim($path, '/'), self::META_UID);
			if ($uid !== '') {
				$this->originalDashboardUids[] = $uid;
			}
		}
	}

	// ── moving ────────────────────────────────────────────────────────────────

	/**
	 * @When /^I move "([^"]*)" into "([^"]*)"$/
	 *
	 * Pins the source's identities first, for the reason {@see iCopyTo} gives: after
	 * the gesture there is nothing left to compare "the original id" against.
	 */
	public function iMoveInto(string $from, string $into): void {
		$this->pinOriginalsOf($from);
		$this->davMkdir($into);
		$this->davMove($from, rtrim($into, '/') . '/' . $this->leafOf($from));
		$this->currentFolder = rtrim($into, '/') . '/' . $this->leafOf($from);
	}

	/** @When /^I try to move "([^"]*)" into "([^"]*)"$/ */
	public function iTryToMoveInto(string $from, string $into): void {
		$this->pinOriginalsOf($from);
		$this->davMkdir($into);
		$this->lastMoveStatus = $this->davMoveStatus($from, rtrim($into, '/') . '/' . $this->leafOf($from));
	}

	/**
	 * @When /^someone moves the "([^"]*)" Grafana folder under "([^"]*)" as "([^"]*)"$/
	 *
	 * One call, because Grafana takes a parent and a title together — which is why
	 * `folders/move.feature` can move and rename at once where a Files gesture cannot.
	 */
	public function someoneMovesTheGrafanaFolderUnderAs(string $ncPath, string $newParent, string $name): void {
		$uid = $this->grafanaFolderUidForNcPath($ncPath);
		$this->lastFolderUid = (string)$this->davReadMetadata($ncPath, self::META_FOLDER_UID);
		$this->grafanaMoveFolder($uid, $this->grafanaFolderUidForNcPath($newParent), $name);
		$this->pullEveryMapping();
	}

	// ── the trash ─────────────────────────────────────────────────────────────

	/** @When /^I try to move "([^"]*)" to the trash$/ */
	public function iTryToMoveToTheTrash(string $folder): void {
		$this->pinOriginalsOf($folder);
		$this->lastDeleteStatus = $this->davDeleteStatus($folder);
	}

	/** The trashbin entry this scenario made, pinned so a stale one cannot answer for it. */
	private string $trashedFolderEntry = '';

	/** @BeforeScenario */
	public function resetTrashedFolderEntry(): void {
		$this->trashedFolderEntry = '';
	}

	/**
	 * @Given /^"([^"]*)" is in the Nextcloud trash$/
	 *
	 * The trash gesture ITSELF, run as an arrange. Restoring and purging both need a
	 * folder that has really been through it — the trash hooks are what park or
	 * delete the dashboards, and a folder placed in the trash by any other route
	 * would leave Grafana in a state no gesture produces.
	 */
	public function isInTheNextcloudTrash(string $folder): void {
		$this->pinOriginalsOf($folder);
		$this->davDelete($folder);
		if ($this->davExists($folder)) {
			throw new \RuntimeException("'$folder' is still in Nextcloud after being trashed");
		}
		$entry = $this->trashbinPathFor($folder);
		if ($entry === null) {
			throw new \RuntimeException("setup: '$folder' did not reach the Nextcloud trash");
		}
		// PINNED HERE, NOT LOOKED UP LATER. Every scenario trashes a folder called the
		// same thing and nothing empties the trash between them — emptying it is itself
		// a gesture that finishes deletes in Grafana — so asking by name afterwards can
		// answer with an entry an earlier scenario left behind, and report this one
		// still present after it was purged. Measured: the same `Team.d1787180677`
		// answered for three different scenarios.
		$this->trashedFolderEntry = $entry;
	}

	/** @Then /^"([^"]*)" is recoverable from the Nextcloud trash$/ */
	public function isRecoverableFromTheNextcloudTrash(string $folder): void {
		if ($this->trashbinPathFor($folder) === null) {
			throw new \RuntimeException("'$folder' is not in the Nextcloud trash, so nothing could be recovered");
		}
	}

	/** @When /^I restore "([^"]*)" from the Nextcloud trash$/ */
	public function iRestoreFromTheNextcloudTrash(string $folder): void {
		$entry = $this->trashbinPathFor($folder);
		if ($entry === null) {
			throw new \RuntimeException("'$folder' is not in the Nextcloud trash");
		}
		$res = $this->davClient()->request('MOVE', $this->trashHref($entry), [
			'headers' => [
				'Destination' => $this->ncBaseUrl . '/remote.php/dav/trashbin/'
					. rawurlencode($this->ncUser) . '/restore/' . rawurlencode($entry),
			],
		]);
		$this->assertStatus($res, [201, 204], "restore $entry");
		$this->currentFolder = $folder;
	}

	/** @When /^I purge "([^"]*)" from the trash$/ */
	public function iPurgeFromTheTrash(string $folder): void {
		$entry = $this->trashedFolderEntry ?: $this->trashbinPathFor($folder);
		if ($entry === null || $entry === '') {
			throw new \RuntimeException("'$folder' is not in the Nextcloud trash");
		}
		$this->assertStatus($this->davClient()->request('DELETE', $this->trashHref($entry)), [204, 200], "purge $entry");
	}

	/** @Then /^"([^"]*)" is gone from the Nextcloud trash$/ */
	public function isGoneFromTheNextcloudTrash(string $folder): void {
		// THIS SCENARIO'S ENTRY, not whatever the name resolves to now — see the pin in
		// `is in the Nextcloud trash`.
		if ($this->trashedFolderEntry === '') {
			throw new \RuntimeException("nothing recorded trashing '$folder', so 'gone' has nothing to mean");
		}
		if ($this->trashEntryExists($this->trashedFolderEntry)) {
			throw new \RuntimeException(
				"'$folder' is still in the Nextcloud trash as '{$this->trashedFolderEntry}'",
			);
		}
	}


	// ── what became of the dashboards ─────────────────────────────────────────

	/** @Then /^none of those dashboards exists in Grafana$/ */
	public function noneOfThoseDashboardsExistsInGrafana(): void {
		if ($this->originalDashboardUids === []) {
			throw new \RuntimeException('nothing captured the dashboards to look for');
		}
		foreach ($this->originalDashboardUids as $uid) {
			if ($this->grafanaGetDashboard($uid) !== null) {
				throw new \RuntimeException("dashboard $uid still exists in Grafana");
			}
		}
	}

	/**
	 * @Then /^no dashboard it held exists in Grafana$/
	 *
	 * VACUOUS WHEN IT HELD NONE, deliberately. `purge.feature` runs this over an
	 * Examples table whose whole point is that what was inside makes no difference —
	 * and one row is a folder holding only a spreadsheet. Demanding a non-empty set
	 * would fail that row for being exactly what it says it is.
	 */
	public function noDashboardItHeldExistsInGrafana(): void {
		foreach ($this->originalDashboardUids as $uid) {
			if ($this->grafanaGetDashboard($uid) !== null) {
				throw new \RuntimeException("dashboard $uid still exists in Grafana");
			}
		}
	}

	/**
	 * @Then /^those dashboards are parked in "([^"]*)"$/
	 *
	 * PARKED, NOT DELETED — the whole of what the recycle bin buys. Each is still
	 * live in Grafana, and living in the bin folder is the only thing that changed.
	 */
	public function thoseDashboardsAreParkedIn(string $binTitle): void {
		if ($this->originalDashboardUids === []) {
			throw new \RuntimeException('nothing captured the dashboards to look for');
		}
		$bin = $this->grafanaFolderUidByTitle($binTitle);
		if ($bin === null) {
			throw new \RuntimeException("Grafana has no folder titled '$binTitle'");
		}
		foreach ($this->originalDashboardUids as $uid) {
			$record = $this->grafanaGetDashboard($uid);
			if ($record === null) {
				throw new \RuntimeException("dashboard $uid was deleted rather than parked");
			}
			$in = (string)($record['meta']['folderUid'] ?? '');
			if ($in !== $bin) {
				throw new \RuntimeException("dashboard $uid is in '$in', not the bin '$binTitle' ($bin)");
			}
		}
	}

	/** @Then /^"([^"]*)" holds no dashboard files$/ */
	public function holdsNoDashboardFiles(string $folder): void {
		$found = $this->davListDashboardFiles($folder);
		if ($found !== []) {
			throw new \RuntimeException("'$folder' still holds dashboard files: " . implode(', ', $found));
		}
	}

	/** @Then /^"([^"]*)" holds "([^"]*)"$/ */
	public function holdsNamedFile(string $folder, string $name): void {
		if (!$this->davExists(trim($folder, '/') . '/' . $name)) {
			throw new \RuntimeException("'$folder' does not hold '$name'");
		}
	}

	/** @When /^someone deletes the "([^"]*)" folder in Grafana$/ */
	public function someoneDeletesTheFolderInGrafana(string $ncPath): void {
		$this->pinOriginalsOf($ncPath);
		$this->grafanaDeleteFolder($this->grafanaFolderUidForNcPath($ncPath));
		$this->pullEveryMapping();
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * The Grafana folder behind a Nextcloud path.
	 *
	 * `$create` makes the chain on the way down, for an arrange that is describing a
	 * folder into existence. An assertion passes false and gets a failure naming the
	 * level that is missing, rather than quietly conjuring it and then agreeing that
	 * it is there.
	 */
	private function grafanaFolderUidForNcPath(string $ncPath, bool $create = false): string {
		[$uid, $segments] = $this->grafanaChainFor($ncPath);
		$walked = '';
		foreach ($segments as $segment) {
			$walked = $walked === '' ? $segment : $walked . '/' . $segment;
			$child = $this->grafanaChildUid($uid, $segment);
			if ($child === null) {
				if (!$create) {
					throw new \RuntimeException("Grafana has no folder mirroring '$ncPath' (missing at '$walked')");
				}
				$child = $this->grafanaCreateFolder($segment, $uid);
				// Only what this step made — see FeatureContext::$createdGrafanaFolders.
				$this->createdGrafanaFolders[] = $child;
			}
			$this->knownGrafanaFolders[$segment] = $child;
			$uid = $child;
		}
		return $uid;
	}
}
