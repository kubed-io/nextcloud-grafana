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
 * The mapping is created **admin-owned** (use_team_folder=false), which is also
 * the app's default: it writes into the actor's home, which the DAV client
 * (authed as that admin) reads back directly — the same reconcile logic, minus
 * the groupfolders dependency.
 *
 * This note used to say the CI Nextcloud "has no groupfolders app". It has had
 * one since integration.yml started installing it on every leg, so the reason is
 * now cost and defaultness rather than availability. A scenario that wants a Team
 * Folder can have one.
 */
trait SyncSteps {
	/** The decoded JSON the last `grafana_sync:sync pull` printed — what the run reports. */
	private array $lastPullReport = [];

	/** Mirror etags (name ⇒ etag) as of the last "the mirrors in … are noted". */
	private array $pinnedEtags = [];

	/**
	 * Create an admin-owned sync mapping from a preloaded Grafana folder to a
	 * Nextcloud folder, and register the folder for teardown.
	 *
	 * @Given an admin-owned mapping from Grafana folder :uid to Nextcloud folder :folder
	 */
	public function anAdminOwnedMappingFromGrafanaFolderToNextcloudFolder(string $uid, string $folder): void {
		// CLEAR FIRST, so this is a pre-STATE rather than an accumulation.
		//
		// A mapping is unique on the Grafana uid, so without this the second row of
		// an Examples table that maps the same folder is refused as a duplicate and
		// the scenario fails on its Given. Every scenario in this file wants exactly
		// one mapping anyway, and a pre-state step that quietly depends on what the
		// previous scenario left behind is the thing outlines exist to avoid.
		foreach ($this->listMappingsForSync() as $existing) {
			$id = (string)($existing['id'] ?? '');
			if ($id !== '') {
				$this->occ('grafana_sync:remove-mapping ' . escapeshellarg($id));
			}
		}

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
	 * A REGEX WITH THE VOCABULARY SPELLED OUT, not `:actor syncs :scope`. Behat's
	 * `:name` placeholder matches a quoted string or a single non-space token — so
	 * `the admin` never matches it, and all three rows come back UNDEFINED. The
	 * alternation also makes a typo in an Examples cell a hard failure rather than
	 * a silently different actor.
	 *
	 * @When /^(the admin|the schedule) syncs (one mapping|every mapping)$/
	 *
	 * THE TRIGGER IS DATA, NOT A BEHAVIOUR. Three ways to start the same sync —
	 * the card's button, the section's button, and the clock — so the outline
	 * treats them as columns and this step turns a column into an action.
	 *
	 * "one mapping" and "every mapping" differ only in whether an id is passed;
	 * the schedule is the interesting one, because the only honest way to test it
	 * is to make the real TimedJob run.
	 */
	public function actorSyncsScope(string $actor, string $scope): void {
		if ($actor === 'the schedule') {
			$this->theScheduleFires();

			return;
		}

		$args = 'pull';
		if ($scope === 'one mapping') {
			$id = (string)($this->listMappingsForSync()[0]['id'] ?? '');
			Assert::assertNotSame('', $id, 'no mapping to sync');
			$args .= ' --mapping=' . escapeshellarg($id);
		}

		$res = $this->occ('grafana_sync:sync ' . $args);
		Assert::assertSame(0, $res['exit'], "sync failed:\n{$res['output']}");
		$this->lastPullReport = self::decodeSyncReport((string)$res['output']);
	}

	/**
	 * Make the scheduled pull actually run, rather than asserting it would.
	 *
	 * TWO SAFETY FLOORS STAND BETWEEN A TEST AND A TIMED JOB, and neither can be
	 * waited out in CI: the job's own interval (60s minimum, by design — see
	 * ScheduleInterval) and the worker's last-run gate. So this enables the
	 * schedule, finds the registered job by class, and executes it by id with
	 * `--force-execute`, which bypasses both.
	 *
	 * That is the real job, reading the real setting, calling the real sync. The
	 * alternative — asserting that a row exists in oc_jobs — would prove the job
	 * is registered and nothing about whether it works, which is precisely the gap
	 * this app had: the setting existed for months and nothing read it.
	 */
	private function theScheduleFires(): void {
		$res = $this->occ('config:app:set grafana_sync schedule_enabled --value=1 --type=boolean');
		Assert::assertSame(0, $res['exit'], "could not enable the schedule:\n{$res['output']}");

		$res = $this->occ('background-job:list --class=' . escapeshellarg('OCA\\GrafanaSync\\BackgroundJob\\ScheduledPullJob') . ' --output=json');
		$jobs = json_decode($res['output'], true);
		Assert::assertIsArray($jobs, "background-job:list did not return JSON:\n{$res['output']}");
		Assert::assertNotSame([], $jobs, 'the scheduled pull job is not registered — Application::boot did not add it');

		$id = (string)($jobs[0]['id'] ?? '');
		Assert::assertNotSame('', $id, 'the scheduled pull job has no id');

		$res = $this->occ('background-job:execute ' . escapeshellarg($id) . ' --force-execute');
		Assert::assertSame(0, $res['exit'], "running the scheduled pull failed:\n{$res['output']}");
	}

	/**
	 * @Then the file :path carries its Grafana dates
	 *
	 * BOTH CLOCKS IN ONE SENTENCE, because they are one end state: a mirror wears
	 * the dashboard's times rather than the sync's. Spelled out as two `Then`s it
	 * read like two behaviours; every future thing that produces a mirror wants to
	 * assert exactly this, and now it can in one line.
	 */
	public function theFileCarriesItsGrafanaDates(string $path): void {
		$this->theFileIsDatedWhenItsDashboardChanged($path);
		$this->theFileWasCreatedWhenItsDashboardWas($path);
	}

	/** The panel title the last edit wrote, for the assertion that it arrived. */
	private string $editedPanelTitle = '';

	/**
	 * Edit the file's panels and save — the gesture a person performs. The push is
	 * folded in, as everywhere else: nobody edits a dashboard in order to run a sync.
	 *
	 * @When I edit the file's panels and save
	 */
	public function iEditTheFilesPanelsAndSave(): void {
		// Object decode: an assoc round-trip rewrites the spec's empty `{}` objects as
		// `[]`, which Grafana rejects. Editing the panels must not reshape the rest.
		$spec = json_decode($this->davGet($this->currentFilePath), false, 512, JSON_THROW_ON_ERROR);
		if (!$spec instanceof \stdClass) {
			throw new \RuntimeException('the dashboard file is not a JSON object');
		}
		$this->editedPanelTitle = 'Edited-' . bin2hex(random_bytes(3));
		$spec->panels = [(object)['type' => 'text', 'title' => $this->editedPanelTitle]];
		$this->davPut($this->currentFilePath, json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$this->occ('grafana_sync:sync push');
	}

	/** @Then the dashboard in Grafana holds the file's panels */
	public function theDashboardHoldsTheFilesPanels(): void {
		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		$record = $this->grafanaGetDashboard($uid);
		if ($record === null) {
			throw new \RuntimeException("Grafana has no dashboard '$uid'");
		}
		$titles = array_map(
			static fn (array $p): string => (string)($p['title'] ?? ''),
			(array)($record['dashboard']['panels'] ?? []),
		);
		if (!in_array($this->editedPanelTitle, $titles, true)) {
			throw new \RuntimeException(
				"Grafana's panels are [" . implode(', ', $titles) . "], without '{$this->editedPanelTitle}'",
			);
		}
	}

	/** @return list<array<string,mixed>> */
	private function listMappingsForSync(): array {
		$res = $this->occ('grafana_sync:list-mappings');
		$decoded = json_decode(trim($res['output']), true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @When the admin pulls from Grafana
	 */
	public function theAdminPullsFromGrafana(): void {
		$res = $this->occ('grafana_sync:sync pull');
		Assert::assertSame(0, $res['exit'], "pull failed:\n{$res['output']}");
		$this->lastPullReport = self::decodeSyncReport((string)$res['output']);
	}

	/**
	 * The mirror's "Modified" is the dashboard's own `meta.updated`, not the moment the
	 * pull ran.
	 *
	 * There is deliberately no "and it is not the time the pull ran" companion: the
	 * fixture is pulled moments after it is seeded, so the two clocks are seconds apart
	 * by construction and asserting they differ would assert the harness's scheduling
	 * rather than the behaviour. Clocks that are far apart are covered where they can
	 * be — the unit suite drives them arbitrarily far apart, and the live smoke test
	 * showed February dates on an August pull.
	 *
	 * @Then the file :path is dated when its dashboard changed in Grafana
	 */
	public function theFileIsDatedWhenItsDashboardChanged(string $path): void {
		$record = $this->grafanaGetDashboard($this->dashboardUidFor($path));
		Assert::assertIsArray($record, "the dashboard behind $path is gone from Grafana");
		$updated = strtotime((string)($record['meta']['updated'] ?? ''));
		Assert::assertIsInt($updated, 'Grafana reported no meta.updated to compare against');

		Assert::assertSame(
			$updated,
			$this->davReadTime($path, 'getlastmodified'),
			"the mirror's modification time is not the dashboard's meta.updated",
		);
	}

	/** @Then the file :path was created when its dashboard was created in Grafana */
	public function theFileWasCreatedWhenItsDashboardWas(string $path): void {
		$record = $this->grafanaGetDashboard($this->dashboardUidFor($path));
		Assert::assertIsArray($record, "the dashboard behind $path is gone from Grafana");
		$created = strtotime((string)($record['meta']['created'] ?? ''));
		Assert::assertIsInt($created, 'Grafana reported no meta.created to compare against');

		Assert::assertSame(
			$created,
			$this->davReadTime($path, 'creation_time'),
			"the mirror's creation time is not the dashboard's meta.created",
		);
	}

	/** The uid a mirror is stamped with — the link between the file and its dashboard. */
	private function dashboardUidFor(string $path): string {
		$uid = $this->davReadMetadata($path, self::META_UID);
		Assert::assertNotNull($uid, "the file $path carries no dashboard uid");
		return $uid;
	}

	/**
	 * Pin every mirror's etag in $folder, so a later Then can say whether the run
	 * under test wrote anything.
	 *
	 * ── KEPT WITHOUT A CALLER, ON PURPOSE ────────────────────────────────────
	 *
	 * These three steps drove "Sync from Grafana with nothing changed rewrites
	 * nothing and says so", which was DELETED rather than moved when
	 * reconcile.feature was retired: it asserted an mtime — a result — about the
	 * reconciler — a mechanism — and neither is a behaviour anyone performs.
	 * (features/AGENTS.md#sync-now-scope has the reasoning; the n8n sibling made
	 * the same call.)
	 *
	 * The defect it guarded was real: a pull that rewrote every mirror on every
	 * run left the whole folder reading "Modified a few seconds ago", so a file
	 * you had actually touched was impossible to spot. So the machinery stays,
	 * and re-adding the scenario is one line if it ever earns a home.
	 *
	 * @Given the mirrors in :folder are noted
	 */
	public function theMirrorsAreNoted(string $folder): void {
		$this->pinnedEtags = $this->mirrorEtags($folder);
		Assert::assertNotEmpty($this->pinnedEtags, "nothing was mirrored into $folder, so a second pull proves nothing");
	}

	/**
	 * Every dashboard the run succeeded on was one it did NOT have to rewrite.
	 * `unchanged` is a subset of `succeeded`, so equality is the strongest available
	 * statement of "this run wrote nothing" — and it is a number, which is what an
	 * admin reads.
	 *
	 * @Then the run reports every dashboard as unchanged
	 */
	public function theRunReportsEveryDashboardAsUnchanged(): void {
		Assert::assertArrayHasKey('unchanged', $this->lastPullReport, 'the run reported no `unchanged` count: ' . json_encode($this->lastPullReport));
		Assert::assertSame(
			(int)($this->lastPullReport['succeeded'] ?? -1),
			(int)$this->lastPullReport['unchanged'],
			'the run rewrote files even though nothing changed in Grafana',
		);
	}

	/** @Then no file in :folder was rewritten */
	public function noFileInWasRewritten(string $folder): void {
		Assert::assertNotEmpty($this->pinnedEtags, 'no mirror etags were pinned before the run');
		Assert::assertSame(
			$this->pinnedEtags,
			$this->mirrorEtags($folder),
			'a pull rewrote mirrors whose bodies already matched Grafana',
		);
	}

	/**
	 * Every `.grafana.json` mirror under $folder as `name ⇒ etag`, sorted by name so
	 * two snapshots compare as whole maps. Nextcloud mints a fresh etag on every
	 * write, so an identical map means nothing under the folder was written.
	 *
	 * @return array<string,string>
	 */
	private function mirrorEtags(string $folder): array {
		$etags = [];
		foreach ($this->davListDashboardFiles($folder) as $name) {
			$etags[$name] = $this->davReadEtag($folder . '/' . $name);
		}
		ksort($etags);
		return $etags;
	}

	/**
	 * Pull the run's JSON report out of the command's stdout. `occ` may prefix its own
	 * lines (deprecations, warnings), so we decode from the first `{` rather than
	 * assuming the whole stream is JSON. An undecodable stream yields `[]` — the
	 * counters are then absent, and the Then that wanted them says so.
	 *
	 * @return array<string,mixed>
	 */
	private static function decodeSyncReport(string $output): array {
		$start = strpos($output, '{');
		if ($start === false) {
			return [];
		}
		$decoded = json_decode(substr($output, $start), true);
		return is_array($decoded) ? $decoded : [];
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
		// Object decode, because this PUTs the whole body back. An assoc round-trip
		// would rewrite every empty `{}` in the spec (`timepicker`, a panel's
		// `options`, `fieldConfig.defaults`) as `[]` — so a step that arranges "the
		// user changed the title" would quietly reshape the rest of the dashboard,
		// and the push assertion downstream would be checking a body this step
		// corrupted. Same rule the production pull and push paths follow.
		$spec = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		Assert::assertInstanceOf(\stdClass::class, $spec, "dashboard file '$path' is not a JSON object");
		$spec->title = $title;
		$this->davPut($path, json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
			// BOTH SPELLINGS OF A COLLISION, because a helper that can only see our own
			// would be blind to exactly the file a copy scenario is hunting. Nextcloud
			// names a colliding copy `Board.grafana (1).json`, counting before the last
			// extension; the app renames it to `Board (1).grafana.json`, and a test that
			// could not see the "before" could never fail when the rename did not happen.
			// This is the same blind spot the app itself shipped with — see
			// FilenameCodec::canonicalise().
			if (str_ends_with($base, '.grafana.json') || preg_match('/\.grafana \(\d+\)\.json$/', $base) === 1) {
				$out[] = $base;
			}
		}
		return $out;
	}
}
