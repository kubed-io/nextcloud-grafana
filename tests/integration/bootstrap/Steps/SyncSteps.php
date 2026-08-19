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
	 * A REGEX WITH THE VOCABULARY SPELLED OUT, not `:actor syncs :scope`. Behat's
	 * `:name` placeholder matches a quoted string or a single non-space token — so
	 * `the admin` never matches it, and all three rows come back UNDEFINED. The
	 * alternation also makes a typo in an Examples cell a hard failure rather than
	 * a silently different actor.
	 *
	 * NO LONGER A STEP OF ITS OWN. Every feature now names the direction — `syncs
	 * every mapping from Grafana` — because this file has two buttons and naming only
	 * one of them was how "sync" came to mean "pull" everywhere but the push scenario.
	 * {@see \OCA\GrafanaSync\Tests\Integration\Steps\ResourceSteps} is the caller.
	 *
	 * THE TRIGGER IS DATA, NOT A BEHAVIOUR. Three ways to start the same sync —
	 * the card's button, the section's button, and the clock — so the outline
	 * treats them as columns and this step turns a column into an action.
	 *
	 * "one mapping" and "every mapping" differ only in whether an id is passed;
	 * the schedule is the interesting one, because the only honest way to test it
	 * is to make the real TimedJob run.
	 */
	private function actorSyncsScope(string $actor, string $scope): void {
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

	/**
	 * The mirror image of the gesture above, performed on the far side.
	 *
	 * THE PULL IS FOLDED IN, exactly as the push is for the local edit: nobody edits a
	 * dashboard in Grafana in order to run a sync. The n8n master states the same rule
	 * for the same reason — a scenario naming the sync describes the plumbing rather
	 * than the behaviour.
	 *
	 * The whole spec is re-sent rather than patched: Grafana's `dashboards/db` takes a
	 * complete dashboard, and `overwrite` keys on the uid, so re-posting the record we
	 * just read with new panels is the smallest edit the API actually supports.
	 *
	 * @When someone edits the dashboard's panels in Grafana
	 */
	public function someoneEditsTheDashboardsPanelsInGrafana(): void {
		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		if ($uid === '') {
			throw new \RuntimeException('no dashboard behind the file under test');
		}
		// OBJECT DECODE, and it is not a style choice. An assoc round-trip rewrites the
		// spec's empty `{}` objects as `[]`, and Grafana rejects the result — the same
		// trap `iEditTheFilesPanelsAndSave` documents above. This step reads a record and
		// sends it straight back, so it is the most exposed caller there is.
		$record = $this->grafanaGetDashboardObject($uid);
		if ($record === null) {
			throw new \RuntimeException("Grafana has no dashboard '$uid'");
		}

		$this->editedPanelTitle = 'EditedInGrafana-' . bin2hex(random_bytes(3));
		$spec = $record->dashboard ?? new \stdClass();
		$spec->panels = [(object)['type' => 'text', 'title' => $this->editedPanelTitle]];
		$res = $this->grafanaClient()->request('POST', 'dashboards/db', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode([
				'dashboard' => $spec,
				'folderUid' => (string)($record->meta->folderUid ?? ''),
				'overwrite' => true,
				'message' => 'integration: edited in Grafana',
			], JSON_THROW_ON_ERROR),
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException("editing '$uid' in Grafana failed: " . (string)$res->getBody());
		}
		$this->pullEveryMapping();
	}

	/**
	 * @Then the file holds the dashboard's panels as Grafana has them
	 *
	 * The SYNC half of the mode fork. A sync mirror carries the dashboard's real body,
	 * so the panel Grafana just gained has to be in the file — its absence is the pull
	 * having stamped metadata without writing anything, which looks identical from the
	 * metadata alone.
	 */
	public function theFileHoldsTheDashboardsPanels(): void {
		$body = json_decode($this->davGet($this->currentFilePath), true);
		if (!is_array($body)) {
			throw new \RuntimeException('the mirror is not JSON');
		}
		$titles = array_map(
			static fn ($p): string => is_array($p) ? (string)($p['title'] ?? '') : '',
			(array)($body['panels'] ?? []),
		);
		if (!in_array($this->editedPanelTitle, $titles, true)) {
			throw new \RuntimeException(
				"the mirror's panels are [" . implode(', ', $titles) . "], without '{$this->editedPanelTitle}'",
			);
		}
	}

	/**
	 * @Then the file holds a pointer:
	 *
	 * A LINK'S BODY, SAID OUT LOUD. The negative — "does not hold the dashboard" — never
	 * names what IS there, and what is there is a specific documented shape: a
	 * `grafana.reference/v1` payload carrying the uid, the title and a deep link
	 * ({@see \OCA\GrafanaSync\Service\DashboardBody::encodeReference}). Ported from the
	 * n8n master's step of the same name.
	 *
	 * `panels` is asserted ABSENT first, because that is the whole distinction between
	 * the two modes and the one a pull could plausibly get wrong: a link that gained the
	 * dashboard body would still satisfy every key below.
	 */
	public function theFileHoldsAPointer(TableNode $table): void {
		$body = json_decode($this->davGet($this->currentFilePath), true);
		if (!is_array($body)) {
			throw new \RuntimeException('the link is not JSON');
		}
		if (array_key_exists('panels', $body)) {
			throw new \RuntimeException('a link carries the dashboard body; it should hold only a pointer');
		}

		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		$record = $this->grafanaGetDashboard($uid);
		if ($record === null) {
			throw new \RuntimeException("Grafana has no dashboard '$uid'");
		}

		foreach ($table->getRowsHash() as $key => $expected) {
			$key = trim($key);
			$actual = (string)($body[$key] ?? '');
			$want = match (trim($expected)) {
				"the dashboard's uid" => $uid,
				"the dashboard's title" => (string)($record['dashboard']['title'] ?? ''),
				'a deep link to it in Grafana' => null,
				default => trim($expected),
			};
			// A deep link is only ever asserted to NAME the dashboard: pinning Grafana's
			// URL shape would break on an upgrade that changed it, and the claim is that
			// the link points here, not that it is spelled a particular way.
			if ($want === null) {
				if (!str_contains($actual, $uid)) {
					throw new \RuntimeException("the pointer's $key ('$actual') is not a deep link to '$uid'");
				}
				continue;
			}
			if ($actual !== $want) {
				throw new \RuntimeException("the pointer's $key is '$actual', expected '$want'");
			}
		}
	}

	/**
	 * @When someone creates the dashboard :title in the :folder Grafana folder
	 *
	 * THE PULL IS FOLDED IN, as it is for every other in-Grafana gesture: nobody creates
	 * a dashboard in order to run a sync. The uid is minted here rather than read back so
	 * the teardown can remove it — a dashboard this app mirrors into a mapped folder is
	 * re-mirrored into every later scenario that maps the same folder and pulls.
	 */
	public function someoneCreatesTheDashboardIn(string $title, string $folder): void {
		// `grafanaFolderUidByTitle` lives in TrashSteps and answers null for an unknown
		// folder. Both traits compose into one FeatureContext, so a SECOND copy here was
		// not a helper but a fatal: PHP refuses two traits contributing the same method
		// name, and it takes the whole suite down before a single scenario runs.
		$folderUid = $this->grafanaFolderUidByTitle($folder);
		if ($folderUid === null) {
			throw new \RuntimeException("Grafana has no folder titled '$folder'");
		}
		$uid = 'nc-made-' . bin2hex(random_bytes(3));
		$this->grafanaCreateDashboard($uid, $title, $folderUid);
		$this->createdDashboardUids[] = $uid;
		$this->lastUid = $uid;
		$this->pullEveryMapping();
	}

	/**
	 * @Then the file holds :contents
	 *
	 * THE MODE'S WHOLE OBSERVABLE DIFFERENCE, in one sentence. A sync mirror carries the
	 * dashboard; a link carries a pointer to it. Everything else about the two files —
	 * the name, the uid, the mapping — is identical, so the BODY is the only place the
	 * mode is visible to a person, and the only place a pull can get it wrong.
	 */
	public function theFileHoldsContents(string $contents): void {
		$body = json_decode($this->davGet($this->currentFilePath), true);
		if (!is_array($body)) {
			throw new \RuntimeException('the mirror is not JSON');
		}
		$isPointer = ($body['$schema'] ?? '') === 'grafana.reference/v1';
		switch (trim($contents)) {
			case "the dashboard's full JSON":
				if ($isPointer) {
					throw new \RuntimeException('a sync mirror holds a pointer; it should hold the dashboard');
				}
				if (!array_key_exists('panels', $body)) {
					throw new \RuntimeException('the mirror carries no panels, so it is not the dashboard');
				}
				return;
			case 'a pointer to the dashboard':
				if (!$isPointer) {
					throw new \RuntimeException('a link holds the dashboard body; it should hold only a pointer');
				}
				return;
			default:
				throw new \RuntimeException("'$contents' is not a body this vocabulary knows");
		}
	}

	/** @return list<array<string,mixed>> */
	private function listMappingsForSync(): array {
		$res = $this->occ('grafana_sync:list-mappings');
		$decoded = json_decode(trim($res['output']), true);

		return is_array($decoded) ? $decoded : [];
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
	 * Every `.grafana` mirror under $folder as `name ⇒ etag`, sorted by name so
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
	 * Pull every mapping, as an internal helper.
	 *
	 * NO LONGER A STEP: no feature says "the admin pulls from Grafana" since the
	 * arrange tables landed, but sixteen call sites across seven step traits still
	 * need a pull. Deleting the method because no FEATURE said its sentence broke
	 * every one of them — the annotation was dead, the method very much was not.
	 */
	private function pullEveryMapping(): void {
		$res = $this->occ('grafana_sync:sync pull');
		Assert::assertSame(0, $res['exit'], "pull failed:\n{$res['output']}");
		$this->lastPullReport = self::decodeSyncReport((string)$res['output']);
	}

	/**
	 * @When the admin syncs every mapping to Grafana
	 *
	 * NAMED FOR ITS DIRECTION, like its `from Grafana` twin. It was `the admin pushes
	 * to Grafana`, which no feature said any more — and a section with two buttons
	 * where only one of them names its direction is how "sync" came to mean "pull"
	 * everywhere except here.
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
	 * PROPFIND depth-1 a folder and return the basenames of its `.grafana`
	 * children — the CI-visible signal for "how many dashboard files landed here".
	 *
	 * @return list<string>
	 */
	// ── view.feature: what the Files app shows, and what a client can read ─────

	/**
	 * @When I open :folder in the Files app
	 *
	 * Opening a folder in the Files app IS a Depth-1 PROPFIND — the same request the
	 * browser makes — so this lists it for real and remembers what came back. The
	 * assertion is in the matching Then. Ported from the n8n master's ViewWorkflowSteps.
	 */
	public function iOpenTheFolderInTheFilesApp(string $folder): void {
		$this->currentFolder = $folder;
		$this->viewedFiles = array_map(
			static fn (string $name): string => $folder . '/' . $name,
			$this->davListDashboardFiles($folder),
		);
	}

	/**
	 * @Then the mapped folder shows the dashboards with the Grafana icon
	 *
	 * THE ICON IS A CONSEQUENCE OF THE MIMETYPE, and the mimetype is the testable half.
	 * {@see \OCA\GrafanaSync\Migration\RegisterMimetype} maps `application/grafana+json`
	 * to the app's glyph, so a file carrying that type renders as a dashboard and one
	 * carrying `application/json` renders as a generic document. Behat cannot read
	 * pixels; it can read the type that decides them.
	 *
	 * EVERY FILE THE USER JUST SAW, not merely the last one arranged — a folder where
	 * ONE row falls back to the generic glyph is exactly the failure this catches, and
	 * checking a single file would miss it.
	 */
	public function theMappedFolderShowsTheGrafanaIcon(): void {
		if ($this->viewedFiles === []) {
			throw new \RuntimeException("'{$this->currentFolder}' listed no dashboard files at all");
		}
		foreach ($this->viewedFiles as $path) {
			$type = $this->davContentType($path);
			// THE TYPE, WITHOUT ITS PARAMETERS. `getcontenttype` may legally carry
			// `; charset=utf-8`, and the icon is chosen from the type alone — so an exact
			// string compare would fail a scenario whose subject was entirely correct.
			$bare = trim(explode(';', $type, 2)[0]);
			if ($bare !== 'application/grafana+json') {
				throw new \RuntimeException(
					"$path is served as '$type', not application/grafana+json — its icon would be the generic glyph",
				);
			}
		}
	}

	/**
	 * @When a WebDAV client requests the file's properties
	 *
	 * Performs nothing: every property is read back by the matching `the file holds:`
	 * through a Depth-0 PROPFIND. The step exists so the scenario can name the gesture
	 * a client actually makes, rather than asserting metadata out of nowhere.
	 */
	public function aWebdavClientRequestsTheProperties(): void {
	}

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
			// ONE SPELLING OF A COLLISION IS ENOUGH NOW. This used to have to match the
			// retired `Board.grafana (1).json` too — Nextcloud counted before the last
			// extension, so a copy did not end in the app's extension and a helper blind
			// to that shape could never fail when the app failed to rename it. With a
			// single-segment extension the client's name IS the app's name, so a helper
			// that still accepted the old shape would only be able to report a file this
			// app no longer produces.
			if (str_ends_with($base, '.grafana')) {
				$out[] = $base;
			}
		}
		return $out;
	}
}
