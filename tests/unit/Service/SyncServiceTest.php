<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\OwnershipTags;
use OCA\GrafanaSync\Service\StorageService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the {@see SyncService} pull (saga Ch2 Course 2). The end-to-end
 * wiring is covered by reconcile.feature + the live smoke test; these pin the
 * reconcile *decisions* the orchestration makes, so a regression can't land silently:
 *
 *  - a Team Folder mapping with no groups (invisible) + an unavailable backend are
 *    skipped before any write;
 *  - a fresh dashboard is written, metadata-stamped, and pilled;
 *  - a re-pull matches by **uid** and updates in place (never a duplicate);
 *  - a managed file whose dashboard left the folder is pruned — but one owned by a
 *    *different* mapping is left alone;
 *  - `link` mode writes a pointer without reading the full spec.
 *
 * Doubles follow the repo convention: canned collaborators are `createStub`; the file
 * nodes / metadata / tags whose calls we verify are `createMock`. `final` collaborators
 * rely on the unit bootstrap's `dg/bypass-finals`.
 */
#[CoversClass(SyncService::class)]
final class SyncServiceTest extends TestCase {
	private MappingService $mappings;
	private GrafanaClient $grafana;
	private DashboardMetadata $metadata;
	private OwnershipTags $tags;
	private StorageService $storage;
	private SyncService $service;

	protected function setUp(): void {
		$this->mappings = $this->createStub(MappingService::class);
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->tags = $this->createMock(OwnershipTags::class);
		$this->storage = $this->createStub(StorageService::class);

		// SyncGuard just brackets work in enter/leave; inert stub is enough.
		$guard = $this->createStub(SyncGuard::class);

		// fixupFilecacheMimetype: a no-op pair, never asserted.
		$mimeLoader = $this->createStub(IMimeTypeLoader::class);
		$mimeLoader->method('getId')->willReturn(1);

		$this->service = new SyncService(
			$this->mappings,
			$this->grafana,
			$this->metadata,
			$this->tags,
			$this->storage,
			$guard,
			$mimeLoader,
			new NullLogger(),
		);
	}

	private function mapping(string $mode = Mapping::MODE_SYNC, string $id = 'map-alpha', bool $useTeamFolder = false, array $groups = ['admin']): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'grafana_folder_uid' => 'gf-alpha',
			'grafana_folder_title' => 'alpha',
			'nc_folder' => 'alpha',
			'mode' => $mode,
			'nc_groups' => $groups,
			'use_team_folder' => $useTeamFolder,
		]);
	}

	/** A managed `.grafana.json` File stub with a fixed id + name (no verified interactions). */
	private function file(int $id, string $name): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		return $node;
	}

	/** A {@see ManagedFile} read() stub value (the typed metadata DashboardMetadata::read returns). */
	private function managed(string $uid = '', string $mode = '', string $mappingId = ''): ManagedFile {
		return new ManagedFile($uid, $mode, '', '', $mappingId, '', '');
	}

	/** One `/api/search` row in the shape {@see GrafanaClient::listDashboards} returns. */
	private function row(string $uid, string $title): array {
		return ['uid' => $uid, 'title' => $title, 'folderUid' => 'gf-alpha', 'url' => '/d/' . $uid . '/x', 'tags' => []];
	}

	// ── guards ───────────────────────────────────────────────────────────────

	public function testPullOneSkipsTeamFolderMappingWithNoGroups(): void {
		// A Team Folder shared with nobody is invisible — skipped with all-zero counts
		// before any provisioning (ensureFolder is never reached; storage stays a stub).
		$res = $this->service->pullOne($this->mapping(useTeamFolder: true, groups: []));

		self::assertSame(['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0], $res);
	}

	public function testPullOneSkipsWhenStorageUnavailable(): void {
		$this->storage->method('isAvailable')->willReturn(false);

		$res = $this->service->pullOne($this->mapping(useTeamFolder: true));

		self::assertSame(0, $res['processed']);
	}

	// ── fresh write ────────────────────────────────────────────────────────────

	public function testPullOneWritesStampsAndPillsAFreshDashboard(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]); // nothing local yet
		$folder->method('nodeExists')->willReturn(false);
		$folder->expects(self::once())
			->method('newFile')
			->with('Board.grafana.json', self::isType('string'))
			->willReturn($this->file(100, 'Board.grafana.json'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboard')->with('d1')
			->willReturn(['dashboard' => ['uid' => 'd1', 'title' => 'Board', 'version' => 5], 'meta' => ['folderUid' => 'gf-alpha']]);

		// The core contract: uid + mode + version stamped, and the sync pill applied.
		$this->metadata->expects(self::once())
			->method('stampSynced')
			->with(100, 'd1', Mapping::MODE_SYNC, '5', self::isType('string'), 'map-alpha');
		$this->tags->expects(self::once())->method('apply')->with(100, Mapping::MODE_SYNC);

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['pruned']);
	}

	// ── update in place by uid ───────────────────────────────────────────────────

	public function testPullOneUpdatesInPlaceByUidWithoutDuplicating(): void {
		// The local file's name already matches the canonical form, so it updates in
		// place (putContent) — no rename, and crucially no newFile (no "(2)" duplicate).
		$canonical = FilenameCodec::format('Board', 'd1', false, 0);
		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(10);
		$existing->method('getName')->willReturn($canonical);
		$existing->expects(self::once())->method('putContent');

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$existing]);
		$folder->expects(self::never())->method('newFile');

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboard')->willReturn(['dashboard' => ['uid' => 'd1', 'title' => 'Board', 'version' => 6]]);

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['pruned']);
	}

	// ── prune ────────────────────────────────────────────────────────────────────

	public function testPullOnePrunesFileWhoseDashboardLeftTheFolder(): void {
		$canonical = FilenameCodec::format('Keep', 'd-keep', false, 0);
		$keep = $this->createMock(File::class);
		$keep->method('getId')->willReturn(10);
		$keep->method('getName')->willReturn($canonical);
		$keep->method('putContent');
		$keep->expects(self::never())->method('delete');

		$stale = $this->createMock(File::class);
		$stale->method('getId')->willReturn(11);
		$stale->method('getName')->willReturn('Gone.grafana.json');
		$stale->expects(self::once())->method('delete');

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$keep, $stale]);
		$folder->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturnCallback(fn (int $id): ?ManagedFile => match ($id) {
			10 => $this->managed('d-keep', Mapping::MODE_SYNC, 'map-alpha'),
			11 => $this->managed('d-stale', Mapping::MODE_SYNC, 'map-alpha'),
			default => null,
		});
		// Grafana still returns only the "keep" dashboard.
		$this->grafana->method('listDashboards')->willReturn([$this->row('d-keep', 'Keep')]);
		$this->grafana->method('readDashboard')->willReturn(['dashboard' => ['uid' => 'd-keep', 'title' => 'Keep']]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(1, $res['pruned']);
	}

	public function testPruneLeavesAFileOwnedByADifferentMapping(): void {
		// A file in this subtree stamped with ANOTHER mapping's id is never indexed,
		// so even though its dashboard isn't seen this pull, prune must not delete it.
		$foreign = $this->createMock(File::class);
		$foreign->method('getId')->willReturn(20);
		$foreign->method('getName')->willReturn('Foreign.grafana.json');
		$foreign->expects(self::never())->method('delete');

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$foreign]);
		$folder->method('nodeExists')->willReturn(false);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('d-foreign', Mapping::MODE_SYNC, 'other-mapping'));
		$this->grafana->method('listDashboards')->willReturn([]);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(0, $res['pruned']);
	}

	// ── link mode ────────────────────────────────────────────────────────────────

	public function testPullOneLinkModeWritesPointerWithoutReadingTheSpec(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->file(200, 'Board.grafana.json'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('deepLinkFromPath')->willReturn('https://grafana.example/d/d1/x');
		// A link never reads the full dashboard spec.
		$this->grafana->expects(self::never())->method('readDashboard');

		$this->tags->expects(self::once())->method('apply')->with(200, Mapping::MODE_LINK);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_LINK));

		self::assertSame(1, $res['succeeded']);
	}
}
