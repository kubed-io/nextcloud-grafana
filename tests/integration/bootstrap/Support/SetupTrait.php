<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Support;

use PHPUnit\Framework\Assert;

/**
 * Shared arrangement for the file-lifecycle suites (create / copy / move / rename /
 * delete). Everything here is setup and plumbing — the assertions live in the
 * `Steps/*` traits so a reader can find "what does this scenario prove" in one place.
 *
 * ── THE FRIENDLY-NAME MAP ────────────────────────────────────────────────────────
 *
 * The feature files say `the Grafana folder "alpha"`, because a spec should read
 * like prose. Grafana knows those folders by uid, and `bin/preload-grafana.sh`
 * creates four of them up front as a CONTROL CASE — dashboards that exist in
 * Grafana without this app's involvement. {@see grafanaFolderUid} is the one place
 * that translation happens; anything not preloaded is created on demand, which is
 * what lets a scenario name a bin folder or a fresh mapping target inline.
 *
 * ── WHY PUSH TIMING IS FORCED TO `sync` ──────────────────────────────────────────
 *
 * The app defaults to pushing asynchronously, which is right in production and
 * wrong in a test: the assertion would race the job. {@see forceSyncTiming} pins it
 * so the push completes inside the WebDAV request. Where a scenario is specifically
 * about the async path, {@see drainJobs} runs the queued job instead — the two are
 * different behaviours and the specs keep them apart.
 */
trait SetupTrait {
	/**
	 * THE LIFECYCLE SUITES DELIBERATELY DO NOT USE THE PRELOADED FOLDERS.
	 *
	 * `bin/preload-grafana.sh` builds nc-alpha…nc-delta as a CONTROL CASE, and
	 * `sync-now.feature` asserts against them exactly — *"alpha-dash holds exactly 1
	 * dashboard file"*. If the lifecycle scenarios created their dashboards in
	 * nc-alpha, every one they left behind would show up in that count.
	 *
	 * That is not hypothetical: it is what happened. A `Board f1e168` created by a
	 * delete scenario survived into the reconcile run and made a passing test fail —
	 * and the failure pointed at reconcile, which was innocent.
	 *
	 * So the spec-friendly names resolve to folders this suite creates and tears down
	 * itself. Nothing here touches the fixtures, and nothing here depends on them
	 * either: a scenario that needs a dashboard makes one.
	 */
	private const FOLDER_UIDS = [];

	/** Grafana folders this scenario created on demand, torn down afterwards. */
	private array $createdGrafanaFolders = [];

	/** Dashboards this scenario created in Grafana, torn down afterwards. */
	private array $createdDashboardUids = [];

	/**
	 * Resolve a spec-friendly folder name to a Grafana folder uid, creating the
	 * folder if it is not one of the preloaded four.
	 */
	private function grafanaFolderUid(string $name): string {
		if (isset(self::FOLDER_UIDS[$name])) {
			return self::FOLDER_UIDS[$name];
		}
		$uid = 'nc-t-' . substr(sha1($name), 0, 10);
		$res = $this->grafanaClient()->request('POST', '/api/folders', [
			'json' => ['uid' => $uid, 'title' => $name],
		]);
		$code = $res->getStatusCode();
		// 200 created, 409/412 already there — both usable.
		if (!in_array($code, [200, 409, 412], true)) {
			throw new \RuntimeException("creating Grafana folder '$name' failed: HTTP $code\n" . (string)$res->getBody());
		}
		// REGISTER FOR TEARDOWN ONLY WHAT WE ACTUALLY CREATED. A 409/412 means the
		// folder was already there — possibly holding dashboards nobody in this suite
		// knows about — and deleting a Grafana folder takes its contents with it. The
		// suite must be safe to run against a Grafana that is not freshly built, and
		// tearing down other people's folders is exactly how a test suite earns a
		// reputation for being unrunnable outside CI.
		if ($code === 200 && !in_array($uid, $this->createdGrafanaFolders, true)) {
			$this->createdGrafanaFolders[] = $uid;
		}
		return $uid;
	}

	/**
	 * Create a mapping and its Nextcloud folder, and remember both as "the current
	 * mapping" so later steps can say "the file" without repeating the path.
	 *
	 * Admin-owned (use_team_folder=false) for the reason SyncSteps documents — it
	 * is the app's default and the cheaper mount, not, as this note used to claim,
	 * because the CI Nextcloud lacks groupfolders. It has not lacked it for a while.
	 */
	private function setupMapping(string $name, string $mode): string {
		$uid = $this->grafanaFolderUid($name);
		// A per-run suffix keeps two scenarios in one CI run from colliding in the
		// admin's home directory, which teardown alone would not prevent.
		$ncFolder = 'gs-' . $name . '-' . substr(sha1($name . getmypid()), 0, 6);
		$json = json_encode([
			'grafana_folder_uid' => $uid,
			'grafana_folder_title' => $name,
			'nc_folder' => $ncFolder,
			'mode' => $mode,
			'format' => 'json',
			'use_team_folder' => false,
		], JSON_THROW_ON_ERROR);
		$res = $this->occ('grafana_sync:add-mapping ' . escapeshellarg($json));
		Assert::assertSame(0, $res['exit'], "adding the '$name' mapping failed:\n{$res['output']}");

		$this->davMkdir($ncFolder);
		$this->mappedFolders[$name] = $ncFolder;
		$this->mappingModes[$name] = $mode;
		$this->currentFolder = $ncFolder;
		return $ncFolder;
	}

	/** The Nextcloud folder for a mapping the scenario set up, by its spec-friendly name. */
	private function mappedFolder(string $name): string {
		Assert::assertArrayHasKey($name, $this->mappedFolders, "no mapping named '$name' was arranged in this scenario");
		return $this->mappedFolders[$name];
	}

	/** A plain, unmapped folder in the admin's home, for the "outside any mapping" cases. */
	private function unmappedFolder(): string {
		if ($this->unmappedFolder === '') {
			$this->unmappedFolder = 'gs-loose-' . substr(sha1('loose' . getmypid()), 0, 6);
			$this->davMkdir($this->unmappedFolder);
		}
		return $this->unmappedFolder;
	}

	/**
	 * A minimal but VALID Grafana dashboard body.
	 *
	 * `panels` is a real array and `templating`/`timepicker` are real objects,
	 * deliberately: this is the fixture that would catch a regression of the
	 * object-vs-array flattening bug, so it has to contain both shapes. Encoded with
	 * JSON_PRETTY_PRINT so a failure dump is readable.
	 */
	private function dashboardBody(string $title, ?string $uid = null): string {
		$spec = [
			'title' => $title,
			'panels' => [],
			'schemaVersion' => 39,
			'templating' => ['list' => []],
			'timepicker' => new \stdClass(),
			'tags' => [],
		];
		if ($uid !== null) {
			$spec['uid'] = $uid;
		}
		return json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Write a dashboard file into a folder and return its path. This is the gesture
	 * that fires create-on-land when the folder is a sync mapping.
	 */
	private function putDashboardFile(string $folder, string $title): string {
		$path = $folder . '/' . $title . '.grafana.json';
		$this->davPut($path, $this->dashboardBody($title));
		$this->currentFilePath = $path;
		return $path;
	}

	/**
	 * Create a managed file in a mapping and capture its uid — the arrangement almost
	 * every lifecycle scenario starts from. Asserts the uid landed, because a scenario
	 * that silently starts from an UNmanaged file would go green for the wrong reason.
	 */
	private function makeManagedFile(string $mappingName, string $title): string {
		$path = $this->putDashboardFile($this->mappedFolder($mappingName), $title);
		$uid = $this->davReadMetadata($path, self::META_UID);
		Assert::assertNotNull($uid, "the file at $path was never stamped with a uid — create-on-land did not run");
		Assert::assertNotSame('', $uid, "the file at $path has an empty uid");
		$this->lastUid = $uid;
		$this->createdDashboardUids[] = $uid;
		return $path;
	}

	/**
	 * Put a dashboard straight into a Grafana folder, through Grafana's own API and
	 * with no involvement from this app — the control case a `link` scenario needs,
	 * since a link file is authored by the PULL and never by a local write.
	 */
	private function seedGrafanaDashboard(string $folderName, string $title): string {
		$uid = 'nc-seed-' . bin2hex(random_bytes(4));
		$res = $this->grafanaClient()->request('POST', '/api/dashboards/db', [
			'json' => [
				// Object decode, not assoc — this fixture carries an empty `timepicker: {}`
				// precisely so the flattening bug would be caught, and an assoc round-trip
				// here would quietly seed `[]` and defeat that.
				'dashboard' => json_decode($this->dashboardBody($title, $uid), false, 512, JSON_THROW_ON_ERROR),
				'folderUid' => $this->grafanaFolderUid($folderName),
				'overwrite' => true,
				'message' => 'integration fixture',
			],
		]);
		$this->check($res->getStatusCode() === 200, "seeding '$title' into '$folderName' failed: " . (string)$res->getBody());
		$this->createdDashboardUids[] = $uid;
		return $uid;
	}

	/**
	 * Fail with a plain exception rather than a PHPUnit assertion.
	 *
	 * PHPUnit 12's failure exporter reaches into `PHPUnit\TextUI\Configuration\Registry`,
	 * which is null under Behat (there is no TextUI bootstrap). So a FAILING PHPUnit
	 * assertion surfaces as an opaque
	 * `Registry::get(): ... null returned` TypeError that hides the real reason — and
	 * costs a whole CI cycle to place. WebDavTrait already documents this for HTTP
	 * statuses; this is the same escape hatch for value checks in the step definitions.
	 */
	private function check(bool $ok, string $message): void {
		if (!$ok) {
			throw new \RuntimeException($message);
		}
	}

	/** Pin the push to run inside the request, so assertions do not race a background job. */
	private function forceSyncTiming(): void {
		$this->occ('config:app:set ' . self::APP_ID . ' timing --value=sync');
	}

	/** Turn the Grafana recycle-bin folder off (the default), explicitly. */
	private function setBinOff(): void {
		$this->occ('config:app:set ' . self::APP_ID . ' bin_enabled --value=0 --type=boolean');
	}

	/** Turn the recycle-bin folder on and point it at a real Grafana folder. */
	private function setBinOn(string $folderTitle): void {
		$this->grafanaFolderUid($folderTitle); // ensure it exists in Grafana
		$this->occ('config:app:set ' . self::APP_ID . ' bin_enabled --value=1 --type=boolean');
		$this->occ('config:app:set ' . self::APP_ID . ' bin_folder --value=' . escapeshellarg($folderTitle));
	}

	/**
	 * Run every queued job of one class to completion. Used only where a scenario is
	 * about the deferred path — the file-locked rename reconcile, or the async push.
	 */
	private function drainJobs(string $jobClass): void {
		$res = $this->occ('background-job:list --class=' . escapeshellarg($jobClass) . ' --output=json');
		$jobs = json_decode($res['output'], true);
		if (!is_array($jobs)) {
			return;
		}
		foreach ($jobs as $job) {
			$id = $job['id'] ?? null;
			if (is_int($id) || (is_string($id) && $id !== '')) {
				$this->occ('background-job:execute ' . escapeshellarg((string)$id) . ' --force-execute');
			}
		}
	}

	/** The Grafana folder uid a dashboard currently lives in, or null if it is gone. */
	private function dashboardFolderUid(string $uid): ?string {
		$record = $this->grafanaGetDashboard($uid);
		if ($record === null) {
			return null;
		}
		$folder = $record['meta']['folderUid'] ?? '';
		return is_string($folder) ? $folder : '';
	}

	/**
	 * Tear down the Grafana side. Nextcloud folders are handled by FeatureContext;
	 * these are the far-side artefacts a lifecycle scenario leaves behind.
	 *
	 * @AfterScenario
	 */
	public function tearDownGrafanaArtefacts(): void {
		foreach ($this->createdDashboardUids as $uid) {
			try {
				$this->grafanaDeleteDashboard($uid);
			} catch (\Throwable) {
				// best-effort: already deleted by the scenario is the common case
			}
		}
		foreach ($this->createdGrafanaFolders as $folderUid) {
			try {
				$this->grafanaClient()->request('DELETE', '/api/folders/' . rawurlencode($folderUid));
			} catch (\Throwable) {
				// best-effort
			}
		}
		$this->createdDashboardUids = [];
		$this->createdGrafanaFolders = [];
		$this->mappedFolders = [];
		$this->mappingModes = [];
		$this->unmappedFolder = '';
		$this->lastUid = '';
		// Leave no bin setting behind for the next scenario.
		$this->occ('config:app:delete ' . self::APP_ID . ' bin_enabled');
		$this->occ('config:app:delete ' . self::APP_ID . ' bin_folder');
		$this->occ('config:app:delete ' . self::APP_ID . ' timing');
	}
}
