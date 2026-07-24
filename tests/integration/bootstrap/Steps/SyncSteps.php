<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * Manual pull steps (saga Ch2 Course 2, "Sync from Grafana"): drive the reconciler
 * over occ (`grafana_sync:pull`, the headless twin of the admin button) and assert
 * the result over WebDAV — the dashboard files appear in the mapped folder, carry
 * the metadata contract, and repeated pulls neither duplicate nor churn. Composed
 * into {@see \OCA\GrafanaSync\Tests\Integration\FeatureContext}.
 *
 * The mapping is created **admin-owned** (use_team_folder=false): the CI Nextcloud
 * checkout has no groupfolders app, so a Team Folder mapping would be skipped as
 * "storage unavailable". Admin-owned writes into the actor's home, which the DAV
 * client (authed as that admin) reads back directly — the same reconcile logic,
 * minus the groupfolders dependency.
 */
trait SyncSteps {
	/**
	 * Create an admin-owned sync mapping from a preloaded Grafana folder to a
	 * Nextcloud folder, and register the folder for teardown.
	 *
	 * @Given an admin-owned mapping from Grafana folder :uid to Nextcloud folder :folder
	 */
	public function anAdminOwnedMappingFromGrafanaFolderToNextcloudFolder(string $uid, string $folder): void {
		$json = json_encode([
			'grafana_folder_uid' => $uid,
			'grafana_folder_title' => $uid,
			'nc_folder' => $folder,
			'mode' => 'sync',
			'format' => 'json',
			'use_team_folder' => false,
		], JSON_THROW_ON_ERROR);
		$res = $this->occ('grafana_sync:add-mapping ' . escapeshellarg($json));
		Assert::assertSame(0, $res['exit'], "adding the mapping failed:\n{$res['output']}");
		// The pull creates this folder in the actor's home; track it so tearDown removes it.
		if (!in_array($folder, $this->createdFolders, true)) {
			$this->createdFolders[] = $folder;
		}
	}

	/**
	 * @When the admin pulls from Grafana
	 */
	public function theAdminPullsFromGrafana(): void {
		$res = $this->occ('grafana_sync:sync pull');
		Assert::assertSame(0, $res['exit'], "pull failed:\n{$res['output']}");
	}

	/**
	 * @When the admin pushes to Grafana
	 */
	public function theAdminPushesToGrafana(): void {
		$res = $this->occ('grafana_sync:sync push');
		Assert::assertSame(0, $res['exit'], "push failed:\n{$res['output']}");
	}

	/**
	 * Edit a pulled dashboard file's title in place (WebDAV GET → change title → PUT),
	 * the way a user editing the JSON in the Files app would. The file id (and its
	 * metadata) is preserved, so the push matches it back to the same dashboard.
	 *
	 * @When the dashboard file :path is edited to title :title
	 */
	public function theDashboardFileIsEditedToTitle(string $path, string $title): void {
		$spec = json_decode($this->davGet($path), true);
		Assert::assertIsArray($spec, "dashboard file '$path' is not valid JSON");
		$spec['title'] = $title;
		$this->davPut($path, (string)json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @Then the Grafana dashboard :uid has title :title
	 */
	public function theGrafanaDashboardHasTitle(string $uid, string $title): void {
		$record = $this->grafanaGetDashboard($uid);
		Assert::assertNotNull($record, "Grafana has no dashboard with uid '$uid'");
		$actual = $record['dashboard']['title'] ?? null;
		Assert::assertSame($title, $actual, "dashboard '$uid' title mismatch");
	}

	/**
	 * @Then a file named :name appears in :folder
	 */
	public function aFileNamedAppearsIn(string $name, string $folder): void {
		Assert::assertTrue(
			$this->davExists($folder . '/' . $name),
			"expected '$name' to exist in '$folder' after the pull",
		);
	}

	/**
	 * @Then no file named :name remains in :folder
	 */
	public function noFileNamedRemainsIn(string $name, string $folder): void {
		Assert::assertFalse(
			$this->davExists($folder . '/' . $name),
			"expected '$name' to have been pruned from '$folder'",
		);
	}

	/**
	 * @Then the file :path is a :mode dashboard for uid :uid
	 */
	public function theFileIsADashboardForUid(string $path, string $mode, string $uid): void {
		Assert::assertSame($uid, $this->davReadMetadata($path, self::META_UID), "uid metadata mismatch on '$path'");
		Assert::assertSame($mode, $this->davReadMetadata($path, self::META_MODE), "mode metadata mismatch on '$path'");
	}

	/**
	 * @Then :folder holds exactly :count dashboard file(s)
	 */
	public function holdsExactlyDashboardFiles(string $folder, int $count): void {
		$files = $this->davListDashboardFiles($folder);
		Assert::assertCount($count, $files, "unexpected dashboard files in '$folder': " . implode(', ', $files));
	}

	/**
	 * @Given a throwaway Grafana dashboard :title with uid :uid in folder :folderUid
	 */
	public function aThrowawayGrafanaDashboardInFolder(string $title, string $uid, string $folderUid): void {
		$this->grafanaCreateDashboard($uid, $title, $folderUid);
	}

	/**
	 * @When the Grafana dashboard with uid :uid is deleted
	 */
	public function theGrafanaDashboardWithUidIsDeleted(string $uid): void {
		$this->grafanaDeleteDashboard($uid);
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * PROPFIND depth-1 a folder and return the basenames of its `.grafana.json`
	 * children — the CI-visible signal for "how many dashboard files landed here".
	 *
	 * @return list<string>
	 */
	private function davListDashboardFiles(string $folder): array {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($folder), [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $folder failed: " . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$out = [];
		foreach ($doc->xpath('//d:href') ?: [] as $href) {
			$base = basename(rtrim(rawurldecode((string)$href), '/'));
			if (str_ends_with($base, '.grafana.json')) {
				$out[] = $base;
			}
		}
		return $out;
	}
}
