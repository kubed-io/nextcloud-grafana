<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Exception\ExistingDashboardsException;
use OCA\GrafanaSync\Service\ExistingDashboards;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\RecycleBin;
use OCA\GrafanaSync\Service\StorageService;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * CRUD + resolver behaviour of the mapping store. Backed by an in-memory fake of
 * IAppConfig (a single string cell for the `mappings` key) so the JSON
 * encode/decode round-trip is exercised for real, not mocked away.
 */
final class MappingServiceTest extends TestCase {
	/** @var array<string, list<string>> what reached each mapping's FOLDER */
	private array $appliedGroups = [];

	/** Shared backing store for the fake IAppConfig, keyed by config key. */
	private array $store = [];

	/**
	 * Where each Nextcloud folder id currently is, as the server would answer.
	 *
	 * This is the fixture a rename is expressed in: move the path a mapping's id
	 * points at, and nothing else changes.
	 *
	 * @var array<int, string>
	 */
	private array $folderPaths = [];

	private function config(): IAppConfig {
		$store = &$this->store;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string
				=> $store[$key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			},
		);
		return $config;
	}

	/**
	 * An {@see ExistingDashboards} that finds nothing.
	 *
	 * The default for every test here, because "the folder already holds dashboard
	 * files" is one rule among many and the rest must not have to think about it. The
	 * tests that DO care build their own with {@see existingDashboardsHolding()}; the
	 * sweep itself is covered by {@see ExistingDashboardsTest}.
	 */
	private function existingDashboards(): ExistingDashboards {
		$existing = $this->createStub(ExistingDashboards::class);
		$existing->method('under')->willReturn([]);
		return $existing;
	}

	private function service(): MappingService {
		return new MappingService($this->config(), $this->storage(), $this->recycleBin(), $this->existingDashboards());
	}

	/**
	 * A StorageService that records what was applied to each mapping's folder.
	 *
	 * A FAKE, NOT A STUB: the whole point of the groups change is that they go to
	 * the FOLDER and not into the store, and a stub returning null would let a
	 * service that quietly persisted them pass. This one remembers, so the tests
	 * can assert where the groups actually landed.
	 */
	private function storage(): StorageService {
		$storage = $this->createMock(StorageService::class);
		$storage->method('ensureFolder')->willReturnCallback(
			function (Mapping $m, array|string|null $groups = null) {
				if ($groups !== null) {
					$this->appliedGroups[$m->id] = StorageService::normaliseGroups($groups);
				}
				// The provisioned folder answers with the id the fixture gave the
				// mapping, so a test that pins an id keeps it through `add()`.
				$folder = $this->createStub(\OCP\Files\Folder::class);
				$folder->method('getId')->willReturn($m->ncFolderId);
				return $folder;
			},
		);
		$storage->method('groupsOf')->willReturnCallback(
			fn (Mapping $m): array => $this->appliedGroups[$m->id] ?? [],
		);
		$storage->method('pathOfFolderId')->willReturnCallback(
			fn (int $id): ?string => $this->folderPaths[$id] ?? null,
		);

		return $storage;
	}

	/**
	 * A RecycleBin naming no folder, so the bin guard is inert unless a test opts in.
	 * `$binFolder` is what the admin typed into the setting — the guard compares the
	 * mapping's Grafana folder TITLE against it.
	 */
	private function recycleBin(string $binFolder = ''): RecycleBin {
		$bin = $this->createStub(RecycleBin::class);
		$bin->method('folderTitle')->willReturn($binFolder);
		return $bin;
	}

	private function mapping(string $uid, string $ncFolder, string $mode = 'sync'): Mapping {
		return Mapping::fromArray([
			'grafana_folder_uid' => $uid,
			'grafana_folder_title' => $uid,
			'nc_folder' => $ncFolder,
			'mode' => $mode,
		]);
	}

	public function testAddThenListRoundTrips(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'alpha'));
		$svc->add($this->mapping('uid-b', 'bravo', 'link'));

		$all = $svc->list();
		self::assertCount(2, $all);
		self::assertSame('uid-a', $all[0]->grafanaFolderUid);
		self::assertSame('bravo', $all[1]->ncFolder);
		self::assertSame('link', $all[1]->mode);
	}

	public function testPersistsAcrossServiceInstances(): void {
		$this->service()->add($this->mapping('uid-a', 'alpha'));
		// A fresh service sharing the same backing store must decode what was stored.
		$all = $this->service()->list();
		self::assertCount(1, $all);
		self::assertSame('uid-a', $all[0]->grafanaFolderUid);
	}

	public function testGetByIdReturnsTheMappingOrNull(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha'));
		self::assertNotNull($svc->getById($saved->id));
		self::assertSame('uid-a', $svc->getById($saved->id)->grafanaFolderUid);
		self::assertNull($svc->getById('nope'));
	}

	public function testRejectsADuplicateFolderUid(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'alpha'));
		$this->expectException(\InvalidArgumentException::class);
		$svc->add($this->mapping('uid-a', 'different-folder'));
	}

	public function testRejectsADuplicateId(): void {
		$svc = $this->service();
		$a = Mapping::fromArray(['id' => 'fixed', 'grafana_folder_uid' => 'uid-a', 'nc_folder' => 'alpha', 'mode' => 'sync']);
		$b = Mapping::fromArray(['id' => 'fixed', 'grafana_folder_uid' => 'uid-b', 'nc_folder' => 'bravo', 'mode' => 'sync']);
		$svc->add($a);
		$this->expectException(\InvalidArgumentException::class);
		$svc->add($b);
	}

	// ── there is no update() any more, and these tests went with it ──────────────
	//
	// Seven tests lived here asserting that update() rejected a change to the
	// Grafana folder, the Nextcloud folder, the Team Folder flag and
	// subfolder-sync, one field at a time. Every one of them described a guard on
	// a method that no longer exists.
	//
	// Immutability is now the API's SHAPE: updateGroups() takes an id and groups,
	// so a change to anything else cannot be expressed and there is no rejection to
	// assert. Testing a guard that cannot be reached would be testing nothing.
	//
	// The old guard list was also incomplete in a way the tests hid: it checked
	// four fields and left `mode` editable, and it decides how every
	// already-mirrored file was written. Seven passing tests, and the gap between
	// them was the bug.
	//
	// What replaced them: the groups tests below, and
	// features/admin-mapping.feature's @decision scenario, which records that there
	// is deliberately no operation here at all.

	public function testDeleteRemovesTheMapping(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha'));
		$svc->delete($saved->id);
		self::assertCount(0, $svc->list());
	}

	public function testDeleteUnknownIdThrows(): void {
		$svc = $this->service();
		$this->expectException(\OutOfBoundsException::class);
		$svc->delete('missing');
	}

	public function testListSkipsMalformedRows(): void {
		// A stored list with one good row and one invalid row (bad mode) must yield
		// only the good one, never a blank panel.
		$this->store['mappings'] = json_encode([
			['grafana_folder_uid' => 'uid-a', 'nc_folder' => 'alpha', 'mode' => 'sync'],
			['grafana_folder_uid' => 'uid-b', 'nc_folder' => 'bravo', 'mode' => 'bogus'],
			'not-even-an-array',
		]);
		$all = $this->service()->list();
		self::assertCount(1, $all);
		self::assertSame('uid-a', $all[0]->grafanaFolderUid);
	}

	/**
	 * ONE FOLDER, ONE MAPPING — the Nextcloud half, which was missing while the
	 * Grafana half was guarded. Two mappings on one folder means a file in it
	 * resolves to whichever the resolver reaches first, so its dashboard lands in one
	 * of two Grafana folders with nothing choosing between them.
	 */
	public function testTwoMappingsMayNotTargetTheSameNextcloudFolder(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'Demo'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/already uses the Nextcloud folder/');
		$svc->add($this->mapping('uid-b', 'Demo'));
	}

	/**
	 * Compared case-insensitively, because Nextcloud will not create `Demo` beside
	 * `demo`. Two mappings differing only in case would both provision the SAME
	 * folder while each believing it had one to itself.
	 */
	public function testTheNextcloudFolderClashIsCaseInsensitive(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'Demo'));

		$this->expectException(\InvalidArgumentException::class);
		$svc->add($this->mapping('uid-b', 'demo'));
	}

	public function testADifferentNextcloudFolderIsFine(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'Demo'));
		$svc->add($this->mapping('uid-b', 'Reports'));

		self::assertCount(2, $svc->list());
	}

	/**
	 * THE BIN IS THE APP'S OWN SCRATCH SPACE. It holds parked dashboards AND
	 * dashboards Nextcloud has never managed, so mapping it would point a sync folder
	 * at that pile — and emptying the mapped folder would delete other people's
	 * dashboards out of the bin.
	 */
	public function testTheRecycleBinFolderCannotBeMapped(): void {
		$svc = new MappingService($this->config(), $this->storage(), $this->recycleBin('nextcloud-trash'), $this->existingDashboards());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/cannot be mapped because it is the recycle bin/');
		$svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-bin',
			'grafana_folder_title' => 'nextcloud-trash',
			'nc_folder' => 'Trash',
			'mode' => 'sync',
		]));
	}

	public function testTheRecycleBinClashIsCaseInsensitive(): void {
		$svc = new MappingService($this->config(), $this->storage(), $this->recycleBin('nextcloud-trash'), $this->existingDashboards());

		$this->expectException(\InvalidArgumentException::class);
		$svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-bin',
			'grafana_folder_title' => 'Nextcloud-Trash',
			'nc_folder' => 'Trash',
			'mode' => 'sync',
		]));
	}

	// ── a link mapping over a folder that already holds dashboard files ───────

	/**
	 * An {@see ExistingDashboards} that finds $files and records what it was asked to
	 * purge, so a test can tell "refused" from "destroyed" without either being mocked
	 * into existence.
	 *
	 * @param list<File> $files
	 */
	private function existingDashboardsHolding(array $files, ?array &$purged = null): ExistingDashboards {
		$existing = $this->createMock(ExistingDashboards::class);
		$existing->method('under')->willReturn($files);
		$existing->method('purge')->willReturnCallback(static function (array $f) use (&$purged): int {
			$purged = $f;
			return count($f);
		});
		return $existing;
	}

	private function dashFile(): File {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn('Keeper.grafana');
		return $f;
	}

	private function linkMapping(): Mapping {
		return Mapping::fromArray([
			'grafana_folder_uid' => 'uid-a',
			'grafana_folder_title' => 'observe',
			'nc_folder' => 'Dashboards',
			'mode' => 'link',
		]);
	}

	/**
	 * REFUSED BY DEFAULT, AND THAT IS THE SAFETY. The destructive path cannot be
	 * reached by a caller that does not know about it — an older panel, a script, a
	 * curl. The refusal carries the count as a NUMBER because the panel puts it in a
	 * warning, and parsing it back out of a sentence would break the first time that
	 * sentence is reworded.
	 */
	public function testALinkMappingOverExistingDashboardsIsRefusedWithTheCount(): void {
		$svc = new MappingService(
			$this->config(),
			$this->storage(),
			$this->recycleBin(),
			$this->existingDashboardsHolding([$this->dashFile(), $this->dashFile()]),
		);

		try {
			$svc->add($this->linkMapping());
			self::fail('a link mapping over two dashboard files was accepted');
		} catch (ExistingDashboardsException $e) {
			self::assertSame(2, $e->dashboards);
			self::assertSame('Dashboards', $e->folder);
			self::assertStringContainsString('permanently deleted', $e->getMessage());
			self::assertStringContainsString('Move them elsewhere first', $e->getMessage());
		}
		self::assertSame([], $svc->list(), 'the refused mapping was stored anyway');
	}

	/** The admin answered, so the files go — and the mapping is saved. */
	public function testAcknowledgingThePurgeCreatesTheMappingAndDestroysTheFiles(): void {
		$purged = null;
		$files = [$this->dashFile()];
		$svc = new MappingService(
			$this->config(),
			$this->storage(),
			$this->recycleBin(),
			$this->existingDashboardsHolding($files, $purged),
		);

		$svc->add($this->linkMapping(), [], true);

		self::assertCount(1, $svc->list());
		self::assertSame($files, $purged, 'the acknowledged files were not the ones destroyed');
	}

	/**
	 * A SYNC MAPPING IS UNTOUCHED. It pushes what it finds up to Grafana, so nothing is
	 * destroyed and nothing is confirmed — the rule is about links alone, and asking a
	 * sync mapping the question would refuse the ordinary case of mapping a folder that
	 * already holds work.
	 */
	public function testASyncMappingOverTheSameFilesIsNotEvenAsked(): void {
		$purged = null;
		$svc = new MappingService(
			$this->config(),
			$this->storage(),
			$this->recycleBin(),
			$this->existingDashboardsHolding([$this->dashFile()], $purged),
		);

		$svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-a',
			'grafana_folder_title' => 'observe',
			'nc_folder' => 'Dashboards',
			'mode' => 'sync',
		]));

		self::assertCount(1, $svc->list());
		self::assertNull($purged, 'a sync mapping destroyed files it should have adopted');
	}

	/**
	 * NOTHING IS DESTROYED FOR A MAPPING THAT WAS NEVER MADE. The purge runs after the
	 * mapping is persisted, so an admin who acknowledges the files and then hits a
	 * different refusal keeps both the files and the absence of the mapping.
	 */
	public function testAMappingRefusedForAnotherReasonPurgesNothing(): void {
		$purged = null;
		$svc = new MappingService(
			$this->config(),
			$this->storage(),
			$this->recycleBin(),
			$this->existingDashboardsHolding([$this->dashFile()], $purged),
		);
		$svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-taken',
			'grafana_folder_title' => 'other',
			'nc_folder' => 'Dashboards',
			'mode' => 'sync',
		]));

		// Same Nextcloud folder as the one just mapped — refused before anything else.
		$this->expectException(\InvalidArgumentException::class);
		try {
			$svc->add($this->linkMapping(), [], true);
		} finally {
			self::assertNull($purged, 'files were destroyed for a mapping that was refused');
		}
	}

	/** With no bin configured nothing is reserved, so an ordinary mapping still saves. */
	public function testWithNoBinConfiguredNothingIsReserved(): void {
		$svc = new MappingService($this->config(), $this->storage(), $this->recycleBin(''), $this->existingDashboards());

		$svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-a',
			'grafana_folder_title' => '',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
		]));

		self::assertCount(1, $svc->list());
	}

	public function testResolveForPathPicksTheNearestEnclosingMapping(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-outer', 'dashboards'));
		$inner = $svc->add($this->mapping('uid-inner', 'dashboards/observe'));

		$hit = $svc->resolveForPath('/admin/files/dashboards/observe/cpu.grafana');
		self::assertNotNull($hit);
		self::assertSame($inner->id, $hit->id, 'the deepest (longest-prefix) mapping wins');
	}

	/**
	 * THE DEFECT THIS FIELD EXISTS TO REMOVE.
	 *
	 * Renaming a mapped folder used to orphan the mapping: it stored the name, so
	 * after the rename nothing was called that any more and every file underneath
	 * resolved to no mapping — silently, with no error and no sync. Holding the
	 * folder ID means the rename is a no-op, which is what
	 * `features/mapping/rename.feature` says must happen.
	 */
	public function testAMappedFolderThatWasRenamedStillResolves(): void {
		$svc = $this->service();
		$this->folderPaths[512] = 'Demo';
		$saved = $svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-demo',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 512,
		]));

		// The admin renames the folder in Nextcloud. Nothing tells the mapping.
		$this->folderPaths[512] = 'Dashboards';

		$hit = $svc->resolveForPath('/admin/files/Dashboards/cpu.grafana');
		self::assertNotNull($hit, 'the mapping must follow its folder through a rename');
		self::assertSame($saved->id, $hit->id);

		// And the old name is now just a name — nothing lives there to claim.
		self::assertNull($svc->resolveForPath('/admin/files/Demo/cpu.grafana'));

		// The stored label catches up too, so the panel and `occ` do not keep showing
		// a folder that no longer exists.
		self::assertSame('Dashboards', $svc->getById($saved->id)?->ncFolder);

		// And the RETURNED object carries the caught-up label, not the copy the
		// resolve loop started with. MoveGuardListener puts this straight into a
		// message, so a stale one names the folder the admin just stopped using.
		self::assertSame('Dashboards', $hit->ncFolder);
	}

	/**
	 * A move is a different gesture from a rename — new parent, same name — and the
	 * id covers it for the same reason: it is the folder that moved, not the mapping.
	 */
	public function testAMappedFolderThatWasMovedStillResolves(): void {
		$svc = $this->service();
		$this->folderPaths[77] = 'Demo';
		$saved = $svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-demo',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 77,
		]));

		$this->folderPaths[77] = 'Archive/Demo';

		$hit = $svc->resolveForPath('/admin/files/Archive/Demo/cpu.grafana');
		self::assertNotNull($hit);
		self::assertSame($saved->id, $hit->id);
	}

	/**
	 * The other half of that rule, and the reason it needs one.
	 *
	 * A mapping whose folder is gone matches nothing, so the mapping goes SILENT —
	 * no link guard, no gesture, and no explanation. That is correct while nobody has
	 * provisioned a replacement, and wrong the moment this app creates one ITSELF: a
	 * folder `ensureFolder()` made for this mapping is not a folder that happens to
	 * share a name, it is this mapping's folder, and recording its id is a fact
	 * rather than a guess.
	 */
	public function testProvisioningRebanksTheFolderIdSoTheMappingWorksAgain(): void {
		$svc = $this->service();
		$this->folderPaths[900] = 'Demo';
		$saved = $svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-demo',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 900,
		]));

		// The folder is deleted and re-provisioned somewhere with a new id.
		unset($this->folderPaths[900]);
		self::assertNull($svc->resolveForPath('/admin/files/Demo/cpu.grafana'));

		$this->folderPaths[901] = 'Demo';
		$svc->bankFolderId($saved->id, 901);

		$hit = $svc->resolveForPath('/admin/files/Demo/cpu.grafana');
		self::assertNotNull($hit);
		self::assertSame($saved->id, $hit->id);
		self::assertSame(901, $hit->ncFolderId);
	}

	/** A banked id is never replaced by "unknown". */
	public function testBankingRefusesAnIdThatIsNotOne(): void {
		$svc = $this->service();
		$this->folderPaths[900] = 'Demo';
		$saved = $svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-demo',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 900,
		]));

		$svc->bankFolderId($saved->id, 0);

		self::assertSame(900, $svc->getById($saved->id)?->ncFolderId);
	}

	/**
	 * A folder that is genuinely gone matches nothing.	/**
	 * A folder that is genuinely gone matches nothing. It is NOT repaired by falling
	 * back to the stored name: a new folder that happens to reuse the name is a
	 * different folder, and adopting it would point the mapping somewhere nobody
	 * chose.
	 */
	public function testAMappingWhoseFolderIsGoneMatchesNothing(): void {
		$svc = $this->service();
		$this->folderPaths[900] = 'Demo';
		$svc->add(Mapping::fromArray([
			'grafana_folder_uid' => 'uid-demo',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 900,
		]));

		unset($this->folderPaths[900]);

		self::assertNull($svc->resolveForPath('/admin/files/Demo/cpu.grafana'));
	}

	public function testResolveForPathRespectsSegmentBoundaries(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'observe'));
		// "observability" must NOT be swallowed by the "observe" mapping.
		self::assertNull($svc->resolveForPath('/admin/files/observability/x.grafana'));
	}

	public function testResolveForPathReturnsNullOutsideFilesRoot(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'observe'));
		self::assertNull($svc->resolveForPath('/some/other/path'));
	}

	// ── groups are the folder's, not the mapping's ───────────────────────────────

	/**
	 * THE HEADLINE: groups given at create reach the FOLDER and are not persisted.
	 *
	 * Three apps in this family can map to one folder. While each stored its own
	 * list, every sync stamped that list over the others' and they fought forever,
	 * none of them wrong. This is the assertion that they have stopped.
	 */
	public function testGroupsGivenAtCreateGoToTheFolderNotTheStore(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[]');
		$written = null;
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written = $value;
				return true;
			},
		);

		$service = new MappingService($config, $this->storage(), $this->recycleBin(), $this->existingDashboards());
		$mapping = $service->add(
			Mapping::fromArray(['id' => 'm1', 'grafana_folder_uid' => 'a', 'nc_folder' => 'a', 'mode' => 'sync']),
			['design', 'admin'],
		);

		self::assertStringNotContainsString('nc_groups', (string)$written, 'the stored blob must not carry groups');
		self::assertSame(['design', 'admin'], $this->appliedGroups['m1'], 'the groups must reach the folder');
		self::assertSame(['design', 'admin'], $service->groupsOf($mapping));
	}

	/** Changing the groups writes to the folder and persists nothing at all. */
	public function testUpdatingGroupsStoresNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","grafana_folder_uid":"a","nc_folder":"a","mode":"sync"}]');
		$config->expects(self::never())->method('setValueString');

		$service = new MappingService($config, $this->storage(), $this->recycleBin(), $this->existingDashboards());
		self::assertSame(['design'], $service->updateGroups('m1', 'design'));
		self::assertSame(['design'], $this->appliedGroups['m1']);
	}

	/** A narrowed set really narrows — the old code could only ever add. */
	public function testGroupsCanBeNarrowedAndCleared(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","grafana_folder_uid":"a","nc_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage(), $this->recycleBin(), $this->existingDashboards());
		$service->updateGroups('m1', 'design,admin,sales');
		self::assertSame(['design'], $service->updateGroups('m1', 'design'));
		self::assertSame([], $service->updateGroups('m1', ''));
	}

	/** describe() is the stored shape PLUS what the folder currently reports. */
	public function testDescribeAddsTheFoldersGroupsToTheStoredShape(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[{"id":"m1","grafana_folder_uid":"a","nc_folder":"a","mode":"sync"}]');

		$service = new MappingService($config, $this->storage(), $this->recycleBin(), $this->existingDashboards());
		$service->updateGroups('m1', 'design');
		$mapping = $service->getById('m1');
		self::assertNotNull($mapping);

		$described = $service->describe($mapping);
		self::assertSame(['design'], $described['nc_groups']);
		self::assertArrayNotHasKey('nc_groups', $mapping->toArray(), 'the STORED shape carries no groups');
	}

	/**
	 * A stored row from before this change still parses — its `nc_groups` key is
	 * simply not a field any more, and reading it must not throw.
	 */
	public function testAStoredRowWithGroupsStillParsesAndIgnoresThem(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(
			'[{"id":"m1","grafana_folder_uid":"a","nc_folder":"a","mode":"sync","nc_groups":["devs"]}]',
		);

		$mappings = (new MappingService($config, $this->storage(), $this->recycleBin(), $this->existingDashboards()))->list();
		self::assertCount(1, $mappings);
		self::assertArrayNotHasKey('nc_groups', $mappings[0]->toArray());
	}
}
