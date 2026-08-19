<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

/**
 * "Which files do you want to keep?" — the conflict dialog, and the three answers.
 *
 * Ported from the n8n master's `MoveSteps`, which covers the same two scenarios against
 * the same dialog. Kept in its own trait because it is the only cluster of steps that
 * models a CLIENT decision rather than a server one, and because it carries the one
 * arrange in this suite that has to change an app setting to build its pre-state (see
 * {@see anUnmappedFileNamedCarrying}).
 *
 * ## THE DIALOG IS NOT A SERVER CONCEPT
 *
 * `moveOrCopyAction.ts` PROPFINDs the destination, finds the collision, and opens the
 * picker BEFORE a single request goes out; the answer then decides whether one is sent
 * at all, and under what name. So `I move the unmapped file into` sends nothing — it
 * announces the destination — and `I select` is what performs the gesture. A step that
 * moved the file first and "resolved" afterwards would be modelling a client that does
 * not exist.
 */
trait MoveConflictSteps {
	/** The file standing in the mapping — the destination of the collision. */
	private string $collisionSyncedPath = '';

	/** The unmapped file about to be moved in. */
	private string $collisionIncomingPath = '';

	/** The uid the destination held before the gesture — what an overwrite must preserve. */
	private string $destinationUidBefore = '';

	/** The folder named by `I move the unmapped file into`, awaiting an answer. */
	private string $conflictDestination = '';

	/** The panel title written into the arriving body, so "whose body won" has an answer. */
	private string $arrivedPanelTitle = '';

	/** The panel title the destination's own body carries. */
	private string $existingPanelTitle = '';

	/**
	 * A duplicate of the file already in the mapping, sitting outside every mapping.
	 *
	 * WHICH UID IT CARRIES IS A COLUMN, because the rule does not depend on it. An
	 * overwrite preserves the DESTINATION's identity whatever the arrival was bound to,
	 * and a scenario whose two files always shared a uid could never show that — the
	 * assertion would be satisfied by an app that simply kept the arrival's uid.
	 *
	 * ## THIS ARRANGE TURNS THE RECYCLE BIN ON, AND THAT IS NOT INCIDENTAL
	 *
	 * The two rows that need the arrival to carry a uid need a file that is unmapped and
	 * still stamped, and there is exactly one gesture that produces one: moving a managed
	 * file out of a mapping WITH THE BIN ON, which parks the dashboard and keeps the uid
	 * ({@see \OCA\GrafanaSync\Service\MotionService::onLeaveMapping}). With the bin off
	 * the same move deletes the dashboard and strips the file, which is the third row.
	 *
	 * So the bin goes on for the walk-out and OFF AGAIN before the When. That is a real
	 * history — someone who had the bin on, moved a file out, and later turned it off —
	 * and it leaves the gesture under test running against the harsher setting, where a
	 * failure of the overwrite suppression destroys the dashboard rather than parking it.
	 * Restoring it is not optional: `move.feature` never names a bin, so a leaked `on`
	 * would silently change what every later scenario's move-out means.
	 *
	 * @Given an unmapped file named :filename in :folder carrying :whichUid
	 */
	public function anUnmappedFileNamedCarrying(string $filename, string $folder, string $whichUid): void {
		if ($this->currentFilePath === '') {
			throw new \RuntimeException('no managed file to duplicate — a Given must arrange one');
		}
		$mappedFolder = $this->currentFolder;
		// PINNED BEFORE ANYTHING ELSE RUNS. `putDashboardFile` moves the cursor to the
		// file it makes, so the `a different grafana_uid` branch below would otherwise
		// name the throwaway `Other-…` file as the destination of the collision.
		$syncedPath = $this->currentFilePath;
		$this->davMkdir($folder);
		$dest = $folder . '/' . $filename;
		// Any leftover at the destination is cleared first: `davMove` sends
		// `Overwrite: F`, so a previous scenario's file would refuse this arrange with a
		// 412 that reads like a permissions problem.
		if ($this->davExists($dest)) {
			$this->davDelete($dest);
		}

		if ($whichUid === 'no grafana_uid at all') {
			// NOTHING TO INHERIT FROM, which is the copy case: a copy does not carry the
			// metadata row, so an unmapped `.grafana` duplicated in the file manager has
			// no uid at all. It lands in create-on-land rather than the motion path, and
			// the rule has to hold there too. Written straight into `$folder`, which is
			// outside every mapping, so nothing stamps it on the way in.
			$this->collisionSyncedPath = $syncedPath;
			$stem = preg_replace('/\.grafana$/', '', $filename) ?? $filename;
			$this->davPut($dest, $this->dashboardBody($stem));
			if (($this->davReadMetadata($dest, self::META_UID) ?? '') !== '') {
				throw new \RuntimeException("setup: the file at $dest was stamped with a uid; it should carry none");
			}
		} else {
			// THE OTHER TWO ROWS BOTH NEED A STAMPED, UNMAPPED FILE, and differ only in
			// whether its uid is the destination's or a stranger's.
			$walked = $whichUid === 'a different grafana_uid'
				// A SECOND DASHBOARD ENTIRELY. The file already in the mapping stays
				// exactly where it is — it is the destination — and a freshly created
				// managed file is walked out under the colliding name. Two files, two
				// dashboards, one name.
				? $this->putDashboardFile($mappedFolder, 'Other-' . bin2hex(random_bytes(3)))
				// THE SAME DASHBOARD, TWICE. The synced file itself is walked out (uid
				// preserved, dashboard parked), the dashboard is moved back into the
				// mapped folder in Grafana, and a pull writes a fresh synced mirror into
				// the mapping. Net: one dashboard, mirrored by two files, one of them
				// outside every mapping.
				: $syncedPath;

			$parkedUid = (string)$this->davReadMetadata($walked, self::META_UID);
			$this->withRecycleBin(function () use ($walked, $dest): void {
				$this->davMove($walked, $dest);
			});

			if ($whichUid === 'a different grafana_uid') {
				$this->collisionSyncedPath = $syncedPath;
			} else {
				$this->rescueParkedDashboard($parkedUid, $mappedFolder);
				$this->theAdminPullsFromGrafana();
				// FOUND BY UID, not by filename: the pull names a mirror after its
				// DASHBOARD, so the filename is Grafana's to choose.
				$this->collisionSyncedPath = $this->mirrorFor($mappedFolder, $parkedUid);
			}

			if ($this->davReadMetadata($dest, self::META_MODE) !== 'unmapped') {
				throw new \RuntimeException("setup: the moved-out file at $dest is not unmapped");
			}
		}

		$this->currentFilePath = $dest;
		$this->collisionIncomingPath = $dest;

		if ($this->davReadMetadata($this->collisionSyncedPath, self::META_MODE) !== 'sync') {
			throw new \RuntimeException("setup: the file at {$this->collisionSyncedPath} is not in sync mode");
		}
		// THE UID THE DESTINATION ALREADY HAD, pinned here while it is still the only
		// thing standing in the mapping. This is what an overwrite must preserve, and
		// reading it afterwards would compare the surviving uid with itself.
		$this->destinationUidBefore = (string)$this->davReadMetadata($this->collisionSyncedPath, self::META_UID);
		if ($this->destinationUidBefore === '') {
			throw new \RuntimeException('setup: the destination file carries no dashboard uid');
		}
		// The arriving file is the one the scenario moves next, and its uid is the
		// baseline `its own, not the one it arrived with` measures against.
		$this->arrivedWithUid = (string)($this->davReadMetadata($dest, self::META_UID) ?? '');
		$this->existingPanelTitle = $this->panelTitleIn($this->collisionSyncedPath);
	}

	/**
	 * Give the incoming file a body of its own, so "whose panels survived" is a question
	 * with an answer. Without it, keeping either version leaves the same bytes behind and
	 * nothing downstream can tell the two apart.
	 *
	 * SAFE TO WRITE, because the file is unmapped: `NodeWrittenListener` skips it, so this
	 * edit reaches nothing in Grafana — which is `edit.feature`'s own claim, relied on
	 * here rather than restated.
	 *
	 * @Given that file's panels differ from the dashboard's
	 */
	public function thatFilesPanelsDifferFromTheDashboards(): void {
		$path = $this->collisionIncomingPath !== '' ? $this->collisionIncomingPath : $this->currentFilePath;
		$spec = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		$this->arrivedPanelTitle = 'Arrived-' . bin2hex(random_bytes(3));
		$spec->panels = [(object)['type' => 'text', 'title' => $this->arrivedPanelTitle]];
		// THE BODY'S TITLE AGREES WITH THE FILENAME, which is what any real file at this
		// path looks like — a name is one value living in three places (`rename.feature`).
		// The `a different grafana_uid` arrange builds this file under another name and
		// walks it here, so without this it arrives claiming to be `Other-xxxx`; the push
		// on adoption would then rename the DESTINATION's dashboard to that, and the next
		// pull would rename its mirror to match. A real arrival never disagrees with its
		// own filename, and a scenario about contents should not smuggle in a rename.
		$spec->title = preg_replace('/\.grafana$/', '', basename($path)) ?? basename($path);
		$this->davPut($path, json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	}

	/**
	 * @When I move the unmapped file into :folder
	 *
	 * Announces the destination; the answer performs the move. See the trait docblock.
	 */
	public function iMoveTheUnmappedFileInto(string $folder): void {
		$this->conflictDestination = $folder;
	}

	/**
	 * Answer the "Which files do you want to keep?" dialog — all three, each doing exactly
	 * what the Files app does with that answer and nothing more.
	 *
	 *   the existing version → the node is filtered out of the request list, so NO REQUEST
	 *                          IS SENT AT ALL and the file stays where it is. Doing nothing
	 *                          is the implementation, not a stub.
	 *   both versions        → `getUniqueName()` picks a free name and one ordinary MOVE
	 *                          goes to it (`Overwrite: F` is fine — by construction the
	 *                          name is free).
	 *   the new version      → one MOVE to the original name with `Overwrite: T`, which is
	 *                          what an absent header means. Sabre deletes the destination
	 *                          and then moves.
	 *
	 * @When I select :answer
	 */
	public function iSelect(string $answer): void {
		$folder = $this->conflictDestination;
		if ($folder === '') {
			throw new \RuntimeException('no move announced — a When must name the destination');
		}
		$name = basename($this->currentFilePath);
		$dest = $folder . '/' . $name;

		switch ($answer) {
			case 'the existing version':
				if (!$this->davExists($dest)) {
					throw new \RuntimeException(
						"there is no '$name' in $folder to collide with — this scenario needs a conflict to answer",
					);
				}
				return;
			case 'both versions':
				$this->davMove($this->currentFilePath, $folder . '/' . $this->uniqueNameIn($folder, $name));
				return;
			case 'the new version':
				if (!$this->davExists($dest)) {
					throw new \RuntimeException(
						"there is no '$name' in $folder to overwrite — this scenario needs a conflict to answer",
					);
				}
				// OVERWRITE: T, which is what the Files app sends by omitting the header.
				$this->davMove($this->currentFilePath, $dest, true);
				return;
			default:
				throw new \RuntimeException("'$answer' is not an answer the conflict dialog offers");
		}
	}

	/**
	 * @Then :path holds the panels of :whose
	 *
	 * The CONTENT half of the rule, and the only half the person answering the dialog was
	 * actually choosing between. The metadata table beside this one says the identity was
	 * not theirs to pick.
	 */
	public function holdsThePanelsOf(string $path, string $whose): void {
		$want = match (trim($whose)) {
			'the file already there' => $this->existingPanelTitle,
			'the file that arrived' => $this->arrivedPanelTitle,
			default => throw new \RuntimeException("'$whose' is not a body this vocabulary knows"),
		};
		if ($want === '') {
			throw new \RuntimeException("the arrange captured no panels for '$whose'");
		}
		$got = $this->panelTitleIn($path);
		if ($got !== $want) {
			throw new \RuntimeException("$path holds the panel '$got', not '$want' — the wrong body survived");
		}
	}

	/**
	 * @Then its dashboard in Grafana still exists and holds those same panels
	 *
	 * GRAFANA IS ASKED SEPARATELY, because the file agreeing with itself proves nothing:
	 * an overwrite that stamped correctly but never pushed leaves a mirror describing a
	 * dashboard that still holds the other body.
	 */
	public function itsDashboardStillExistsAndHoldsThoseSamePanels(): void {
		$uid = (string)$this->davReadMetadata($this->collisionDestinationPath(), self::META_UID);
		$record = $this->grafanaGetDashboardObject($uid);
		if ($record === null) {
			throw new \RuntimeException("Grafana has no dashboard '$uid' — the overwrite deleted it");
		}
		$got = $this->firstPanelTitle($record->dashboard ?? new \stdClass());
		$want = $this->panelTitleIn($this->collisionDestinationPath());
		if ($got !== $want) {
			throw new \RuntimeException("the dashboard holds the panel '$got' while its file holds '$want'");
		}
	}

	/**
	 * @Then its dashboard in Grafana is titled :title and holds the panels it always had
	 */
	public function itsDashboardIsTitledAndHoldsThePanelsItAlwaysHad(string $title): void {
		$this->assertDashboardFor($this->collisionDestinationPath(), $title, $this->existingPanelTitle);
	}

	/**
	 * @Then its dashboard in Grafana is titled :title and holds the panels that arrived
	 */
	public function itsDashboardIsTitledAndHoldsThePanelsThatArrived(string $title): void {
		$this->assertDashboardFor($this->conflictDestination . '/' . $title . '.grafana', $title, $this->arrivedPanelTitle);
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/** Where the surviving file in the mapping is: the colliding name, in the destination. */
	private function collisionDestinationPath(): string {
		return $this->conflictDestination . '/' . basename($this->collisionSyncedPath);
	}

	/** One dashboard, checked from Grafana's side: it exists, is named, and holds a body. */
	private function assertDashboardFor(string $path, string $title, string $panel): void {
		$uid = (string)$this->davReadMetadata($path, self::META_UID);
		if ($uid === '') {
			throw new \RuntimeException("$path carries no dashboard uid");
		}
		$record = $this->grafanaGetDashboardObject($uid);
		if ($record === null) {
			throw new \RuntimeException("Grafana has no dashboard '$uid' for $path");
		}
		$spec = $record->dashboard ?? new \stdClass();
		$actual = (string)($spec->title ?? '');
		if ($actual !== $title) {
			throw new \RuntimeException("the dashboard behind $path is titled '$actual', not '$title'");
		}
		$got = $this->firstPanelTitle($spec);
		if ($got !== $panel) {
			throw new \RuntimeException("the dashboard behind $path holds the panel '$got', not '$panel'");
		}
	}

	/** The first panel title in a mirror, which is the whole of a body in these scenarios. */
	private function panelTitleIn(string $path): string {
		$spec = json_decode($this->davGet($path), false, 512, JSON_THROW_ON_ERROR);
		return $this->firstPanelTitle($spec instanceof \stdClass ? $spec : new \stdClass());
	}

	private function firstPanelTitle(\stdClass $spec): string {
		$panels = $spec->panels ?? [];
		if (!is_array($panels) || $panels === []) {
			return '';
		}
		$first = $panels[0];
		return $first instanceof \stdClass ? (string)($first->title ?? '') : '';
	}

	/**
	 * Run one gesture with the recycle bin on, and put the setting back afterwards —
	 * including when the gesture throws. See {@see anUnmappedFileNamedCarrying} for why
	 * the arrange needs the bin at all, and why leaving it on would be a bug.
	 */
	private function withRecycleBin(callable $gesture): void {
		$this->setBinFolder('nextcloud-trash');
		$this->setBinEnabled(true);
		try {
			$gesture();
		} finally {
			$this->setBinEnabled(false);
		}
	}

	/** Move a parked dashboard back into the mapping's Grafana folder, as a colleague would. */
	private function rescueParkedDashboard(string $uid, string $ncFolder): void {
		$record = $this->grafanaGetDashboardObject($uid);
		if ($record === null) {
			throw new \RuntimeException("setup: dashboard '$uid' is not in Grafana to rescue");
		}
		$res = $this->grafanaClient()->request('POST', '/api/dashboards/db', [
			'json' => [
				'dashboard' => $record->dashboard ?? new \stdClass(),
				'folderUid' => $this->grafanaFolderUidForMapping($ncFolder),
				'overwrite' => true,
				'message' => 'integration: rescued from the bin folder',
			],
		]);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException('setup: the rescue failed: ' . (string)$res->getBody());
		}
	}

	/** The file in $folder mirroring $uid, whatever the pull decided to call it. */
	private function mirrorFor(string $folder, string $uid): string {
		foreach ($this->davListDashboardFiles($folder) as $name) {
			$path = $folder . '/' . $name;
			if ((string)$this->davReadMetadata($path, self::META_UID) === $uid) {
				return $path;
			}
		}
		throw new \RuntimeException("setup: the pull wrote no mirror for '$uid' into '$folder'");
	}

	/** `Name (1).grafana`, `Name (2).grafana`, … — the first spelling free in $folder. */
	private function uniqueNameIn(string $folder, string $name): string {
		$stem = preg_replace('/\.grafana$/', '', $name) ?? $name;
		for ($i = 1; $i < 50; $i++) {
			$candidate = "$stem ($i).grafana";
			if (!$this->davExists($folder . '/' . $candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException("no free name beside '$name' in '$folder'");
	}

}
