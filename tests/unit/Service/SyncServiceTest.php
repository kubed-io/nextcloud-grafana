<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardBody;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DashboardSpec;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderTreeMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MirrorTimes;
use OCA\GrafanaSync\Service\PushService;
use OCA\GrafanaSync\Service\StorageService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncService;
use OCA\GrafanaSync\Service\TagSyncService;
use OCA\GrafanaSync\Service\TrashControl;
use OCA\GrafanaSync\Service\TrashReconcileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the {@see SyncService} pull (saga Ch2 Course 2). The end-to-end
 * wiring is covered by sync-now.feature + the live smoke test; these pin the
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
	private StorageService $storage;
	private PushService $push;
	private MirrorTimes $times;
	private FolderTreeMirror $tree;
	private FolderMetadata $folders;
	private TagSyncService $tagSync;
	private SyncGuard $guard;
	private SyncService $service;

	protected function setUp(): void {
		$this->mappings = $this->createStub(MappingService::class);
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->storage = $this->createStub(StorageService::class);

		// SyncGuard just brackets work in enter/leave; inert stub is enough.
		$guard = $this->createStub(SyncGuard::class);

		// The pull path never calls PushService; the push tests configure it.
		$this->push = $this->createMock(PushService::class);

		// MirrorTimes reaches into the storage/cache stack, so it is mocked here and
		// covered on its own in MirrorTimesTest — the reconciler only owes the mapping.
		$this->times = $this->createMock(MirrorTimes::class);

		// A tree with no mirrored subfolders: every dashboard lands in the mapping's
		// root, which is what these tests are about. The tree's own behaviour is
		// covered in FolderTreeMirrorTest.
		$this->tree = $this->createStub(FolderTreeMirror::class);
		$this->tree->method('sync')->willReturn([]);

		// NO FOLDER IS A MIRROR unless a test says so. `uidOf` returning '' is what
		// tells the pull that a file's folder is one the user made — so the default
		// here is "leave every mirror where it is", and a relocation test opts in.
		$this->folders = $this->createStub(FolderMetadata::class);
		$this->folders->method('uidOf')->willReturn('');

		// Tag import is asserted in TagSyncServiceTest; here it only has to not fire.
		$this->tagSync = $this->createStub(TagSyncService::class);

		$this->guard = $guard;
		$this->rebuildService();
	}

	/**
	 * Rebuild the service from the current collaborators.
	 *
	 * A test that swaps the tree stub has to rebuild, because the service takes it by
	 * constructor — and swapping after construction would leave the old one wired in
	 * while the test believed otherwise.
	 */
	private function rebuildService(): void {
		$this->service = new SyncService(
			$this->mappings,
			$this->grafana,
			$this->metadata,
			$this->storage,
			$this->guard,
			$this->push,
			$this->times,
			$this->tree,
			$this->folders,
			$this->tagSync,
			// A REAL TrashControl, not a double. The unit suite has no
			// `files_trashbin`, so `withoutTrash()` finds no manager and simply runs
			// the callback — which is the production path on an instance without the
			// trash app, and the only one these tests should be exercising anyway.
			new TrashControl(
				$this->createStub(ContainerInterface::class),
				$this->createStub(IUserManager::class),
				$this->createStub(IUserSession::class),
				new NullLogger(),
			),
			// The reconcile is a pass INSIDE the pull, not the point of it — these tests
			// are about what the pull writes, so it is stubbed to do nothing. Its own
			// behaviour has its own tests.
			$this->createStub(TrashReconcileService::class),
			new NullLogger(),
		);
	}

	private function mapping(string $mode = Mapping::MODE_SYNC, string $id = 'map-alpha', bool $useTeamFolder = false): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'grafana_folder_uid' => 'gf-alpha',
			'grafana_folder_title' => 'alpha',
			'nc_folder' => 'alpha',
			'mode' => $mode,
			'use_team_folder' => $useTeamFolder,
		]);
	}

	/** A managed `.grafana` File stub with a fixed id + name (no verified interactions). */
	private function file(int $id, string $name): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		return $node;
	}

	/** A {@see ManagedFile} read() stub value (the typed metadata DashboardMetadata::read returns). */
	private function managed(string $uid = '', string $mode = '', string $mappingId = ''): ManagedFile {
		return new ManagedFile($uid, $mode, '', '', $mappingId, '');
	}

	/** One `/api/search` row in the shape {@see GrafanaClient::listDashboards} returns. */
	private function row(string $uid, string $title): array {
		return ['uid' => $uid, 'title' => $title, 'folderUid' => 'gf-alpha', 'url' => '/d/' . $uid . '/x', 'tags' => []];
	}

	// ── guards ───────────────────────────────────────────────────────────────

	// THE "SKIP A TEAM FOLDER WITH NO GROUPS" TEST IS GONE, with the guard it
	// covered. It read $mapping->ncGroups, which no longer exists — the groups are
	// the folder's now, so the sync has no list to inspect and nothing to decide.
	//
	// It was also the wrong behaviour to protect: an unshared folder is the admin's
	// business, plainly visible in the mapping card and in Files, and refusing to
	// sync into it turned a sharing question into a mysteriously empty folder. The
	// storage-unavailable skip below is the one that still earns its place, because
	// there the app genuinely cannot proceed.

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
			->with('Board.grafana', self::isString())
			->willReturn($this->file(100, 'Board.grafana'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);

		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')->with('d1')
			->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board', 'version' => 5], null, null));

		// The core contract: uid + mode + version stamped, and the sync pill applied.
		$this->metadata->expects(self::once())
			->method('stampSynced')
			->with(100, 'd1', Mapping::MODE_SYNC, '5', self::isString(), 'map-alpha');

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
		$this->grafana->method('readDashboardSpec')->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board', 'version' => 6], null, null));

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['pruned']);
	}

	// ── where a mirror lives ─────────────────────────────────────────────────

	/**
	 * A dashboard that moved to a Grafana subfolder takes its mirror with it.
	 *
	 * THE BUG THIS GUARDS WAS FOUND BY HAND, ON A LIVE INSTANCE, and could not have
	 * been found any other way at the time: no assertion in the whole integration
	 * suite knew what a subfolder was, so a pull that flattened every dashboard onto
	 * the mapping's root passed everything. The mirror was reconciled for contents,
	 * name, stamp and tags — everything except the one thing that was wrong.
	 */
	public function testPullOneMovesAMirrorIntoTheFolderMirroringItsGrafanaFolder(): void {
		$sub = $this->createMock(Folder::class);
		$sub->method('getId')->willReturn(2);
		$sub->method('getPath')->willReturn('/admin/files/alpha/Region');
		$sub->method('nodeExists')->willReturn(false);
		$sub->method('getDirectoryListing')->willReturn([]);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(1);
		$root->method('getPath')->willReturn('/admin/files/alpha');

		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(10);
		$existing->method('getName')->willReturn(FilenameCodec::format('Board', 'd1', false, 0));
		$existing->method('getParent')->willReturn($root);
		// The whole assertion: it is moved into the subfolder, keeping its name.
		$existing->expects(self::once())
			->method('move')
			->with('/admin/files/alpha/Region/' . FilenameCodec::format('Board', 'd1', false, 0));

		$root->method('getDirectoryListing')->willReturn([$existing]);
		$root->expects(self::never())->method('newFile');

		$this->tree = $this->createStub(FolderTreeMirror::class);
		$this->tree->method('sync')->willReturn(['gf-sub' => $sub]);
		$this->rebuildService();

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($root);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));

		// SCOPED PER FOLDER, because the pull asks Grafana once per folder it mirrors
		// and a blanket willReturn would hand the same dashboard back for every scope.
		$this->grafana->method('listDashboards')->willReturnCallback(
			static fn (string $scope): array => $scope === 'gf-sub'
				? [['uid' => 'd1', 'title' => 'Board', 'folderUid' => 'gf-sub', 'url' => '/d/d1/x', 'tags' => []]]
				: [],
		);
		$this->grafana->method('readDashboardSpec')
			->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board', 'version' => 6], null, null));

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
	}

	/**
	 * A mirror in a folder the USER made is left exactly where they put it.
	 *
	 * The counterweight to the test above, and the reason the relocation asks whether
	 * a folder is one this app manages rather than simply moving whatever is in the
	 * wrong place. A folder with no Grafana uid stamped on it is somebody's filing,
	 * and a pull that tidied it away every seventy seconds would be worse than the
	 * bug it fixed.
	 */
	public function testPullOneLeavesAMirrorInAFolderTheUserMade(): void {
		$theirs = $this->createMock(Folder::class);
		$theirs->method('getId')->willReturn(3); // not the root, and carries no uid
		$theirs->method('getPath')->willReturn('/admin/files/alpha/Drafts');
		$theirs->method('nodeExists')->willReturn(false);

		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(1);
		$root->method('getPath')->willReturn('/admin/files/alpha');

		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(10);
		$existing->method('getName')->willReturn(FilenameCodec::format('Board', 'd1', false, 0));
		$existing->method('getParent')->willReturn($theirs);
		$existing->expects(self::never())->method('move');

		$root->method('getDirectoryListing')->willReturn([$existing]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($root);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')
			->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board', 'version' => 6], null, null));

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['processed']);
	}

	// ── pull change-detection (saga Ch2, Course 7) ───────────────────────────────
	//
	// The defect, inherited from the n8n master and fixed there first: writeDashboard
	// called putContent() unconditionally, so every pull rewrote every mirror and the
	// whole folder read "Modified a few seconds ago" after every tick.
	//
	// The comparison only works because DashboardBody strips the VOLATILE fields
	// (`id`, `version`) Grafana rewrites on every save — hence the `version` bump in
	// the "unchanged" fixture below, which must NOT count as a change.

	public function testASyncReadFailureCountsAsFailedNotSucceeded(): void {
		// A transient Grafana error while reading the spec means we never learned what
		// the dashboard holds. Reporting that as a clean pull would tell an admin the
		// mapping is in step while its mirrors sit stale — so it has to reach the
		// failure counter, which means the sync path must NOT swallow the exception.
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')->willThrowException(new \RuntimeException('Grafana 500'));

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_SYNC));

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['failed']);
		self::assertSame(0, $res['succeeded']);
		self::assertSame(0, $res['unchanged']);
	}

	public function testPullHandsGrafanasOwnTimestampsToTheClockStamper(): void {
		// The reconciler owes one thing here: pass through the two clocks the client
		// already decoded, and say whether the body was just rewritten. The
		// write-only-what-differs rule is MirrorTimes' — see MirrorTimesTest.
		$spec = (object)['uid' => 'd1', 'title' => 'Board', 'version' => 6];
		$body = DashboardBody::encodeSync($spec);

		$existing = $this->mirror(10, FilenameCodec::format('Board', 'd1', false, 0), $body);

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$existing]);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')->willReturn(new DashboardSpec($spec, 1771000000, 1739500000));

		// Unchanged body → $force is false, so MirrorTimes decides by comparison.
		$this->times->expects(self::once())
			->method('apply')
			->with($existing, 1771000000, 1739500000, false);

		self::assertSame(1, $this->service->pullOne($this->mapping())['unchanged']);
	}

	public function testAJustWrittenMirrorForcesTheClockRestamp(): void {
		// The body was rewritten, so the file's mtime is `now` — comparing would read a
		// value we already know is wrong. The reconciler says so with $force = true.
		$spec = (object)['uid' => 'd1', 'title' => 'Board'];

		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(10);
		$existing->method('getName')->willReturn(FilenameCodec::format('Board', 'd1', false, 0));
		$existing->method('getSize')->willReturn(1); // differs → rewritten

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$existing]);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')->willReturn(new DashboardSpec($spec, 1771000000, null));

		$this->times->expects(self::once())->method('apply')->with($existing, 1771000000, null, true);

		self::assertSame(0, $this->service->pullOne($this->mapping())['unchanged']);
	}

	public function testPullDoesNotRewriteAMirrorThatAlreadyMatchesGrafana(): void {
		$spec = (object)['uid' => 'd1', 'title' => 'Board', 'version' => 6];
		$body = DashboardBody::encodeSync($spec);

		$existing = $this->mirror(10, FilenameCodec::format('Board', 'd1', false, 0), $body);
		$existing->expects(self::never())->method('putContent');

		self::assertSame(1, $this->pullWith($existing, $spec)['unchanged']);
	}

	public function testAVersionBumpAloneIsNotAChange(): void {
		// Grafana bumps `version` on every save, including saves that changed nothing
		// we mirror. VOLATILE strips it, so the body is identical and the mirror must
		// not be rewritten — this is the assumption the whole skip rests on.
		$onDisk = DashboardBody::encodeSync((object)['uid' => 'd1', 'title' => 'Board', 'version' => 6]);
		$fromGrafana = (object)['uid' => 'd1', 'title' => 'Board', 'version' => 41];

		$existing = $this->mirror(10, FilenameCodec::format('Board', 'd1', false, 0), $onDisk);
		$existing->expects(self::never())->method('putContent');

		// The stamp still advances to the new version — only the BODY is skipped.
		$this->metadata->expects(self::once())
			->method('stampSynced')
			->with(10, 'd1', Mapping::MODE_SYNC, '41', self::isString(), 'map-alpha');

		self::assertSame(1, $this->pullWith($existing, $fromGrafana)['unchanged']);
	}

	public function testPullRewritesAMirrorWhoseDashboardChangedInGrafana(): void {
		// Same length as the stale mirror, so only the CONTENT comparison can catch it
		// — proof the cheap size check is a shortcut, never the whole test.
		$spec = (object)['uid' => 'd1', 'title' => 'Board B'];
		$body = DashboardBody::encodeSync($spec);
		$stale = str_replace('Board B', 'Board A', $body);
		self::assertSame(strlen($body), strlen($stale), 'fixture must be same-length to exercise the content compare');

		$existing = $this->mirror(10, FilenameCodec::format('Board B', 'd1', false, 0), $stale);
		$existing->expects(self::once())->method('putContent')->with($body);

		$res = $this->pullWith($existing, $spec);

		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['unchanged']);
	}

	public function testPullRewritesWithoutReadingWhenTheSizeAlreadyDiffers(): void {
		// A differing size is an exact "changed" answer straight from the filecache, so
		// a changed dashboard never pays for a storage read.
		$spec = (object)['uid' => 'd1', 'title' => 'Board'];

		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(10);
		$existing->method('getName')->willReturn(FilenameCodec::format('Board', 'd1', false, 0));
		$existing->method('getSize')->willReturn(1);
		$existing->expects(self::never())->method('getContent');
		$existing->expects(self::once())->method('putContent');

		self::assertSame(0, $this->pullWith($existing, $spec)['unchanged']);
	}

	public function testPullRewritesWhenTheMirrorCannotBeRead(): void {
		// An unreadable mirror must degrade to the old always-write behaviour, never to
		// "leave it alone" — a pull still has to be able to repair a broken file.
		$spec = (object)['uid' => 'd1', 'title' => 'Board'];
		$body = DashboardBody::encodeSync($spec);

		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(10);
		$existing->method('getName')->willReturn(FilenameCodec::format('Board', 'd1', false, 0));
		$existing->method('getSize')->willReturn(strlen($body));
		$existing->method('getContent')->willThrowException(new \RuntimeException('storage unreachable'));
		$existing->expects(self::once())->method('putContent')->with($body);

		self::assertSame(0, $this->pullWith($existing, $spec)['unchanged']);
	}

	public function testASpecLessDashboardIsNotCountedAsUnchanged(): void {
		// Nothing was written, but nothing was verified either — we never learned what
		// Grafana holds, so it must not read as a clean no-op.
		$existing = $this->mirror(10, FilenameCodec::format('Board', 'd1', false, 0), 'whatever');

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$existing]);
		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')->willReturn(null);

		self::assertSame(0, $this->service->pullOne($this->mapping())['unchanged']);
	}

	public function testAFreshWriteIsNeverCountedAsUnchanged(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->file(100, 'Board.grafana'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('readDashboardSpec')->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board'], null, null));

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['unchanged']);
	}

	/**
	 * A managed mirror File mock whose size and content both report $body — what
	 * {@see SyncService::bodyDiffers} reads. A mock (not a stub) so the caller can
	 * assert on putContent.
	 */
	private function mirror(int $id, string $name, string $body): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		$node->method('getSize')->willReturn(strlen($body));
		$node->method('getContent')->willReturn($body);
		return $node;
	}

	/**
	 * Pull one mapping holding exactly $mirror, with Grafana returning exactly $spec.
	 * The mirror is already canonically named and owned by the mapping, so the only
	 * decision left in writeDashboard is whether to write the body.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, unchanged:int}
	 */
	private function pullWith(File $mirror, \stdClass $spec): array {
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$mirror]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed($spec->uid, Mapping::MODE_SYNC, 'map-alpha'));
		$this->grafana->method('listDashboards')->willReturn([$this->row($spec->uid, $spec->title)]);
		$this->grafana->method('readDashboardSpec')->willReturn(new DashboardSpec($spec, null, null));

		return $this->service->pullOne($this->mapping());
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
		$stale->method('getName')->willReturn('Gone.grafana');
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
		$this->grafana->method('readDashboardSpec')->willReturn(new DashboardSpec((object)['uid' => 'd-keep', 'title' => 'Keep'], null, null));

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
		$foreign->method('getName')->willReturn('Foreign.grafana');
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

	public function testPullOneLinkModeWritesAPointerBodyNotTheSpec(): void {
		// A link's BODY is still the pointer built from the search row — the spec is
		// never serialized into it. What changed in Course 8 is that the record IS now
		// read, because `/api/search` carries no timestamps and a link's clocks have to
		// come from somewhere. One GET per link, per pull, deliberately (saga Ch2 §8).
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')
			->with('Board.grafana', self::stringContains('grafana.reference/v1'))
			->willReturn($this->file(200, 'Board.grafana'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('deepLinkFromPath')->willReturn('https://grafana.example/d/d1/x');
		$this->grafana->method('readDashboardSpec')
			->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board'], 1700, 900));

		// The point of paying for that GET: a link's clocks are real too.
		$this->times->expects(self::once())->method('apply')->with(self::anything(), 1700, 900, true);

		$res = $this->service->pullOne($this->mapping(Mapping::MODE_LINK));

		self::assertSame(1, $res['succeeded']);
	}

	public function testALinkStillGetsItsPointerWhenTheRecordCannotBeRead(): void {
		// The record is only wanted for the clocks here, so a read that fails must cost
		// the link its dates, never its file.
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->file(200, 'Board.grafana'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($folder);
		$this->grafana->method('listDashboards')->willReturn([$this->row('d1', 'Board')]);
		$this->grafana->method('deepLinkFromPath')->willReturn('https://grafana.example/d/d1/x');
		$this->grafana->method('readDashboardSpec')->willThrowException(new \RuntimeException('Grafana 500'));

		$this->times->expects(self::once())->method('apply')->with(self::anything(), null, null, true);

		self::assertSame(1, $this->service->pullOne($this->mapping(Mapping::MODE_LINK))['succeeded']);
	}

	// ── push (bulk Sync to Grafana) ──────────────────────────────────────────────

	public function testPushOneIsANoOpForALinkMapping(): void {
		// A link mapping never reaches storage; the returned zeros prove the bail.
		$this->push->expects(self::never())->method('push');

		$res = $this->service->pushOne($this->mapping(Mapping::MODE_LINK));

		self::assertSame(['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null], $res);
	}

	public function testPushOnePushesManagedSyncFilesAndSkipsTheRest(): void {
		$syncFile = $this->file(1, 'Flow.grafana');
		$notOurs = $this->file(2, 'notes.txt');          // wrong extension → skipped
		$linkFile = $this->file(3, 'Pointer.grafana'); // link mode → skipped

		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$syncFile, $notOurs, $linkFile]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturnCallback(fn (int $id): ?ManagedFile => match ($id) {
			1 => $this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'),
			3 => $this->managed('d3', Mapping::MODE_LINK, 'map-alpha'),
			default => null,
		});

		// Only the managed sync file is a push candidate.
		$this->push->expects(self::once())->method('push')->with($syncFile)->willReturn(true);

		$res = $this->service->pushOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
		self::assertSame(0, $res['failed']);
		self::assertNull($res['message']);
	}

	public function testPushOneCarriesAPerFileFailureMessage(): void {
		$syncFile = $this->file(1, 'Flow.grafana');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$syncFile]);

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->push->method('push')->willThrowException(new \RuntimeException('boom'));

		$res = $this->service->pushOne($this->mapping(Mapping::MODE_SYNC, 'map-alpha'));

		self::assertSame(1, $res['processed']);
		self::assertSame(0, $res['succeeded']);
		self::assertSame(1, $res['failed']);
		self::assertNotNull($res['message']);
		self::assertStringContainsString('Flow.grafana', (string)$res['message']);
	}

	// ── dispatch / runInline (the parity seam) ───────────────────────────────────

	public function testDispatchRejectsAnUnknownDirection(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->dispatch('sideways', null);
	}

	public function testDispatchRunsPullInlineOverAllMappings(): void {
		$this->mappings->method('list')->willReturn([]); // no mappings → clean zero run
		$res = $this->service->dispatch(SyncService::DIR_PULL, null);
		self::assertSame('ok', $res['status']);
		self::assertSame(0, $res['processed']);
	}

	public function testDispatchRunsPushInlineOverAllMappings(): void {
		$this->mappings->method('list')->willReturn([]);
		$res = $this->service->dispatch(SyncService::DIR_PUSH, null);
		self::assertSame('ok', $res['status']);
	}

	public function testRunInlineThrowsForAnUnknownMappingId(): void {
		$this->mappings->method('getById')->willReturn(null);
		$this->expectException(\OutOfBoundsException::class);
		$this->service->runInline(SyncService::DIR_PUSH, 'no-such-id');
	}

	// ── the folder tree ───────────────────────────────────────────────────────

	/**
	 * A DASHBOARD LANDS IN THE FOLDER THAT MIRRORS ITS GRAFANA FOLDER, not flat in the
	 * mapping's root. This is what makes the two trees the same shape.
	 */
	public function testADashboardIsWrittenIntoTheFolderMirroringItsGrafanaFolder(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getDirectoryListing')->willReturn([]);
		$root->method('nodeExists')->willReturn(false);
		$root->expects(self::never())->method('newFile');

		$team = $this->createMock(Folder::class);
		$team->method('getDirectoryListing')->willReturn([]);
		$team->method('nodeExists')->willReturn(false);
		$team->expects(self::once())
			->method('newFile')
			->with('Board.grafana', self::isString())
			->willReturn($this->file(100, 'Board.grafana'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($root);
		$this->tree = $this->createStub(FolderTreeMirror::class);
		$this->tree->method('sync')->willReturn(['gf-team' => $team]);
		$this->rebuildService();

		// The mapping's own folder holds nothing; the dashboard is in the subfolder.
		$this->grafana->method('listDashboards')->willReturnCallback(
			fn (?string $scope = null): array => $scope === 'gf-team'
				? [['uid' => 'd1', 'title' => 'Board', 'folderUid' => 'gf-team', 'url' => '/d/d1/x', 'tags' => []]]
				: [],
		);
		$this->grafana->method('readDashboardSpec')->with('d1')
			->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board', 'version' => 5], null, null));

		$res = $this->service->pullOne($this->mapping());

		self::assertSame(1, $res['processed']);
		self::assertSame(1, $res['succeeded']);
	}

	/**
	 * A dashboard in a Grafana folder we do not mirror still lands somewhere — the
	 * mapping's root, which is where every dashboard used to go. Losing it would be
	 * worse than putting it in the obvious place.
	 */
	public function testADashboardInAnUnmirroredFolderFallsBackToTheMappingRoot(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getDirectoryListing')->willReturn([]);
		$root->method('nodeExists')->willReturn(false);
		$root->expects(self::once())
			->method('newFile')
			->willReturn($this->file(100, 'Board.grafana'));

		$this->storage->method('isAvailable')->willReturn(true);
		$this->storage->method('ensureFolder')->willReturn($root);

		$this->grafana->method('listDashboards')->willReturn([
			['uid' => 'd1', 'title' => 'Board', 'folderUid' => 'gf-unknown', 'url' => '/d/d1/x', 'tags' => []],
		]);
		$this->grafana->method('readDashboardSpec')->with('d1')
			->willReturn(new DashboardSpec((object)['uid' => 'd1', 'title' => 'Board', 'version' => 5], null, null));

		self::assertSame(1, $this->service->pullOne($this->mapping())['succeeded']);
	}
}
