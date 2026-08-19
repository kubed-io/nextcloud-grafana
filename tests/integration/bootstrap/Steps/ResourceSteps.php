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
 * Declares one side of the world as a TREE, and asserts one side as a TREE.
 *
 * ## WHY A TABLE OF PATHS REPLACED A PILE OF ARRANGES
 *
 * `an admin-owned mapping from Grafana folder … to Nextcloud folder …`, `the
 * Grafana folder … already contains:` and `a folder mapped as …` each declared one
 * sliver of the world, and every one of them could only describe a FLAT folder. A
 * Background wanting a folder inside a folder could not say so at all — which is
 * why no scenario in this suite had ever placed a dashboard in a Grafana subfolder,
 * and why the pull could flatten an entire tree onto the mapping's root with the
 * whole suite green. See `features/AGENTS.md#the-tree-is-the-assertion`.
 *
 * ## ARRANGE AND ASSERT ARE DELIBERATELY DIFFERENT SENTENCES
 *
 * Behat matches a step by its TEXT, not by its keyword, so `Given Grafana holds
 * these resources:` and `Then Grafana holds these resources:` would be the same
 * definition doing opposite jobs. The assertion says `holds exactly these
 * resources:` instead — which is not merely a way to tell them apart, it is the
 * claim: the tree is the whole tree, extra files included, or the scenario fails.
 *
 * ## THE PATHS
 *
 *   /alpha                  a Grafana TOP-LEVEL folder, or a Nextcloud folder
 *   /alpha/Region           a folder inside it, at any depth
 *   /alpha/Region/Latency   a dashboard (Grafana) — the leaf is a title
 *   /Alpha/Region/x.grafana a mirror (Nextcloud) — the leaf is a filename
 *
 * A top-level Grafana folder is created with uid == title, because that is what
 * `a mapping with the following values:` stores when a scenario names a folder.
 * Nested folders take whatever uid Grafana mints — a folder cannot be created with
 * a chosen one — so this trait keeps a path ⇒ uid map for the scenario's lifetime.
 *
 * A dashboard's uid is DERIVED from its path rather than declared, so a table that
 * does not care about uids does not have to invent them. A scenario that does care
 * may still add a `uid` column.
 */
trait ResourceSteps {
	/** Grafana path (`/alpha/Region`) ⇒ the folder uid behind it, this scenario. */
	private array $grafanaFolderPaths = [];

	/**
	 * @BeforeScenario
	 *
	 * The map is scenario-scoped: an Examples row that re-declares `/alpha/Region`
	 * gets a NEW Grafana folder (teardown deleted the last one), and a stale uid here
	 * would quietly seed the next row's dashboards into a folder that no longer
	 * exists — where they would be invisible to the sync and the failure would read
	 * as "the pull wrote nothing".
	 */
	public function resetGrafanaFolderPaths(): void {
		$this->grafanaFolderPaths = [];
	}

	// ── Grafana: arrange ──────────────────────────────────────────────────────

	/**
	 * @Given /^Grafana holds these resources:$/
	 *
	 * Columns: `path` (required), `type` (`folder`|`dashboard`, default inferred),
	 * `uid` and `tags` (both optional).
	 */
	public function grafanaHoldsTheseResources(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$path = $this->requirePath($row);
			$type = trim((string)($row['type'] ?? ''));
			$tags = $this->parseTags(trim((string)($row['tags'] ?? '')));

			if ($type === 'folder') {
				// A FOLDER MAY CARRY TAGS, and this used to refuse them. Grafana tags
				// dashboards natively and folders not at all, which is true and was the
				// wrong conclusion: a mirrored folder's tags live in the
				// `nextcloud.kubed.io/tags` annotation, they are as real as any other
				// pre-state, and an admin's own API call is exactly how they get there
				// before this app has ever connected. `folders/tags.feature` needs to
				// say so in its Background.
				$uid = $this->ensureGrafanaFolderPath($path);
				if ($tags !== []) {
					$this->grafanaSetFolderTags($uid, $tags);
				}
				continue;
			}

			// A dashboard: the leaf is its title and everything above it is the folder
			// it lives in, which is created on the way past if the table never named it.
			$title = $this->leafOf($path);
			$folderUid = $this->ensureGrafanaFolderPath($this->parentOf($path));
			$uid = trim((string)($row['uid'] ?? '')) ?: $this->derivedDashboardUid($path);

			if ($tags !== []) {
				$this->grafanaCreateTaggedDashboard($uid, $title, $folderUid, $tags);
			} else {
				$this->grafanaCreateDashboard($uid, $title, $folderUid);
			}
			// So `the dashboard's uid` can resolve a title the scenario never gave a uid.
			$this->seededDashboards[$title] = $uid;
		}
	}

	// ── Grafana: assert ───────────────────────────────────────────────────────

	/**
	 * @Then /^Grafana holds exactly these resources:$/
	 *
	 * Exhaustive beneath every top-level folder the table mentions. Folders the table
	 * does not reach into are not inspected — a scenario about `/alpha` should not
	 * fail because some other feature's fixture is still sitting in `/bravo`.
	 */
	public function grafanaHoldsExactlyTheseResources(TableNode $table): void {
		$want = [];
		$wantTags = [];
		$folderRows = [];
		foreach ($table->getHash() as $row) {
			$path = $this->requirePath($row);
			$want[] = $path;
			$tags = trim((string)($row['tags'] ?? ''));
			if ($tags === '') {
				continue;
			}
			$wantTags[$path] = $tags;
			$folderRows[$path] = trim((string)($row['type'] ?? '')) === 'folder';
		}
		sort($want);

		$roots = [];
		foreach ($want as $path) {
			$roots[$this->rootOf($path)] = true;
		}

		$got = [];
		foreach (array_keys($roots) as $root) {
			$got = array_merge($got, $this->grafanaTreeUnder($root));
		}
		sort($got);

		$this->assertTree('Grafana', $want, $got);

		// The tree first, tags second — see the Nextcloud twin for why.
		foreach ($wantTags as $path => $tags) {
			if ($folderRows[$path] ?? false) {
				$this->assertSameTags(
					$this->parseTags($tags),
					$this->grafanaFolderTags($this->ensureGrafanaFolderPath($path)),
					"the tags on the Grafana folder '$path'",
				);
				continue;
			}
			$this->assertSameTags(
				$this->parseTags($tags),
				$this->grafanaDashboardTags($this->grafanaDashboardUidAtPath($path)),
				"the tags on the Grafana dashboard '$path'",
			);
		}
	}

	/** The uid of the dashboard sitting at a `/folder/Title` path. */
	private function grafanaDashboardUidAtPath(string $path): string {
		$folderUid = $this->ensureGrafanaFolderPath($this->parentOf($path));
		$title = $this->leafOf($path);
		foreach ($this->grafanaSearchDashboards($folderUid) as $row) {
			if ((string)($row['title'] ?? '') === $title) {
				return (string)($row['uid'] ?? '');
			}
		}
		throw new \RuntimeException("Grafana has no dashboard at '$path'");
	}

	/**
	 * A Grafana dashboard's tags.
	 *
	 * @return list<string>
	 */
	private function grafanaDashboardTags(string $uid): array {
		$board = $this->grafanaGetDashboard($uid);
		$tags = $board['dashboard']['tags'] ?? [];
		$out = [];
		foreach ((array)$tags as $tag) {
			$tag = trim((string)$tag);
			if ($tag !== '') {
				$out[] = $tag;
			}
		}
		return $out;
	}

	// ── Nextcloud: arrange ────────────────────────────────────────────────────

	/**
	 * @Given /^Nextcloud holds these resources:$/
	 *
	 * DECLARED BEFORE THE MAPPINGS, ALWAYS. Writing a `.grafana` file into a folder
	 * that is ALREADY mapped is a gesture — the app answers it by creating the
	 * dashboard — so a Background that mapped first could never describe "a file that
	 * has never reached Grafana", which is the entire pre-state a push is about.
	 */
	public function nextcloudHoldsTheseResources(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$path = ltrim($this->requirePath($row), '/');
			$tags = $this->parseTags(trim((string)($row['tags'] ?? '')));

			if ($this->looksLikeFolder($path)) {
				$this->davMkdir($path);
				$this->trackTopFolder($path);
			} else {
				$this->davMkdir(ltrim($this->parentOf($path), '/'));
				$this->trackTopFolder($path);
				$this->davPut($path, $this->bodyFor($path));
			}

			if ($tags !== []) {
				$this->setNextcloudTags($path, $tags);
			}
		}
	}

	// ── Nextcloud: assert ─────────────────────────────────────────────────────

	/**
	 * @Then /^Nextcloud holds exactly these resources:$/
	 *
	 * Columns: `path` (required) and `tags` (optional). Exhaustive under every top
	 * folder the table mentions — see the trait docblock for why that matters more
	 * than it looks.
	 */
	public function nextcloudHoldsExactlyTheseResources(TableNode $table): void {
		$want = [];
		$wantTags = [];
		foreach ($table->getHash() as $row) {
			$path = '/' . ltrim($this->requirePath($row), '/');
			$want[] = $path;
			$tags = trim((string)($row['tags'] ?? ''));
			if ($tags !== '') {
				$wantTags[$path] = $tags;
			}
		}
		sort($want);

		$roots = [];
		foreach ($want as $path) {
			$roots[$this->rootOf($path)] = true;
		}

		$got = [];
		foreach (array_keys($roots) as $root) {
			$got = array_merge($got, $this->davTreeUnder(ltrim($root, '/')));
		}
		sort($got);

		$this->assertTree('Nextcloud', $want, $got);

		// TAGS ARE ASSERTED SECOND, AND ONLY AFTER THE TREE PASSES. A tag mismatch
		// reported over a wrong tree is noise: the useful failure is that the file is
		// in the wrong place, and reading its tags first would bury that.
		foreach ($wantTags as $path => $tags) {
			$this->assertNextcloudTags(ltrim($path, '/'), $tags);
		}
	}

	// ── the state the two sides can be in ─────────────────────────────────────

	/**
	 * @Given /^Grafana and Nextcloud are in sync$/
	 *
	 * A STATE, NOT AN ACTION. A Background says what IS; how the two sides came to
	 * agree — a sync, a fixture, a restored backup — is not something any scenario
	 * below depends on, and naming one in a Given would be inventing history to
	 * explain a fact. See `features/AGENTS.md#a-background-is-a-picture-not-a-story`.
	 *
	 * Making it true happens to be a pull, which is the only lever there is.
	 */
	public function grafanaAndNextcloudAreInSync(): void {
		$res = $this->occ('grafana_sync:sync pull');
		Assert::assertSame(0, $res['exit'], "could not bring the two sides into sync:\n{$res['output']}");
	}

	// ── mappings, in the plural ───────────────────────────────────────────────

	/**
	 * @Given /^the following mappings were made:$/
	 *
	 * The plural of `a mapping with the following values:` — same columns, one row
	 * each. BOTH ARE KEPT: one mapping reads better as one upright table, and three
	 * read better as three rows, and neither is worth converting the other's
	 * twenty-odd feature files to prove a point.
	 */
	public function theFollowingMappingsWereMade(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$form = [];
			foreach ($row as $key => $value) {
				$value = trim((string)$value);
				if ($value !== '') {
					$form[(string)$key] = $value;
				}
			}
			$this->declareMapping($form);
			$this->trackMappedFolder(
				(string)($form['nc folder'] ?? ''),
				(string)($form['storage'] ?? ''),
			);
		}
	}

	/** Team Folder mount points this scenario's mappings provisioned. */
	private array $mappedTeamFolders = [];

	/**
	 * Remember a mapped folder so the scenario does not hand its leftovers to the next
	 * one.
	 *
	 * ## WHY THIS IS HERE AND NOT IN THE MAPPING ARRANGE
	 *
	 * Saving a mapping PROVISIONS its Nextcloud folder, and nothing tears that down.
	 * A Background naming a fixed folder therefore inherits whatever the last scenario
	 * mirrored into it, and the pull writes `Pinned (1)`, `Pinned (2)`, `Pinned (3)`
	 * beside the leftovers — one per Examples row.
	 *
	 * The obvious place for the fix is `declareMapping`, shared by both mapping
	 * arranges. Putting it there broke `motion` and `trash`: the older features name
	 * fixed folders too, and deleting one out from under them changes what they were
	 * written against. They do not NEED it — an assertion that names one file at a
	 * time cannot see the debris — so the cleanup belongs to the arrange whose
	 * assertions look at whole trees, and the wider fix is its own change.
	 *
	 * ## A TEAM FOLDER IS NOT A FOLDER YOU CAN DELETE
	 *
	 * `tearDown` removes the admin's folders over WebDAV, and a groupfolder mount
	 * answers that with a shrug: `Pointers` came back clean and `Shared` did not,
	 * measured. A Team Folder has to go through groupfolders' own occ command, which
	 * is what {@see forgetTeamFolders} does.
	 */
	private function trackMappedFolder(string $ncFolder, string $storage): void {
		if ($ncFolder === '') {
			return;
		}
		if (str_contains($storage, 'team')) {
			$this->mappedTeamFolders[$ncFolder] = true;
			return;
		}
		if (!in_array($ncFolder, $this->createdFolders, true)) {
			$this->createdFolders[] = $ncFolder;
		}
	}

	/**
	 * @AfterScenario
	 *
	 * Best-effort, like every other teardown here: a failure to clean up must never
	 * turn a passing scenario into a failing one.
	 */
	public function forgetTeamFolders(): void {
		if ($this->mappedTeamFolders === []) {
			return;
		}
		$mounts = $this->mappedTeamFolders;
		$this->mappedTeamFolders = [];
		try {
			$res = $this->occ('groupfolders:list --output=json');
			$folders = json_decode((string)$res['output'], true);
			foreach (is_array($folders) ? $folders : [] as $folder) {
				// `mountPoint`, camel-cased — NOT the `mount_point` the rest of this
				// app's config speaks. Guessing it cost a whole CI round: nothing
				// matched, nothing was deleted, and a best-effort teardown has no way
				// to complain about that. {@see MappingSteps::theTeamFolderIsSharedWithTheGroupOutsideThisApp}
				// reads the same key.
				$mount = (string)($folder['mountPoint'] ?? '');
				$id = (string)($folder['id'] ?? '');
				if ($id !== '' && isset($mounts[$mount])) {
					$this->occ('groupfolders:delete ' . escapeshellarg($id) . ' --force');
				}
			}
		} catch (\Throwable) {
			// best-effort cleanup
		}
	}

	// ── syncing, named ────────────────────────────────────────────────────────

	/**
	 * @When /^(the admin|the schedule) syncs every mapping from Grafana$/
	 *
	 * The direction is spelt out because this feature has TWO buttons and naming
	 * only one of them was how "sync" came to mean "pull" everywhere but the push
	 * scenario.
	 */
	public function actorSyncsEveryMappingFromGrafana(string $actor): void {
		$this->actorSyncsScope($actor, 'every mapping');
	}

	/**
	 * @When /^the admin syncs the "([^"]*)" mapping from Grafana$/
	 *
	 * NAMED, not "one mapping". The card's button is only interesting when another
	 * mapping is standing beside it untouched, and a step that syncs whichever
	 * mapping happens to be first cannot express that.
	 */
	public function theAdminSyncsTheMappingFromGrafana(string $ncFolder): void {
		// {@see MirrorSteps::mappingIdForNcFolder} — one lookup, not a second copy of it.
		$id = $this->mappingIdForNcFolder($ncFolder);
		$res = $this->occ('grafana_sync:sync pull --mapping=' . escapeshellarg($id));
		Assert::assertSame(0, $res['exit'], "syncing the '$ncFolder' mapping failed:\n{$res['output']}");
		$this->lastPullReport = self::decodeSyncReport((string)$res['output']);
	}

	// ── the chain of folders below a mapping ──────────────────────────────────

	/**
	 * @Given /^no part of "([^"]*)" exists in Grafana yet$/
	 *
	 * SAYS OUT LOUD WHAT THE SCENARIO IS ABOUT TO PROVE. The folder exists in
	 * Nextcloud and nowhere else, so every Grafana folder the gesture ends up needing
	 * is one it had to create — without this line a scenario would pass just as well
	 * against a Grafana that already had the tree, which is the opposite of the claim.
	 */
	public function noPartOfExistsInGrafanaYet(string $ncPath): void {
		[$parentUid, $segments] = $this->grafanaChainFor($ncPath);
		$first = $segments[0] ?? null;
		if ($first === null) {
			throw new \RuntimeException("'$ncPath' is a mapped folder itself — it exists in Grafana by definition");
		}
		// ONLY THE FIRST SEGMENT NEEDS ASKING. If the outermost folder of the chain is
		// absent then nothing below it can exist either, and asking anyway would mean
		// walking through a parent that is not there.
		if ($this->grafanaChildUid($parentUid, $first) !== null) {
			throw new \RuntimeException(
				"Grafana already holds '$first' below the mapping, so '$ncPath' cannot show that a gesture creates it",
			);
		}
	}

	/**
	 * @Then /^Grafana mirrors the folder "([^"]*)"$/
	 *
	 * THE CHAIN, NOT JUST THE LEAF, and at whatever depth the scenario used. A
	 * dashboard five folders deep needs all five, and asserting only the innermost
	 * would pass against a Grafana that had flattened the tree and hung the last
	 * folder off the mapping's root.
	 *
	 * It replaces `Grafana holds "Team" under "Demo", and "Drafts" under "Team"`,
	 * which spelled out exactly two levels — so the depth was baked into the sentence
	 * and a scenario could not vary it. Every level is checked TWICE here: the Grafana
	 * folder exists under the one before it, and the Nextcloud folder at that level
	 * carries its uid. Either alone is satisfied by a half-made mirror.
	 *
	 * Each uid is recorded, so `the dashboard is in the folder mirroring …` can name a
	 * folder only Grafana knows the uid of, and so teardown removes what the scenario
	 * caused to exist.
	 */
	public function grafanaMirrorsTheFolder(string $ncPath): void {
		[$parentUid, $segments] = $this->grafanaChainFor($ncPath);
		if ($segments === []) {
			throw new \RuntimeException("'$ncPath' is a mapped folder itself — there is no chain below it to mirror");
		}

		$ncWalked = $this->owningMappedFolder(trim($ncPath, '/'));
		$parentPath = $ncWalked;
		foreach ($segments as $segment) {
			$ncWalked .= '/' . $segment;

			$childUid = $this->grafanaChildUid($parentUid, $segment);
			if ($childUid === null) {
				throw new \RuntimeException(
					"Grafana has no folder '$segment' under '$parentPath' — the chain below '$ncPath' was not "
					. 'created all the way down',
				);
			}
			// REMEMBERED, NOT ADOPTED. This step finds folders; it never makes one, so
			// nothing it sees belongs on the teardown queue.
			$this->knownGrafanaFolders[$segment] = $childUid;

			$stamped = (string)$this->davReadMetadata($ncWalked, 'grafana_folder_uid');
			Assert::assertSame(
				$childUid,
				$stamped,
				"'$ncWalked' does not carry the uid of the Grafana folder mirroring it — the folder was made in "
				. 'Grafana and Nextcloud was never told which one it is',
			);

			$parentUid = $childUid;
			$parentPath = $ncWalked;
		}
	}

	/**
	 * @Then /^the dashboard is in the folder mirroring "([^"]*)"$/
	 *
	 * The uid comes from {@see grafanaMirrorsTheFolder}, which found it in Grafana —
	 * so this cannot agree with itself by construction, and it says nothing about
	 * WHERE the folder is, which is the other step's business.
	 */
	public function theDashboardIsInTheFolderMirroring(string $ncPath): void {
		$leaf = $this->leafOf($ncPath);
		$want = $this->knownGrafanaFolders[$leaf] ?? null;
		if ($want === null) {
			throw new \RuntimeException(
				"nothing has located the Grafana folder mirroring '$ncPath'; assert `Grafana mirrors the folder` first",
			);
		}
		// THE FILE'S OWN UID, NOT THE CURSOR. A file moved in from OUTSIDE every
		// mapping had no dashboard until it landed, so the uid the scenario is asking
		// about did not exist when the Given ran — `lastUid` would be empty, or worse,
		// left over from an earlier step and pointing somewhere else entirely.
		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		if ($uid === '') {
			$uid = $this->lastUid;
		}
		$record = $this->grafanaGetDashboard($uid);
		if ($record === null) {
			throw new \RuntimeException("dashboard '$uid' does not exist in Grafana");
		}
		Assert::assertSame(
			$want,
			(string)($record['meta']['folderUid'] ?? ''),
			"the dashboard is not in the Grafana folder mirroring '$ncPath'",
		);
	}

	/**
	 * @Then /^Grafana holds no folder named "([^"]*)"$/
	 *
	 * ANYWHERE IN GRAFANA, not just under the mapping. A folder invented at the root
	 * because the app lost track of the parent is exactly as wrong as one invented in
	 * the right place, and a check scoped to the mapping would call that a pass.
	 *
	 * Reads the deep listing, so a folder created two levels down cannot hide from it
	 * the way it hid from the legacy top-level-only `/api/folders`.
	 */
	public function grafanaHoldsNoFolderNamed(string $title): void {
		foreach ($this->grafanaFolderTreeLegacy() as $folder) {
			if ((string)($folder['title'] ?? '') === $title) {
				throw new \RuntimeException(
					"Grafana holds a folder named '$title' (uid " . (string)($folder['uid'] ?? '?') . ') — an empty '
					. 'Nextcloud folder is just a folder, and must not mint one',
				);
			}
		}
	}

	/**
	 * Every Grafana folder, walked over the LEGACY api one parent at a time.
	 *
	 * ## AN ABSENCE MUST NOT BE READ FROM A LAGGING INDEX
	 *
	 * `grafanaListFoldersDeep()` reads unified storage, which is not instantly
	 * consistent with the `/api/folders` the arranges write through — measured as an
	 * intermittent "Grafana has no folder titled 'Demo'" for a folder that had just
	 * been created. For a POSITIVE assertion that is a flake and you find out. For a
	 * NEGATIVE one — "no folder named X" — a lagging index reports exactly what the
	 * assertion wants to hear, and it passes for the wrong reason, silently, forever.
	 *
	 * So this pays a request per folder to ask the same store the writes went to.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function grafanaFolderTreeLegacy(): array {
		$out = [];
		$frontier = $this->grafanaListFolders();
		while ($frontier !== []) {
			$next = [];
			foreach ($frontier as $folder) {
				$out[] = $folder;
				$uid = (string)($folder['uid'] ?? '');
				if ($uid !== '') {
					$next = array_merge($next, $this->grafanaChildFolders($uid));
				}
			}
			$frontier = $next;
		}
		return $out;
	}

	/**
	 * @When /^someone creates the Grafana folder "([^"]*)"$/
	 *
	 * Straight through Grafana's own API, naming the folder BY PATH — so one step
	 * covers a folder at the mapping's root, a whole chain made at once, and one made
	 * under a folder Grafana already had. It replaces `someone creates the folder X
	 * under the Y Grafana folder`, which took the parent's UID: that reads the same as
	 * a title for a top-level folder (the arrange gives those uid == title) and cannot
	 * name a nested parent at all, because Grafana mints those uids itself.
	 *
	 * Grafana mints every uid here, which is what keeps the assertion honest — the
	 * scenario never learns a uid it could have compared against itself.
	 */
	public function someoneCreatesTheGrafanaFolder(string $path): void {
		$segments = explode('/', trim($path, '/'));
		$root = (string)array_shift($segments);
		$parentUid = $this->grafanaFolderUidByTitle($root);
		if ($parentUid === null) {
			throw new \RuntimeException("Grafana has no folder '$root' to create '$path' under");
		}
		if ($segments === []) {
			throw new \RuntimeException("'$path' names a mapped folder, not a folder to create inside one");
		}

		foreach ($segments as $segment) {
			// FIND OR CREATE, level by level. A scenario naming `Demo/Existing/Nubs`
			// means "under the one Grafana already has", not "make a second folder
			// called Existing" — and Grafana would happily do the latter, since it
			// permits duplicate titles in one parent.
			$uid = $this->grafanaChildUid($parentUid, $segment);
			if ($uid === null) {
				$uid = $this->grafanaCreateFolder($segment, $parentUid);
				// ONLY WHAT THIS STEP MADE. A folder that was already there is the
				// Background's or somebody else's, and deleting it at teardown would
				// take its dashboards with it.
				$this->createdGrafanaFolders[] = $uid;
			}
			$this->knownGrafanaFolders[$segment] = $uid;
			$parentUid = $uid;
		}
		$this->pullEveryMapping();
	}

	/**
	 * Where a Nextcloud path sits in Grafana: the mapping's folder uid, and the
	 * segments below it.
	 *
	 * ONLY THE MAPPING'S OWN FOLDER IS TRANSLATED. A mapping may point a Grafana
	 * folder at a differently-named Nextcloud one — `links` at `Pointers` — and
	 * nothing beneath it ever may, so every deeper segment is carried across as it
	 * stands. See `features/AGENTS.md#only-a-mapping-renames-a-folder`.
	 *
	 * @return array{0:string, 1:list<string>}
	 */
	private function grafanaChainFor(string $ncPath): array {
		$ncPath = trim($ncPath, '/');
		$owner = $this->owningMappedFolder($ncPath);
		foreach ($this->listMappings() as $mapping) {
			if ((string)($mapping['nc_folder'] ?? '') !== $owner) {
				continue;
			}
			$rest = trim(substr($ncPath, strlen($owner)), '/');
			return [
				(string)($mapping['grafana_folder_uid'] ?? ''),
				$rest === '' ? [] : explode('/', $rest),
			];
		}
		throw new \RuntimeException("no mapping owns the Nextcloud folder '$ncPath'");
	}

	/** The uid of a Grafana folder titled $title directly under $parentUid, or null. */
	private function grafanaChildUid(string $parentUid, string $title): ?string {
		foreach ($this->grafanaChildFolders($parentUid) as $folder) {
			if ((string)($folder['title'] ?? '') === $title) {
				$uid = (string)($folder['uid'] ?? '');
				return $uid === '' ? null : $uid;
			}
		}
		return null;
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * Compare two trees AS TEXT, and say what is missing and what is extra.
	 *
	 * NOT `assertSame` ON THE ARRAYS. PHPUnit shortens an array longer than ten
	 * entries when it builds a failure diff, and reaching for that setting outside a
	 * PHPUnit run hits an unconfigured registry: `Registry::get(): Return value must
	 * be of type Configuration, null returned`. Behat then reports THAT instead of
	 * the assertion — so every tree assertion big enough to be worth making was
	 * guaranteed to fail unreadably. Measured in CI on a sixteen-row table.
	 *
	 * Joining first also makes the failure legible: two sorted lists side by side,
	 * plus the two lines that actually differ.
	 *
	 * @param list<string> $want
	 * @param list<string> $got
	 */
	private function assertTree(string $side, array $want, array $got): void {
		$missing = array_values(array_diff($want, $got));
		$extra = array_values(array_diff($got, $want));
		Assert::assertSame(
			implode("\n", $want),
			implode("\n", $got),
			"$side is not the tree the scenario describes."
			. ($missing === [] ? '' : "\n  MISSING (the scenario says these exist): " . implode(', ', $missing))
			. ($extra === [] ? '' : "\n  UNEXPECTED (these exist and the scenario does not name them): " . implode(', ', $extra)),
		);
	}

	/** The `path` cell, or a failure that says which table is wrong. */
	private function requirePath(array $row): string {
		$path = trim((string)($row['path'] ?? ''));
		if ($path === '') {
			throw new \RuntimeException('a resource table needs a "path" column with a value in every row');
		}
		return $path;
	}

	/** `/alpha/Region/Latency` ⇒ `Latency`. */
	private function leafOf(string $path): string {
		$parts = explode('/', trim($path, '/'));
		return (string)end($parts);
	}

	/** `/alpha/Region/Latency` ⇒ `/alpha/Region`; a one-segment path ⇒ `''`. */
	private function parentOf(string $path): string {
		$parts = explode('/', trim($path, '/'));
		array_pop($parts);
		return $parts === [] ? '' : '/' . implode('/', $parts);
	}

	/** `/alpha/Region/Latency` ⇒ `/alpha` — the top-level folder an assertion walks from. */
	private function rootOf(string $path): string {
		$parts = explode('/', trim($path, '/'));
		return '/' . ($parts[0] ?? '');
	}

	/**
	 * A Nextcloud path with no extension on its leaf is a folder.
	 *
	 * Inferred rather than declared because the name already says it, and a `type`
	 * column that only ever repeats the filename is a column nobody reads.
	 */
	private function looksLikeFolder(string $path): bool {
		return !str_contains($this->leafOf($path), '.');
	}

	/** A stable uid for a dashboard the table did not name one for. */
	private function derivedDashboardUid(string $path): string {
		return 'gs-' . substr(sha1($path), 0, 16);
	}

	/**
	 * The Grafana folder at this path, creating it and its ancestors if need be.
	 *
	 * The TOP level is `ensureGrafanaFolder`, which mints uid == title, because that
	 * is the uid a mapping table stores when it names a folder. Everything below it
	 * gets Grafana's own uid, which is why the map exists.
	 */
	private function ensureGrafanaFolderPath(string $path): string {
		$path = '/' . trim($path, '/');
		if ($path === '/') {
			return '';
		}
		if (isset($this->grafanaFolderPaths[$path])) {
			return $this->grafanaFolderPaths[$path];
		}

		$parent = $this->parentOf($path);
		if ($parent === '') {
			$title = $this->leafOf($path);
			$this->ensureGrafanaFolder($title);
			return $this->grafanaFolderPaths[$path] = $title;
		}

		$parentUid = $this->ensureGrafanaFolderPath($parent);
		$uid = $this->grafanaCreateFolder($this->leafOf($path), $parentUid);
		if (!in_array($uid, $this->createdGrafanaFolders, true)) {
			$this->createdGrafanaFolders[] = $uid;
		}
		return $this->grafanaFolderPaths[$path] = $uid;
	}

	/**
	 * Every folder and dashboard beneath a top-level Grafana folder, as paths.
	 *
	 * @return list<string>
	 */
	private function grafanaTreeUnder(string $root): array {
		$rootUid = trim($root, '/');
		$byParent = [];
		foreach ($this->grafanaListFoldersDeep() as $folder) {
			$byParent[(string)($folder['parentUid'] ?? '')][] = $folder;
		}

		$out = [];
		$walk = function (string $uid, string $path) use (&$walk, $byParent, &$out): void {
			foreach ($this->grafanaSearchDashboards($uid) as $row) {
				$out[] = $path . '/' . (string)($row['title'] ?? '');
			}
			foreach ($byParent[$uid] ?? [] as $child) {
				$childPath = $path . '/' . (string)($child['title'] ?? '');
				$out[] = $childPath;
				$walk((string)($child['uid'] ?? ''), $childPath);
			}
		};
		$walk($rootUid, '/' . $rootUid);
		return $out;
	}

	/**
	 * Grafana's folders INCLUDING nested ones.
	 *
	 * NOT `grafanaListFolders()`, which is the legacy `/api/folders` and returns
	 * top-level folders only — measured, and the reason a whole class of subfolder
	 * bug was invisible to this suite. This is the same resource the app itself
	 * walks ({@see \OCA\GrafanaSync\Service\GrafanaClient::listFolders}), so a test
	 * asserting a tree and the code building one are reading the same thing.
	 *
	 * @return list<array{uid:string, title:string, parentUid:string}>
	 */
	private function grafanaListFoldersDeep(): array {
		$res = $this->grafanaClient()->request(
			'GET',
			'/apis/folder.grafana.app/v1beta1/namespaces/default/folders?limit=1000',
		);
		Assert::assertSame(200, $res->getStatusCode(), 'listing Grafana folders failed: ' . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		$out = [];
		foreach ((array)($decoded['items'] ?? []) as $item) {
			if (!is_array($item)) {
				continue;
			}
			$uid = (string)($item['metadata']['name'] ?? '');
			if ($uid === '') {
				continue;
			}
			$out[] = [
				'uid' => $uid,
				'title' => (string)($item['spec']['title'] ?? $uid),
				'parentUid' => (string)($item['metadata']['annotations']['grafana.app/folder'] ?? ''),
			];
		}
		return $out;
	}

	/**
	 * Every descendant of a Nextcloud folder, as absolute paths.
	 *
	 * DEPTH 1, RECURSING BY HAND. `Depth: infinity` is refused by default on a
	 * Nextcloud instance, so a helper written the obvious way passes locally and
	 * returns nothing in CI.
	 *
	 * @return list<string>
	 */
	private function davTreeUnder(string $folder): array {
		$folder = trim($folder, '/');
		$out = [];
		$queue = [$folder];
		for ($i = 0; $i < count($queue); $i++) {
			foreach ($this->davChildren($queue[$i]) as $child => $isFolder) {
				$out[] = '/' . $child;
				if ($isFolder) {
					$queue[] = $child;
				}
			}
		}
		return $out;
	}

	/**
	 * One level of a Nextcloud folder: child path ⇒ whether it is a collection.
	 *
	 * @return array<string, bool>
	 */
	private function davChildren(string $folder): array {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($folder), [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
			'http_errors' => false,
		]);
		if ($res->getStatusCode() === 404) {
			return []; // a folder the sync was supposed to make and did not
		}
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $folder failed: " . (string)$res->getBody());

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$self = trim($folder, '/');

		$out = [];
		foreach ($doc->xpath('//d:response') ?: [] as $response) {
			$response->registerXPathNamespace('d', 'DAV:');
			$href = rawurldecode((string)(($response->xpath('d:href')[0]) ?? ''));
			$isFolder = ($response->xpath('.//d:collection') ?: []) !== [];

			// The href is server-absolute (`/remote.php/dav/files/<user>/…`); everything
			// up to and including the folder itself is the prefix, and the remainder is
			// the path this suite speaks in.
			$pos = strpos($href, '/' . $self . '/');
			if ($self !== '' && $pos === false) {
				continue; // the collection's own entry
			}
			$rel = $self === '' ? $href : substr($href, $pos + 1);
			$rel = trim($rel, '/');
			if ($rel === '' || $rel === $self) {
				continue;
			}
			$out[$rel] = $isFolder;
		}
		return $out;
	}

	/**
	 * Register a declared path's TOP folder for teardown.
	 *
	 * The top segment only: `tearDown` deletes the folder, which takes everything
	 * beneath it, and queueing every descendant separately would just make the
	 * teardown fail noisily on paths their parent already removed.
	 */
	private function trackTopFolder(string $path): void {
		$root = trim($this->rootOf($path), '/');
		if ($root !== '' && !in_array($root, $this->createdFolders, true)) {
			$this->createdFolders[] = $root;
		}
	}

	/**
	 * What a declared Nextcloud file contains.
	 *
	 * A `.grafana` file gets a real dashboard body, because a mirror the push cannot
	 * parse is not a mirror. Anything else gets prose — its only job is to still be
	 * there afterwards.
	 */
	private function bodyFor(string $path): string {
		$name = $this->leafOf($path);
		if (!str_ends_with($name, '.grafana')) {
			return "declared by a scenario, and not a dashboard\n";
		}
		$title = substr($name, 0, -strlen('.grafana'));
		return json_encode([
			'title' => $title,
			'panels' => [],
			'schemaVersion' => 39,
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

}
