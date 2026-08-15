<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

/**
 * Tags, on a dashboard file and on a folder (`dashboards/tags.feature`,
 * `folders/tags.feature`, and the tag half of `connection/sync-now.feature`).
 *
 * ## HOW A NEXTCLOUD TAG IS READ AND WRITTEN HERE
 *
 * Through the **systemtags DAV endpoints**, which is what the Files app itself uses:
 *
 *   `/remote.php/dav/systemtags/`                       the catalog
 *   `/remote.php/dav/systemtags-relations/files/<id>`   what one node is tagged
 *
 * Deliberately not `occ`, and deliberately not the database. The point of an
 * integration test is that the tag is really visible where a user would see it, and
 * the DAV relation is the surface the web UI and the desktop client both read. A
 * database assertion would pass on a tag no client could find.
 *
 * ## THE GRAFANA SIDE IS ASKED, NEVER DERIVED
 *
 * Folder tags are read back out of the app-platform annotation through Grafana's own
 * API ({@see \OCA\GrafanaSync\Tests\Integration\Support\GrafanaApiTrait::grafanaFolderTags()}),
 * so a passing assertion means the value genuinely landed on the Grafana object — not
 * that this app agrees with itself about what it would have written.
 */
trait TagSteps {
	/** @Given the Grafana folder :uid is tagged :tags */
	public function theGrafanaFolderIsTagged(string $uid, string $tags): void {
		$this->grafanaSetFolderTags($uid, $this->parseTags($tags));
	}

	/** @Given a tagged Grafana dashboard :title with uid :uid in folder :folderUid tagged :tags */
	public function aTaggedGrafanaDashboard(string $title, string $uid, string $folderUid, string $tags): void {
		$this->grafanaCreateTaggedDashboard($uid, $title, $folderUid, $this->parseTags($tags));
		$this->lastUid = $uid;
	}

	/**
	 * @When the folder's tags are changed to :tags in Grafana
	 *
	 * The medium is not the behaviour — someone changed the tags, and the only door
	 * into a folder's annotation happens to be the API.
	 */
	public function theFoldersTagsAreChangedToInGrafana(string $tags): void {
		if ($this->currentGrafanaFolder === '') {
			throw new \RuntimeException('no Grafana folder is in play for this scenario');
		}
		$this->grafanaSetFolderTags($this->currentGrafanaFolder, $this->parseTags($tags));
	}

	/** @When I tag :folder with :tags */
	public function iTagWith(string $folder, string $tags): void {
		$this->setNextcloudTags($folder, $this->parseTags($tags));
	}

	/** @When I change the Nextcloud tags on :path to :tags */
	public function iChangeTheNextcloudTagsOnTo(string $path, string $tags): void {
		$this->setNextcloudTags($path, $this->parseTags($tags));
	}

	/** @Then the folder :folder is tagged :tags in Nextcloud */
	public function theFolderIsTaggedInNextcloud(string $folder, string $tags): void {
		$this->assertNextcloudTags($folder, $tags);
	}

	/** @Then the tags on :path are :tags in Nextcloud */
	public function theTagsOnAreInNextcloud(string $path, string $tags): void {
		$this->assertNextcloudTags($path, $tags);
	}

	/** @Then the tags on the Grafana folder :uid are :tags */
	public function theTagsOnTheGrafanaFolderAre(string $uid, string $tags): void {
		$this->assertSameTags(
			$this->parseTags($tags),
			$this->grafanaFolderTags($uid),
			"the tags on the Grafana folder $uid",
		);
	}

	/** @Then the tags in the file :path are :tags */
	public function theTagsInTheFileAre(string $path, string $tags): void {
		$decoded = json_decode($this->davGet($path), true);
		if (!is_array($decoded)) {
			throw new \RuntimeException("the file $path is not JSON");
		}
		$actual = array_values(array_filter(array_map('trim', (array)($decoded['tags'] ?? []))));
		$this->assertSameTags($this->parseTags($tags), $actual, "the tags in the body of $path");
	}

	/**
	 * @Then the file :path can be found by a Nextcloud tag search for :tag
	 *
	 * Asserted through a REPORT against the tag, which is the query the Files app's
	 * own tag filter runs — so this proves the tag is findable, not merely stored.
	 */
	public function theFileCanBeFoundByATagSearchFor(string $path, string $tag): void {
		$tagId = $this->findTagId($tag);
		if ($tagId === null) {
			throw new \RuntimeException("Nextcloud has no tag named \"$tag\", so nothing could match a search for it");
		}

		$body = '<?xml version="1.0"?>'
			. '<oc:filter-files xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			. '<oc:filter-rules><oc:systemtag>' . $tagId . '</oc:systemtag></oc:filter-rules>'
			. '</oc:filter-files>';
		$res = $this->davClient()->request('REPORT', '', [
			'headers' => ['Content-Type' => 'application/xml'],
			'body' => $body,
		]);
		$body = (string)$res->getBody();
		if (!str_contains($body, rawurlencode(basename($path))) && !str_contains($body, basename($path))) {
			throw new \RuntimeException("a tag search for \"$tag\" did not return $path");
		}
	}

	// ── dashboards ─────────────────────────────────────────────────────────────

	/**
	 * @Given a dashboard file in :folder whose tags are :tags
	 *
	 * Seeded through the FILE, not through Grafana, because that is the pre-state the
	 * scenarios describe — a mirror that already carries tags. The write goes out over
	 * DAV so the app's own push carries it to Grafana, which means the arrange also
	 * proves the plumbing the scenario is about to exercise actually exists.
	 */
	public function aDashboardFileInWhoseTagsAre(string $folder, string $tags): void {
		// A LINK MAPPING CANNOT BE WRITTEN INTO — that refusal is a shipped feature, not
		// an obstacle. So a link mirror is seeded the only way one really appears: the
		// dashboard is made in Grafana and pulled down. This branch was dead until the
		// table-based mapping arrange started recording modes; it works now.
		if (($this->mappingModes[$folder] ?? '') === 'link') {
			$this->seedMirrorViaPull($folder, 'Linked ' . bin2hex(random_bytes(3)));
			if (trim($tags) !== '') {
				$this->grafanaCreateTaggedDashboard(
					$this->lastUid,
					basename($this->originalPath, '.grafana.json'),
					$this->grafanaFolderUidForMapping($folder),
					$this->parseTags($tags),
				);
				$this->theAdminPullsFromGrafana();
			}
			return;
		}

		$this->aDashboardFileIn($folder);
		// `the file holds:` reads the CURSOR, not the original — and the arrange above
		// only sets the latter, so without this every Modified assertion in this file
		// fails on "no file to inspect" rather than on anything it is about.
		$this->currentFilePath = $this->originalPath;
		if (trim($tags) !== '') {
			$this->writeTagsIntoFile($this->originalPath, $this->parseTags($tags));
		}
	}

	/** @When I change the Nextcloud tags to :tags */
	public function iChangeTheNextcloudTagsTo(string $tags): void {
		$this->setNextcloudTags($this->originalPath, $this->parseTags($tags));
	}

	/** @When I change the tags in the file to :tags */
	public function iChangeTheTagsInTheFileTo(string $tags): void {
		$this->writeTagsIntoFile($this->originalPath, $this->parseTags($tags));
	}

	/**
	 * @When the dashboard's tags are changed to :tags in Grafana
	 *
	 * The pull that brings it back is the step's business, not the scenario's — nobody
	 * performs a sync as an act of intent, so it does not appear in the Gherkin.
	 */
	public function theDashboardsTagsAreChangedToInGrafana(string $tags): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		$spec = $record['dashboard'] ?? [];
		$spec['tags'] = $this->parseTags($tags);
		$this->grafanaCreateTaggedDashboard(
			$this->lastUid,
			(string)($spec['title'] ?? 'Untitled'),
			(string)($record['meta']['folderUid'] ?? ''),
			$this->parseTags($tags),
		);
		$this->theAdminPullsFromGrafana();
	}

	/** @Then the dashboard's tags are :tags in Nextcloud */
	public function theDashboardsTagsAreInNextcloud(string $tags): void {
		$this->assertNextcloudTags($this->originalPath, $tags);
	}

	/** @Then the dashboard's tags are :tags in the file */
	public function theDashboardsTagsAreInTheFile(string $tags): void {
		$this->theTagsInTheFileAre($this->originalPath, $tags);
	}

	/**
	 * @Then the dashboard's tags are :tags in Grafana
	 * @Then the dashboard's tags are still :tags in Grafana
	 *
	 * Both phrasings mean the same assertion. "still" reads better after a gesture
	 * that was supposed to change nothing, and a scenario should not have to pick a
	 * worse sentence to reuse a step.
	 */
	public function theDashboardsTagsAreInGrafana(string $tags): void {
		$record = $this->grafanaGetDashboard($this->lastUid);
		$actual = array_values(array_filter(array_map('trim', (array)($record['dashboard']['tags'] ?? []))));
		$this->assertSameTags($this->parseTags($tags), $actual, "the tags on Grafana dashboard {$this->lastUid}");
	}

	/**
	 * @Then the file's tags settle back to :tags
	 *
	 * A link's state is Grafana's, so the local change is not refused — it is simply
	 * overwritten the next time the mirror is brought up to date.
	 */
	public function theFilesTagsSettleBackTo(string $tags): void {
		$this->theAdminPullsFromGrafana();
		$this->theTagsInTheFileAre($this->originalPath, $tags);
	}

	/** @Then the file can be found by a Nextcloud tag search for :tag */
	public function theCurrentFileCanBeFoundByATagSearchFor(string $tag): void {
		$this->theFileCanBeFoundByATagSearchFor($this->originalPath, $tag);
	}

	/**
	 * Put a tag set into a dashboard file's body, over DAV.
	 *
	 * @param list<string> $tags
	 */
	private function writeTagsIntoFile(string $path, array $tags): void {
		$decoded = json_decode($this->davGet($path), true);
		if (!is_array($decoded)) {
			throw new \RuntimeException("the file $path is not JSON");
		}
		$decoded['tags'] = array_values($tags);
		$this->davPut($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	// ── helpers ────────────────────────────────────────────────────────────────

	/**
	 * Make a node carry exactly these tags, through the systemtags DAV relation.
	 *
	 * A PUT to the relation collection assigns one tag; there is no "replace the set"
	 * verb, so the current set is read and the difference applied — the same shape the
	 * app's own {@see \OCA\GrafanaSync\Service\NextcloudTags} has to use, for the same
	 * reason.
	 *
	 * @param list<string> $tags
	 */
	private function setNextcloudTags(string $path, array $tags): void {
		$fileId = $this->fileIdOf($path);

		foreach ($tags as $name) {
			$id = $this->findTagId($name) ?? $this->createTag($name);
			$this->davClient()->request(
				'PUT',
				'../../systemtags-relations/files/' . $fileId . '/' . $id,
				['http_errors' => false],
			);
		}

		foreach ($this->nextcloudTagsOf($path) as $existing) {
			if (in_array($existing, $tags, true)) {
				continue;
			}
			$id = $this->findTagId($existing);
			if ($id !== null) {
				$this->davClient()->request(
					'DELETE',
					'../../systemtags-relations/files/' . $fileId . '/' . $id,
					['http_errors' => false],
				);
			}
		}
	}

	private function assertNextcloudTags(string $path, string $tags): void {
		$this->assertSameTags(
			$this->parseTags($tags),
			$this->nextcloudTagsOf($path),
			"the Nextcloud tags on $path",
		);
	}

	/**
	 * Compare two tag sets, order-insensitively, WITHOUT a PHPUnit assertion.
	 *
	 * PHPUnit 12's failure exporter reaches into `PHPUnit\TextUI\Configuration\Registry`,
	 * which is null under Behat because there is no TextUI bootstrap — so a failing
	 * `assertSame` on two arrays throws an opaque "Registry::get(): … null returned"
	 * TypeError that hides the actual mismatch. {@see \OCA\GrafanaSync\Tests\Integration\Support\WebDavTrait::assertStatus()}
	 * already documents this for statuses; the same applies to every value comparison
	 * here, and arrays hit it hardest because they always build a diff.
	 *
	 * @param list<string> $expected
	 * @param list<string> $actual
	 */
	private function assertSameTags(array $expected, array $actual, string $what): void {
		sort($expected);
		sort($actual);
		if ($expected !== $actual) {
			throw new \RuntimeException(
				$what . " are wrong.\n  expected: [" . implode(', ', $expected) . ']'
				. "\n  actual:   [" . implode(', ', $actual) . ']',
			);
		}
	}

	/** @return list<string> */
	private function nextcloudTagsOf(string $path): array {
		$res = $this->davClient()->request(
			'PROPFIND',
			'../../systemtags-relations/files/' . $this->fileIdOf($path),
			[
				'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
				'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
					. '<d:prop><oc:display-name/></d:prop></d:propfind>',
				'http_errors' => false,
			],
		);
		return $this->displayNames((string)$res->getBody());
	}

	private function findTagId(string $name): ?string {
		$res = $this->davClient()->request('PROPFIND', '../../systemtags/', [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
				. '<d:prop><oc:id/><oc:display-name/></d:prop></d:propfind>',
		]);

		$xml = simplexml_load_string((string)$res->getBody());
		if ($xml === false) {
			return null;
		}
		$xml->registerXPathNamespace('d', 'DAV:');
		$xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		foreach ($xml->xpath('//d:response') ?: [] as $response) {
			$response->registerXPathNamespace('d', 'DAV:');
			$response->registerXPathNamespace('oc', 'http://owncloud.org/ns');
			$names = $response->xpath('.//oc:display-name');
			$ids = $response->xpath('.//oc:id');
			if ($names && $ids && trim((string)$names[0]) === $name) {
				return trim((string)$ids[0]);
			}
		}
		return null;
	}

	private function createTag(string $name): string {
		$this->davClient()->request('POST', '../../systemtags/', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode([
				'name' => $name,
				'userVisible' => true,
				'userAssignable' => true,
			], JSON_THROW_ON_ERROR),
			'http_errors' => false,
		]);
		$id = $this->findTagId($name);
		if ($id === null) {
			throw new \RuntimeException("could not create the Nextcloud tag \"$name\"");
		}
		return $id;
	}

	/**
	 * The numeric file id, which every systemtags URL is keyed on.
	 *
	 * PROPFIND for `oc:fileid` rather than parsing it out of anything else — it is the
	 * one identifier that survives a rename or a move, which is the same property the
	 * app's own metadata relies on.
	 */
	private function fileIdOf(string $path): string {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
				. '<d:prop><oc:fileid/></d:prop></d:propfind>',
		]);
		if (preg_match('#<oc:fileid>(\d+)</oc:fileid>#', (string)$res->getBody(), $m) !== 1) {
			throw new \RuntimeException("could not read the file id of $path");
		}
		return $m[1];
	}

	/** @return list<string> */
	private function displayNames(string $xml): array {
		if (preg_match_all('#<oc:display-name>([^<]*)</oc:display-name>#', $xml, $m) === false) {
			return [];
		}
		$out = [];
		foreach ($m[1] ?? [] as $name) {
			$trimmed = trim(html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
			if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
				$out[] = $trimmed;
			}
		}
		return $out;
	}

	/**
	 * A spec cell like `dns, linux` into a list. An empty cell is an empty set, which
	 * is how the outlines express "clear every tag".
	 *
	 * @return list<string>
	 */
	private function parseTags(string $joined): array {
		$out = [];
		foreach (explode(',', $joined) as $tag) {
			$trimmed = trim($tag);
			if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
				$out[] = $trimmed;
			}
		}
		return $out;
	}
}
