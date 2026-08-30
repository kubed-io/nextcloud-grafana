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
 * Folder-mapping steps: the "admin maps a Grafana folder to a Nextcloud folder"
 * use case, driven over occ (`grafana_sync:add-mapping` / `list-mappings`) — the
 * same MappingService the admin Settings panel writes through. Composed into
 * {@see \OCA\GrafanaSync\Tests\Integration\FeatureContext}.
 *
 * A mapping stores a Grafana folder **uid**; this config-only feature does not
 * validate the uid against a live Grafana, so the steps pass the folder name as
 * its own uid (and title). The mapping CRUD + validation is what's under test.
 *
 * ## ONE VOCABULARY FOR THE PRE-STATE AND THE ACTION
 *
 * `a mapping with the following values:` and `the admin maps the Grafana folder
 * :uid with:` take the SAME table, because they describe the same object — one as
 * something that is already true, one as something being done. That is what lets
 * a uniqueness scenario put a mapping in the pre-state and then perform the very
 * action that created it, with the difference visible in the table rather than
 * hidden in two differently-worded steps.
 *
 * A blank cell means "the admin left this field alone", so it is dropped from the
 * payload entirely and the app applies its own default. That is the only way an
 * Examples row can say "unset" — an empty string is a value, and here it would
 * test the validator rather than the default.
 */
trait MappingSteps {
	/**
	 * Fail with a message that SURVIVES.
	 *
	 * PHPUnit's assertions are unusable for diagnosis inside Behat: when one
	 * fails, its formatter reaches for `PHPUnit\TextUI\Configuration\Registry`,
	 * which Behat never bootstraps, and the run reports
	 *
	 *     Type error: Registry::get(): Return value must be of type
	 *     Configuration, null returned
	 *
	 * INSTEAD OF THE ASSERTION MESSAGE. The failure looks like a tooling
	 * incompatibility, the actual cause is invisible, and every diagnosis costs a
	 * full CI cycle — it cost three on this change alone, including one where the
	 * message it ate said exactly what was wrong ("no Team Folder mounted at ...").
	 *
	 * So the steps whose message IS the diagnosis throw plainly instead. The
	 * sibling penpot app arrived at the same thing for the same reason.
	 *
	 * @throws \RuntimeException
	 */
	private function fail(string $message): never {
		throw new \RuntimeException($message);
	}

	/** @var array<string,string> the last form submitted, for the post-condition */
	private array $lastMappingForm = [];

	/** @var array<string,string> what an unset field is expected to become */
	private array $mappingDefaults = [];

	/** @Given no Grafana folders are mapped */
	public function noGrafanaFoldersAreMapped(): void {
		foreach ($this->listMappings() as $m) {
			$id = (string)($m['id'] ?? '');
			if ($id !== '') {
				$this->occ('grafana_sync:remove-mapping ' . escapeshellarg($id));
			}
		}
		Assert::assertSame([], $this->listMappings(), 'the mapping store did not empty');
	}

	/**
	 * @Given an unset field on the mapping form defaults to:
	 *
	 * KEEPS BLANK CELLS, unlike every other table here. This one DECLARES what a
	 * default is rather than submitting a form, so `| groups | |` is the assertion
	 * "the default is nothing" — dropping it would leave the row decorative and the
	 * scenario would keep passing if the app started defaulting groups to
	 * something. The two tables look alike and mean opposite things.
	 */
	public function anUnsetFieldDefaultsTo(TableNode $table): void {
		$out = [];
		foreach ($table->getRowsHash() as $field => $value) {
			$out[(string)$field] = trim((string)$value);
		}
		$this->mappingDefaults = $out;
	}

	/** Whether this scenario has already reset the store — see the step's docblock. */
	private bool $mappingsDeclared = false;

	/** How many mappings were stored when the last create was attempted. */
	private ?int $mappingsBeforeCreate = null;

	/**
	 * Re-arm the once-per-scenario reset. Without it the second scenario in a
	 * feature would append to the first one's leftovers.
	 *
	 * @BeforeScenario
	 */
	public function armMappingReset(): void {
		$this->mappingsDeclared = false;
		$this->mappingsBeforeCreate = null;
		$this->filesBeforeTeardown = [];
		$this->dashboardFoldersBeforeTeardown = [];
	}

	/**
	 * @Given a mapping with the following values:
	 *
	 * The pre-state twin of `the admin maps the Grafana folder :uid with:`.
	 *
	 * REPEATING IT DECLARES ANOTHER MAPPING; it does not replace the first. The
	 * reset happens once per scenario, on the first use, which is what the isolation
	 * was ever for — starting from a known count instead of inheriting whatever the
	 * previous scenario left behind. Resetting on EVERY use meant a Background could
	 * only ever describe one mapping, and silently: the second table wiped the first
	 * and nothing said so.
	 */
	public function aMappingWithTheFollowingValues(TableNode $table): void {
		$this->declareMapping($this->formValues($table));
	}

	/**
	 * Declare one mapping as pre-state, from an already-parsed form.
	 *
	 * SHARED WITH THE PLURAL, `the following mappings were made:` — the upright
	 * table and the row-per-mapping table are two spellings of one arrange, and the
	 * once-per-scenario reset below is the part that must not be duplicated. Two
	 * copies of it and a Background using both forms would clear the first form's
	 * mappings when it reached the second.
	 *
	 * @param array<string, string> $form
	 */
	private function declareMapping(array $form): void {
		if (!$this->mappingsDeclared) {
			$this->noGrafanaFoldersAreMapped();
			// PIN THE WRITEBACK TO INLINE. Without this every PUT is handed to a
			// background job, so a file's uid is not stamped by the time the next step
			// reads it and every assertion downstream sees an empty uid. The older
			// `a folder mapped as … ` arrange has always done this; the table form was
			// added without it, which is why it could only ever be used by scenarios
			// that never looked at a uid.
			$this->forceInlineWriteback();
			$this->mappingsDeclared = true;
		}
		// RECORD THE MODE. Without it no arrange can tell a link mapping from a sync
		// one, and a link mirror cannot be seeded the way it really appears — through
		// a pull — because writing into a link folder is refused by design.
		$ncFolder = (string)($form['nc folder'] ?? '');
		if ($ncFolder !== '') {
			$this->mappingModes[$ncFolder] = (string)($form['mode'] ?? 'link');
		}
		$uid = $form['grafana folder'] ?? '';
		unset($form['grafana folder']);
		// The table names a Grafana folder by uid; make sure Grafana actually has one.
		if ($uid !== '') {
			$this->ensureGrafanaFolder($uid);
		}
		$res = $this->addMappingFromForm($uid, $form);
		Assert::assertSame(0, $res['exit'], "the pre-state mapping could not be created:\n{$res['output']}");

		// TEAR THE MAPPED FOLDER DOWN AFTERWARDS, from BOTH arranges.
		//
		// Saving a mapping provisions its Nextcloud folder and nothing removed it, so a
		// feature naming a fixed folder handed the next one whatever it had mirrored —
		// `mapping/delete.feature` left `Pointers` behind and `connection/sync-now`
		// inherited it, then wrote `Pinned (1).grafana`, then `(2)`.
		//
		// This was tried once before, in this same method, and regressed `motion` and
		// `trash`: teardown deleted mapped folders while their mappings still existed,
		// so the delete cascaded into Grafana and — once the link guard learned to
		// refuse a link folder — could not run at all. Teardown unmaps FIRST now, which
		// is what makes this safe rather than merely tidier.
		$this->trackMappedFolder($ncFolder, (string)($form['storage'] ?? ''));
	}

	/** @When the admin maps the Grafana folder :uid with: */
	public function theAdminMapsTheGrafanaFolderWith(string $uid, TableNode $table): void {
		$form = $this->formValues($table);
		$this->lastMappingForm = ['grafana folder' => $uid] + $form;
		// COUNTED BEFORE THE ATTEMPT, so `no mapping was created` can be relative.
		// See that step for why an absolute count is the wrong assertion.
		$this->mappingsBeforeCreate = count($this->listMappings());
		$this->addMappingFromForm($uid, $form);
	}

	/**
	 * @Then the mapping matches the form, unset fields at their defaults
	 *
	 * Reads back what was stored and compares it against the submitted form,
	 * substituting the declared default for every field the form left blank. One
	 * assertion for the whole object, so a scenario says "it saved what I typed"
	 * rather than listing the fields one at a time.
	 */
	public function theMappingMatchesTheForm(): void {
		$uid = $this->lastMappingForm['grafana folder'] ?? '';
		$m = $this->findMapping($uid);
		Assert::assertNotNull($m, "no mapping was stored for Grafana folder $uid");

		$expected = $this->lastMappingForm + $this->mappingDefaults;

		// The Nextcloud folder defaults to the Grafana folder's TITLE, which these
		// steps set to the uid. The declared default is prose rather than a value,
		// so it is resolved here instead of being written into every Examples row.
		$ncFolder = $expected['nc folder'] ?? '';
		if ($ncFolder === '' || $ncFolder === "the Grafana folder's name") {
			$ncFolder = $uid;
		}
		Assert::assertSame($ncFolder, (string)($m['nc_folder'] ?? ''), 'nc folder');

		Assert::assertSame($expected['mode'] ?? '', (string)($m['mode'] ?? ''), 'mode');
		Assert::assertSame(
			$this->storageToModel($expected['storage'] ?? ''),
			(bool)($m['use_team_folder'] ?? false),
			'storage',
		);
		$wanted = $this->groupList($expected['groups'] ?? '');
		$stored = array_values(array_map('strval', (array)($m['nc_groups'] ?? [])));
		sort($wanted);
		sort($stored);
		Assert::assertSame($wanted, $stored, 'groups');
	}

	/**
	 * @Given the Nextcloud groups :groups exist
	 *
	 * THE GROUPS HAVE TO REALLY EXIST. Nextcloud cannot share a folder with a group
	 * that is not there, so a scenario that just names one and asserts it comes
	 * back would be asserting nothing — which is precisely how the old stored-list
	 * model passed: it echoed its own stored intent back without ever touching a
	 * share.
	 */
	public function theNextcloudGroupsExist(string $groups): void {
		foreach (explode(',', $groups) as $gid) {
			$gid = trim($gid);
			if ($gid !== '') {
				// Idempotent: an existing group makes this a non-zero no-op.
				$this->occ('group:add ' . escapeshellarg($gid));
			}
		}
	}

	/** @When the admin changes that mapping's groups to :groups */
	public function theAdminChangesThatMappingsGroupsTo(string $groups): void {
		$id = (string)($this->listMappings()[0]['id'] ?? '');
		if ($id === '') {
			$this->fail('no mapping to change');
		}
		$this->occ('grafana_sync:set-groups ' . escapeshellarg($id) . ' ' . escapeshellarg($groups));
	}

	/**
	 * @When the Team Folder :folder is shared with the group :group outside this app
	 *
	 * Uses groupfolders' OWN occ command, so the share is made exactly the way an
	 * admin would make it in the Files admin UI — by something that is not this
	 * app. That is the whole point: the next read has to report the FOLDER's
	 * sharing rather than this app's memory of it.
	 *
	 * There is no core `occ` command that creates a plain group share (checked
	 * against a live Nextcloud: core ships `sharing:cleanup-remote-storages`,
	 * `delete-orphan-shares`, `expiration-notification` and `fix-share-owners`,
	 * and nothing that shares). So this scenario is written on a Team Folder,
	 * where groupfolders gives us one.
	 *
	 * `read write delete` rather than the default read-only, so the group is
	 * assigned at the same permissions the app itself grants — otherwise the app
	 * would fix them on the next explicit set and the difference would look like
	 * churn.
	 */
	public function theTeamFolderIsSharedWithTheGroupOutsideThisApp(string $folder, string $group): void {
		$this->theNextcloudGroupsExist($group);

		$res = $this->occ('groupfolders:list --output=json');
		$folders = json_decode($res['output'], true);
		if (!is_array($folders)) {
			$this->fail("groupfolders:list did not return JSON:\n{$res['output']}");
		}

		$id = null;
		foreach ($folders as $f) {
			if (($f['mountPoint'] ?? null) === $folder) {
				$id = (string)($f['id'] ?? '');
				break;
			}
		}
		if ($id === null) {
			$this->fail(sprintf(
				"no Team Folder mounted at '%s'. groupfolders:list reported: %s",
				$folder,
				implode(', ', array_map(static fn (array $f): string => (string)($f['mountPoint'] ?? '?'), $folders)) ?: '(none)',
			));
		}

		$res = $this->occ(sprintf(
			'groupfolders:group %s %s read write delete',
			escapeshellarg((string)$id),
			escapeshellarg($group),
		));
		if ($res['exit'] !== 0) {
			$this->fail("could not share $folder with $group:\n{$res['output']}");
		}
	}

	/**
	 * @Then the mapping's groups are :groups
	 *
	 * Compared as a SET, not a list: which groups the folder is shared with is the
	 * fact, and the order Nextcloud happens to return them in is not.
	 */
	public function theMappingsGroupsAre(string $groups): void {
		$want = $this->groupList($groups);
		$got = array_values(array_map('strval', (array)($this->listMappings()[0]['nc_groups'] ?? [])));
		sort($want);
		sort($got);
		if ($want !== $got) {
			$this->fail(sprintf(
				'expected the mapped folder to be shared with [%s]; it reports [%s]',
				implode(', ', $want),
				implode(', ', $got) ?: '(none)',
			));
		}
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was unexpectedly accepted:\n{$this->lastOutput}");
	}

	/**
	 * @Then the refusal explains :fragment
	 *
	 * A FRAGMENT, NOT THE WHOLE MESSAGE. The scenario's job is to prove the refusal
	 * names the field at fault so an admin knows what to change; pinning the exact
	 * sentence would make every wording improvement a test failure.
	 */
	public function theRefusalExplains(string $fragment): void {
		Assert::assertStringContainsString(
			$fragment,
			$this->lastOutput,
			"the refusal did not mention '$fragment':\n{$this->lastOutput}",
		);
	}

	// ── removing a mapping ────────────────────────────────────────────────────

	/**
	 * @var array<string, list<string>> the tree a mapped folder held before the tear-down,
	 *                                  keyed by the Nextcloud folder
	 */
	private array $filesBeforeTeardown = [];

	/** @var array<string, string|null> where each seeded dashboard sat in Grafana beforehand */
	private array $dashboardFoldersBeforeTeardown = [];

	/**
	 * @When the admin removes the :folder mapping
	 *
	 * NAMED BY ITS GRAFANA FOLDER, because that is what a mapping IS — the id is a
	 * server-minted string no scenario should have to know. The Nextcloud folder is
	 * looked up from the stored mapping rather than restated, so a scenario never has
	 * to say the pair twice.
	 *
	 * IT PINS THE TREE FIRST. `holds the same files it held before` has nothing to
	 * compare against once the gesture has run, and the whole point of the sync half of
	 * this rule is that the tree is untouched.
	 */
	public function theAdminRemovesTheMapping(string $folder): void {
		$mapping = $this->findMapping($folder);
		if ($mapping === null) {
			$this->fail("no mapping uses the Grafana folder '$folder'");
		}
		$ncFolder = trim((string)($mapping['nc_folder'] ?? ''), '/');
		if ($ncFolder !== '') {
			$this->filesBeforeTeardown[$ncFolder] = $this->davTreeUnder($ncFolder);
		}
		// AND WHERE EACH DASHBOARD SITS IN GRAFANA, for the same reason: "still in the
		// X Grafana folder" is a claim about what did NOT change, and after the gesture
		// there is nothing left to compare against.
		$this->dashboardFoldersBeforeTeardown = [];
		foreach ($this->originalDashboardUids as $uid) {
			$record = $this->grafanaGetDashboard($uid);
			$this->dashboardFoldersBeforeTeardown[$uid] = $record === null
				? null
				: (string)($record['meta']['folderUid'] ?? '');
		}
		$this->occ('grafana_sync:remove-mapping ' . escapeshellarg((string)($mapping['id'] ?? '')));
	}

	/**
	 * @Then the :folder mapping is no longer configured
	 *
	 * ASKED BY NAME, not by counting what is left. A total is a claim about the whole
	 * app rather than about this removal — the same reason `no mapping was created`
	 * replaced `there are exactly 0 configured mappings`.
	 */
	public function theMappingIsNoLongerConfigured(string $folder): void {
		if ($this->findMapping($folder) !== null) {
			$this->fail("the '$folder' mapping is still configured after the admin removed it");
		}
	}

	/**
	 * @Then /^"([^"]*)" holds the same files it held before$/
	 *
	 * THE SYNC HALF OF THE TEAR-DOWN, and the assertion the old code failed. A sync
	 * file holds the dashboard JSON itself and may be the last copy of it, so removing
	 * the mapping drops the connection and nothing else — every file, at every depth,
	 * still exactly where it was.
	 */
	public function holdsTheSameFilesItHeldBefore(string $folder): void {
		$folder = trim($folder, '/');
		if (!array_key_exists($folder, $this->filesBeforeTeardown)) {
			$this->fail("nothing pinned what '$folder' held, so there is nothing to compare against");
		}
		$before = $this->filesBeforeTeardown[$folder];
		$after = $this->davTreeUnder($folder);
		sort($before);
		sort($after);
		if ($before !== $after) {
			$this->fail(sprintf(
				"'%s' no longer holds what it held.\n  before: %s\n   after: %s",
				$folder,
				implode(', ', $before) ?: '(nothing)',
				implode(', ', $after) ?: '(nothing)',
			));
		}
	}

	/**
	 * @Then the dashboards are still in the :title Grafana folder
	 *
	 * THE FAR SIDE, WHICH IS THE HALF AN ADMIN ACTUALLY FEARS. Grafana has no undo, and
	 * the old tear-down trashed its connected files — which, with the recycle bin off,
	 * deleted every one of these dashboards. The plural of `the dashboard is in the
	 * :title Grafana folder`, asked of every dashboard the arrange seeded.
	 */
	public function theDashboardsAreStillInTheGrafanaFolder(string $title): void {
		if ($this->originalDashboardUids === []) {
			$this->fail('nothing captured the dashboards to look for');
		}
		if ($this->dashboardFoldersBeforeTeardown === []) {
			$this->fail('nothing pinned where the dashboards sat, so "still" has nothing to mean');
		}
		$root = $this->grafanaFolderUidByTitle($title);
		if ($root === null || $root === '') {
			$this->fail("Grafana has no folder titled '$title', so the mapped folder itself is gone");
		}

		$wrong = [];
		foreach ($this->originalDashboardUids as $uid) {
			$was = $this->dashboardFoldersBeforeTeardown[$uid] ?? null;
			$record = $this->grafanaGetDashboard($uid);
			if ($record === null) {
				$wrong[] = "$uid is gone from Grafana";
				continue;
			}
			$now = (string)($record['meta']['folderUid'] ?? '');
			// COMPARED AGAINST WHERE IT WAS, not merely against "somewhere". A dashboard
			// in a SUBFOLDER of the mapping names its own folder rather than the mapped
			// root, so demanding $root would fail the nested one — and accepting any
			// non-empty answer, which is what this did first, passes a dashboard moved
			// clean out of the mapping. Its own former folder is the only value that is
			// both true for a nested dashboard and false for a moved one.
			if ($now !== $was) {
				$wrong[] = sprintf('%s was in %s and is now in %s', $uid, $was ?? '(nowhere)', $now ?: '(no folder)');
			}
		}
		if ($wrong !== []) {
			$this->fail("the tear-down changed Grafana:\n  " . implode("\n  ", $wrong));
		}
	}

	/**
	 * @Then the :ncFolder folder and the :grafanaFolder Grafana folder both outlive the mapping
	 *
	 * REMOVING A MAPPING NEVER REMOVES A FOLDER, on either side. It is what lets the
	 * admin map the pair again and have it land straight back onto itself, and it is
	 * the invariant a tear-down is most likely to break on the way past — a walk that
	 * empties a tree is one line away from removing the tree.
	 *
	 * Two names because the two sides genuinely have two names: a mapping binds
	 * `links` to `Pointers`, and only one scenario in this file has them agree.
	 */
	public function bothFoldersOutliveTheMapping(string $ncFolder, string $grafanaFolder): void {
		$problems = [];
		if (!$this->davExists(trim($ncFolder, '/'))) {
			$problems[] = "the Nextcloud folder '$ncFolder' is gone";
		}
		if (($this->grafanaFolderUidByTitle($grafanaFolder) ?? '') === '') {
			$problems[] = "the Grafana folder '$grafanaFolder' is gone";
		}
		if ($problems !== []) {
			$this->fail('removing the mapping took a folder with it: ' . implode('; ', $problems));
		}
	}

	/**
	 * @Then no mapping was created
	 *
	 * THE REFUSAL LEFT THE STORE AS IT FOUND IT — asked RELATIVE to whatever was
	 * already configured, which is the only form of the question that survives a
	 * real instance.
	 *
	 * It replaced `there are exactly 0 configured mappings`. That reads as a claim
	 * about the whole app rather than about this create: an admin with ten working
	 * mappings can still be refused an eleventh, and the old sentence says the
	 * opposite. It only ever held because the scenario emptied the store first, so
	 * it was pinning the arrange rather than the behaviour — and it could not be
	 * written at all for a refusal that has to happen ALONGSIDE existing mappings,
	 * which is what both uniqueness scenarios are.
	 */
	public function noMappingWasCreated(): void {
		if ($this->mappingsBeforeCreate === null) {
			$this->fail('nothing tried to create a mapping, so "no mapping was created" proves nothing');
		}
		$now = count($this->listMappings());
		if ($now !== $this->mappingsBeforeCreate) {
			$this->fail(sprintf(
				"the refused mapping was stored anyway: %d configured before the attempt, %d after.\n%s",
				$this->mappingsBeforeCreate,
				$now,
				$this->lastOutput,
			));
		}
	}

	/**
	 * @Then there are exactly :count configured mappings
	 * @Then there is exactly :count configured mapping
	 */
	public function thereAreExactlyNConfiguredMappings(int $count): void {
		Assert::assertCount($count, $this->listMappings(), "expected $count mappings");
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/** "team folder" → true, "admin folder" → false. */
	private function storageToModel(string $storage): bool {
		return str_contains($storage, 'team');
	}

	/**
	 * A table of `| field | value |` rows as an array, with BLANK VALUES DROPPED.
	 *
	 * A blank cell in an Examples row means the admin left the field alone, which
	 * is not the same as submitting an empty string — so it must not reach the
	 * payload at all, or the app would validate the empty value instead of
	 * applying its default.
	 *
	 * @return array<string,string>
	 */
	private function formValues(TableNode $table): array {
		$out = [];
		foreach ($table->getRowsHash() as $field => $value) {
			$value = trim((string)$value);
			if ($value !== '') {
				$out[(string)$field] = $value;
			}
		}
		return $out;
	}

	/**
	 * Group ids from a comma-separated cell.
	 *
	 * @return list<string>
	 */
	private function groupList(string $value): array {
		$out = [];
		foreach (explode(',', $value) as $g) {
			$g = trim($g);
			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/**
	 * Submit a mapping form over occ.
	 *
	 * Only the keys the form actually supplied are sent, so the app's own defaults
	 * apply to the rest — which is the whole point of the blank cell. The title is
	 * always sent alongside the uid: this suite uses the folder name as its own
	 * uid, and the title is what a blank `nc_folder` defaults from.
	 *
	 * @param array<string,string> $form
	 * @return array{exit:int, output:string}
	 */
	private function addMappingFromForm(string $uid, array $form): array {
		$data = ['grafana_folder_uid' => $uid, 'grafana_folder_title' => $uid];
		if (array_key_exists('nc folder', $form)) {
			$data['nc_folder'] = $form['nc folder'];
		}
		if (array_key_exists('mode', $form)) {
			$data['mode'] = $form['mode'];
		}
		if (array_key_exists('groups', $form)) {
			$data['nc_groups'] = $this->groupList($form['groups']);
		}
		if (array_key_exists('storage', $form)) {
			$data['use_team_folder'] = $this->storageToModel($form['storage']);
		}

		return $this->occ('grafana_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
	}

	/**
	 * The configured mappings, decoded from `grafana_sync:list-mappings`.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function listMappings(): array {
		$res = $this->occ('grafana_sync:list-mappings');
		Assert::assertSame(0, $res['exit'], "list-mappings failed:\n{$res['output']}");
		$decoded = json_decode(trim($res['output']), true);
		Assert::assertIsArray($decoded, "list-mappings did not return a JSON array:\n{$res['output']}");
		return $decoded;
	}

	/** @return array<string,mixed>|null */
	private function findMapping(string $uid): ?array {
		foreach ($this->listMappings() as $m) {
			if (($m['grafana_folder_uid'] ?? null) === $uid) {
				return $m;
			}
		}
		return null;
	}
}
