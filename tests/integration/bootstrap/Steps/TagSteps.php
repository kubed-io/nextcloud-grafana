<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

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
		Assert::assertNotSame('', $this->currentGrafanaFolder, 'no Grafana folder in play');
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
		$expected = $this->parseTags($tags);
		sort($expected);
		Assert::assertSame(
			$expected,
			$this->grafanaFolderTags($uid),
			"the Grafana folder $uid does not carry the expected tags",
		);
	}

	/** @Then the tags in the file :path are :tags */
	public function theTagsInTheFileAre(string $path, string $tags): void {
		$decoded = json_decode($this->davGet($path), true);
		Assert::assertIsArray($decoded, "the file $path is not JSON");
		$actual = array_values(array_filter(array_map('trim', (array)($decoded['tags'] ?? []))));
		sort($actual);
		$expected = $this->parseTags($tags);
		sort($expected);
		Assert::assertSame($expected, $actual, "the body of $path does not carry the expected tags");
	}

	/**
	 * @Then the file :path can be found by a Nextcloud tag search for :tag
	 *
	 * Asserted through a REPORT against the tag, which is the query the Files app's
	 * own tag filter runs — so this proves the tag is findable, not merely stored.
	 */
	public function theFileCanBeFoundByATagSearchFor(string $path, string $tag): void {
		$tagId = $this->findTagId($tag);
		Assert::assertNotNull($tagId, "Nextcloud has no tag named \"$tag\"");

		$body = '<?xml version="1.0"?>'
			. '<oc:filter-files xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			. '<oc:filter-rules><oc:systemtag>' . $tagId . '</oc:systemtag></oc:filter-rules>'
			. '</oc:filter-files>';
		$res = $this->davClient()->request('REPORT', '', [
			'headers' => ['Content-Type' => 'application/xml'],
			'body' => $body,
		]);
		Assert::assertStringContainsString(
			rawurlencode(basename($path)),
			str_replace('%2F', '/', rawurlencode((string)$res->getBody())),
			"a tag search for \"$tag\" did not return $path",
		);
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
				'../systemtags-relations/files/' . $fileId . '/' . $id,
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
					'../systemtags-relations/files/' . $fileId . '/' . $id,
					['http_errors' => false],
				);
			}
		}
	}

	private function assertNextcloudTags(string $path, string $tags): void {
		$expected = $this->parseTags($tags);
		sort($expected);
		$actual = $this->nextcloudTagsOf($path);
		sort($actual);
		Assert::assertSame($expected, $actual, "$path does not carry the expected Nextcloud tags");
	}

	/** @return list<string> */
	private function nextcloudTagsOf(string $path): array {
		$res = $this->davClient()->request(
			'PROPFIND',
			'../systemtags-relations/files/' . $this->fileIdOf($path),
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
		$res = $this->davClient()->request('PROPFIND', '../systemtags/', [
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
		$this->davClient()->request('POST', '../systemtags/', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode([
				'name' => $name,
				'userVisible' => true,
				'userAssignable' => true,
			], JSON_THROW_ON_ERROR),
			'http_errors' => false,
		]);
		$id = $this->findTagId($name);
		Assert::assertNotNull($id, "could not create the Nextcloud tag \"$name\"");
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
			Assert::fail("could not read the file id of $path");
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
