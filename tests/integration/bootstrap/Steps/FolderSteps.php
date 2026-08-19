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

		Assert::assertTrue(
			$this->davExists($ncPath),
			"the sync did not bring '$ncPath' into Nextcloud, so there is no folder to tag",
		);
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
		Assert::assertNotSame('', $this->lastUid, "no dashboard was created for the file in '$ncPath'");
		$this->createdDashboardUids[] = $this->lastUid;

		$this->lastFolderUid = (string)$this->davReadMetadata($ncPath, self::META_FOLDER_UID);
		Assert::assertNotSame(
			'',
			$this->lastFolderUid,
			"'$ncPath' carries no Grafana folder uid, so it was never mirrored and cannot be renamed",
		);
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
		Assert::assertFalse(
			$this->davExists($path),
			"'$path' is still in Nextcloud — the rename left the old name standing beside the new one",
		);
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
		Assert::assertCount(
			1,
			$files,
			"'$folder' should hold exactly one dashboard file; it holds: " . (implode(', ', $files) ?: '(nothing)'),
		);
		$this->theMirrorHolds($folder . '/' . $files[0], $table);
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
