<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MotionService;
use OCA\GrafanaSync\Service\RecycleBin;
use OCA\GrafanaSync\Service\ReplacedByMoveStore;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see MotionService} — the move engine. A move is classified by WHERE the
 * file lands: within the same mapping (no-op), into a different mapped folder (Grafana
 * folder move, uid kept), or out of every mapping (delete in Grafana + strip the file,
 * recycle-bin OFF being the default). The critical invariant these pin: identity is NEVER
 * stripped unless the Grafana delete actually confirmed.
 */
#[CoversClass(MotionService::class)]
final class MotionServiceTest extends TestCase {
	private const SRC_PATH = '/alice/files/src/Dash.grafana';
	private const DST_PATH = '/alice/files/dst/Dash.grafana';
	private const UNMAPPED_PATH = '/alice/files/loose/Dash.grafana';

	private MappingService $mappings;
	private DashboardMetadata $metadata;
	private GrafanaClient $grafana;
	private FolderMirror $folderMirror;
	private RecycleBin $recycleBin;
	private ReplacedByMoveStore $replaced;
	private CreateService $createService;
	private MotionService $service;

	protected function setUp(): void {
		$this->mappings = $this->createStub(MappingService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->grafana = $this->createMock(GrafanaClient::class);
		// A tree that always answers with the destination subfolder's uid — the folder
		// creation it does on the way is FolderMirror's own business, covered there.
		$this->folderMirror = $this->createStub(FolderMirror::class);
		$this->folderMirror->method('folderUidFor')->willReturn('gf-subfolder');

		// BIN OFF by default, which is the app's default and the state every existing
		// test in this file was written against. The parking path gets its own tests
		// below, with the stub answering a bin uid.
		$this->recycleBin = $this->createStub(RecycleBin::class);
		$this->recycleBin->method('activeFolderUid')->willReturn(null);

		// A REAL STORE, NOT A STUB. It is a request-scoped array with no I/O, and the
		// adoption tests below are about what happens when a mark IS present — which a
		// stub would have to be taught, one willReturn at a time, to say the same thing.
		$this->replaced = new ReplacedByMoveStore();
		// Reached only by the two duplicate paths — an adoption from create-on-land and a
		// move-in beside a file already mirroring the same dashboard. Every other test in
		// this file asserts it is NEVER called, which is the claim that an ordinary move
		// re-parents rather than mints.
		$this->createService = $this->createMock(CreateService::class);

		$this->service = new MotionService(
			$this->mappings,
			$this->metadata,
			$this->grafana,
			$this->folderMirror,
			$this->recycleBin,
			$this->replaced,
			$this->createService,
			new SyncGuard(),
			new NullLogger(),
		);
	}

	private function mapping(string $id, string $folderUid, string $ncFolder, string $mode = Mapping::MODE_SYNC): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'grafana_folder_uid' => $folderUid,
			'nc_folder' => $ncFolder,
			'mode' => $mode,
		]);
	}

	private function managed(string $uid, string $mode = Mapping::MODE_SYNC, string $version = '3'): ManagedFile {
		return new ManagedFile($uid, $mode, $version, 'hash', 'm-src', '');
	}

	private function file(string $path, string $content = '{"title":"Dash","uid":"stale","panels":[]}'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn('Dash.grafana');
		$node->method('getPath')->willReturn($path);
		$node->method('getContent')->willReturn($content);
		return $node;
	}

	public function testAnUnmanagedFileIsLeftToCreateOnLand(): void {
		$this->metadata->method('read')->willReturn($this->managed('')); // no uid → unmanaged
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->metadata->expects(self::never())->method('write');
		$this->metadata->expects(self::never())->method('clear');

		$this->service->onMove($this->file(self::DST_PATH), self::SRC_PATH);
	}

	/**
	 * INVERTED ON PURPOSE — it used to assert that this did NOTHING.
	 *
	 * Staying inside one mapping does not mean staying in one FOLDER, and returning
	 * early made a drag into a subfolder a purely local act: the subfolder was never
	 * created in Grafana and the dashboard sat where the pull had left it. That is the
	 * opposite of the rule `folders/create.feature` states — a folder is in Grafana
	 * when a dashboard is in it, however the dashboard got there.
	 */
	public function testAMoveIntoASubfolderOfTheSameMappingReparentsIt(): void {
		$same = $this->mapping('m-a', 'gf-a', 'a');
		$this->metadata->method('read')->willReturn($this->managed('dash-1'));
		$this->mappings->method('resolveForPath')->willReturn($same); // both from + to resolve here
		$this->grafana->expects(self::never())->method('deleteDashboard');

		$captured = null;
		$this->grafana->expects(self::once())->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$captured): array {
				$captured = $body;
				return ['uid' => 'dash-1', 'version' => 2];
			});

		$this->service->onMove($this->file('/a/sub/Dash.grafana'), '/a/Dash.grafana');

		self::assertSame('gf-subfolder', $captured['folderUid'] ?? null);
		self::assertSame('dash-1', $captured['dashboard']->uid ?? null, 'the identity survives the move');
	}

	/**
	 * A RENAME IS NOT A MOVE, though Nextcloud fires one event for both. An upsert
	 * bumps Grafana's version and `updated`, and this app shows `updated` as the
	 * file's Modified time — so pushing here would move a clock that records when the
	 * DASHBOARD changed, for a gesture that only renamed a file. NameSyncListener
	 * already owns the rename.
	 */
	public function testARenameInsideOneFolderIsNotPushed(): void {
		$same = $this->mapping('m-a', 'gf-a', 'a');
		$this->metadata->method('read')->willReturn($this->managed('dash-1'));
		$this->mappings->method('resolveForPath')->willReturn($same);
		$this->grafana->expects(self::never())->method('upsertDashboard');

		$this->service->onMove($this->file('/a/sub/New.grafana'), '/a/sub/Old.grafana');
	}

	/** A link is a pointer; nothing about it moves in Grafana. */
	public function testALinkMovedWithinItsMappingIsNotReparented(): void {
		$same = $this->mapping('m-a', 'gf-a', 'a');
		$this->metadata->method('read')->willReturn($this->managed('dash-1', Mapping::MODE_LINK));
		$this->mappings->method('resolveForPath')->willReturn($same);
		$this->grafana->expects(self::never())->method('upsertDashboard');

		$this->service->onMove($this->file('/a/sub/Dash.grafana'), '/a/Dash.grafana');
	}

	public function testASyncMoveIntoADifferentMappingReparentsKeepingTheUid(): void {
		$from = $this->mapping('m-src', 'gf-src', 'src');
		$to = $this->mapping('m-dst', 'gf-dst', 'dst');
		$this->metadata->method('read')->willReturn($this->managed('dash-keep', Mapping::MODE_SYNC));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[self::DST_PATH, $to],
		]);

		// The dashboard is re-parented into the destination folder with the SAME uid, and
		// its file identity (the metadata uid), never the file's own typed uid, wins.
		$this->grafana->expects(self::once())
			->method('upsertDashboard')
			->with(self::callback(function (array $body): bool {
				return $body['dashboard']->uid === 'dash-keep'
					// THE SUBFOLDER THE FILE LANDED IN, not the destination mapping's
					// root — FolderMirror resolves the path, so a file dragged into
					// another mapping's subfolder arrives there rather than at its top.
					&& ($body['folderUid'] ?? null) === 'gf-subfolder';
			}))
			->willReturn(['version' => 9]);
		$this->grafana->expects(self::never())->method('deleteDashboard');

		// Re-stamps the mapping, the folder it actually landed in, and the fresh
		// version; never clears (uid kept). The FOLDER is re-stamped because a move is
		// exactly what makes the banked value stale.
		$this->metadata->expects(self::once())
			->method('write')
			->with(42, [
				// THE UID IS RE-STATED FOR THE SAME REASON THE MODE IS — an overwrite
				// arrives here having inherited the destination's uid, so the stamp must
				// say which dashboard this file now points at rather than assuming it is
				// the one already written. For an ordinary move-in it is the value
				// already there and the write is a no-op.
				DashboardMetadata::KEY_UID => 'dash-keep',
				DashboardMetadata::KEY_MAPPING => 'm-dst',
				// THE MODE IS RE-STATED ON EVERY ARRIVAL, not only when it changed. A file
				// parked with the recycle bin on is stamped `unmapped`, so a mapping has to
				// say what the file IS on the way in — and writing it unconditionally is
				// cheaper to reason about than writing it only when it differs.
				DashboardMetadata::KEY_MODE => Mapping::MODE_SYNC,
				DashboardMetadata::KEY_FOLDER_UID => 'gf-subfolder',
				DashboardMetadata::KEY_VERSION => '9',
			]);
		$this->metadata->expects(self::never())->method('clear');

		$this->service->onMove($this->file(self::DST_PATH), self::SRC_PATH);
	}

	/**
	 * DEFENSIVE ONLY, since {@see \OCA\GrafanaSync\Listener\MoveGuardListener} now refuses
	 * this move before it happens — a pointer's membership is Grafana's to decide, so a
	 * re-stamp here would disagree with Grafana until the next pull undid it. Kept, and
	 * kept asserting that Grafana is never called, because a service reached anyway should
	 * still do the least surprising thing. Same shape as the link move-out below.
	 */
	public function testALinkMoveIntoADifferentMappingIsNotReachedButNeverCallsGrafana(): void {
		$from = $this->mapping('m-src', 'gf-src', 'src', Mapping::MODE_LINK);
		$to = $this->mapping('m-dst', 'gf-dst', 'dst', Mapping::MODE_LINK);
		$this->metadata->method('read')->willReturn($this->managed('dash-link', Mapping::MODE_LINK));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[self::DST_PATH, $to],
		]);

		// A link owns no dashboard content — Grafana is untouched, only the mapping re-stamps.
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->metadata->expects(self::once())
			->method('write')
			->with(42, [DashboardMetadata::KEY_MAPPING => 'm-dst']);
		$this->metadata->expects(self::never())->method('clear');

		$this->service->onMove($this->file(self::DST_PATH), self::SRC_PATH);
	}

	public function testASyncMoveOutOfEveryMappingDeletesThenStrips(): void {
		$from = $this->mapping('m-src', 'gf-src', 'src');
		$this->metadata->method('read')->willReturn($this->managed('dash-gone', Mapping::MODE_SYNC));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[self::UNMAPPED_PATH, null], // lands outside every mapping
		]);

		// bin OFF (default): the file's content is safe in Nextcloud, so the dashboard is
		// deleted in Grafana and the file's identity stripped (it becomes a plain document).
		$this->grafana->expects(self::once())->method('deleteDashboard')->with('dash-gone');
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::once())->method('clear')->with(42);

		$this->service->onMove($this->file(self::UNMAPPED_PATH), self::SRC_PATH);
	}

	/**
	 * BIN ON, LEAVING A MAPPING: nothing is destroyed, so nothing is forgotten.
	 *
	 * Grafana has no archive — an ordinary folder move into the nominated bin is the only
	 * reversible removal there is — so the dashboard is re-parented rather than deleted
	 * and the file KEEPS its uid, which is what lets it restore to the same dashboard.
	 * It stops belonging to a mapping, which is all `unmapped` means.
	 */
	public function testABinOnMoveOutParksTheDashboardAndKeepsTheUid(): void {
		$from = $this->mapping('m-src', 'gf-src', 'src');
		$this->metadata->method('read')->willReturn($this->managed('dash-parked'));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[self::UNMAPPED_PATH, null],
		]);
		$this->recycleBin = $this->createStub(RecycleBin::class);
		$this->recycleBin->method('activeFolderUid')->willReturn('gf-bin');
		$this->rebuildWithBin();

		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->grafana->expects(self::once())->method('upsertDashboard')
			->with(self::callback(static function (array $body): bool {
				return ($body['folderUid'] ?? null) === 'gf-bin'
					&& ($body['dashboard']->uid ?? null) === 'dash-parked';
			}))
			->willReturn(['version' => 4]);
		$this->metadata->expects(self::never())->method('clear');
		$this->metadata->expects(self::once())->method('write')
			->with(42, [
				DashboardMetadata::KEY_MAPPING => '',
				DashboardMetadata::KEY_MODE => DashboardMetadata::MODE_UNMAPPED,
				DashboardMetadata::KEY_FOLDER_UID => 'gf-bin',
			]);

		$this->service->onMove($this->file(self::UNMAPPED_PATH), self::SRC_PATH);
	}

	/**
	 * AND THE MODE COMES BACK. A file parked with the bin on is stamped `unmapped`;
	 * re-adopting it without re-stating the mode left a live mirror in a sync mapping
	 * still claiming to be unmapped, which every later gesture reads to decide what it
	 * may do.
	 */
	public function testEnteringAMappingRestoresTheMode(): void {
		$to = $this->mapping('m-dst', 'gf-dst', 'dst');
		$this->metadata->method('read')->willReturn($this->managed('dash-back', DashboardMetadata::MODE_UNMAPPED));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::UNMAPPED_PATH, null],
			[self::DST_PATH, $to],
		]);
		$this->grafana->method('upsertDashboard')->willReturn(['version' => 7]);

		$captured = null;
		$this->metadata->expects(self::once())->method('write')
			->willReturnCallback(function (int $id, array $values) use (&$captured): void {
				$captured = $values;
			});

		$this->service->onMove($this->file(self::DST_PATH), self::UNMAPPED_PATH);

		self::assertSame(Mapping::MODE_SYNC, $captured[DashboardMetadata::KEY_MODE] ?? null);
		self::assertSame('m-dst', $captured[DashboardMetadata::KEY_MAPPING] ?? null);
	}

	// ── an overwrite adopts the identity it landed on ─────────────────────────────
	//
	// The unit half of `move.feature`'s two duplicate scenarios. The Sabre plugin that
	// SETS the mark is only reachable from a live WebDAV MOVE, so what is pinned here is
	// the other end: given a mark, the move-in binds to the uid that was already in the
	// mapping and not to the one the arriving file carried.

	public function testAnOverwriteBindsToTheUidItReplacedRatherThanTheOneItArrivedWith(): void {
		$to = $this->mapping('m-dst', 'gf-dst', 'dst');
		$this->metadata->method('read')->willReturn($this->managed('dash-arrived'));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::UNMAPPED_PATH, null],
			[self::DST_PATH, $to],
		]);
		// File id 42 is what `file()` answers; the plugin marked it as adopting dash-kept.
		$this->replaced->mark(7, 42, 'dash-kept');

		$pushed = null;
		$this->grafana->expects(self::once())
			->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$pushed): array {
				$pushed = $body;
				return ['version' => 4];
			});
		$captured = [];
		$this->metadata->method('write')
			->willReturnCallback(function (int $id, array $values) use (&$captured): void {
				$captured = $values;
			});

		$this->service->onMove($this->file(self::DST_PATH), self::UNMAPPED_PATH);

		// BOTH ENDS, because either alone can be right while the pair is wrong: pushing to
		// the kept uid but stamping the arrival's would leave the file pointing at a
		// dashboard it does not describe, and the next pull would write a second one.
		self::assertSame('dash-kept', $pushed['dashboard']->uid ?? null, 'the push went to the wrong dashboard');
		self::assertSame('dash-kept', $captured[DashboardMetadata::KEY_UID] ?? null, 'the file was stamped with the wrong uid');
	}

	public function testAnOrdinaryMoveInKeepsItsOwnUid(): void {
		// THE CONTROL, and the reason the adoption is keyed by FILE ID rather than being a
		// simple "is anything marked" flag: a mark belonging to some other file in the same
		// request must not reach this one.
		$to = $this->mapping('m-dst', 'gf-dst', 'dst');
		$this->metadata->method('read')->willReturn($this->managed('dash-arrived'));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::UNMAPPED_PATH, null],
			[self::DST_PATH, $to],
		]);
		$this->replaced->mark(7, 99, 'dash-somebody-elses');

		$pushed = null;
		$this->grafana->expects(self::once())
			->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$pushed): array {
				$pushed = $body;
				return ['version' => 4];
			});

		$this->service->onMove($this->file(self::DST_PATH), self::UNMAPPED_PATH);

		self::assertSame('dash-arrived', $pushed['dashboard']->uid ?? null);
	}

	// ── an overwrite INSIDE one mapping ───────────────────────────────────────────
	//
	// The case a human found by dragging a file between two subfolders of `observe`.
	// Every scenario in move.feature arrives from an UNMAPPED folder, so the adoption
	// used to sit inside onEnterMapping — which a same-mapping move never reaches. The
	// arrival kept its own uid, the destination's dashboard was left behind, and one
	// Grafana folder held two dashboards with one file between them.

	public function testAnOverwriteBetweenSubfoldersOfOneMappingStillAdopts(): void {
		$same = $this->mapping('m-obs', 'gf-obs', 'observe');
		$this->metadata->method('read')->willReturn($this->managed('dash-arrived'));
		$this->mappings->method('resolveForPath')->willReturnMap([
			['/alice/files/observe/blurn/Dash.grafana', $same],
			[self::DST_PATH, $same],
		]);
		$this->replaced->mark(7, 42, 'dash-kept');

		$pushed = null;
		$this->grafana->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$pushed): array {
				$pushed ??= $body;
				return ['version' => 3];
			});
		// BIN OFF (the default here), so the superseded dashboard is deleted outright —
		// its file now points somewhere else, and a pull would otherwise mirror it back.
		$this->grafana->expects(self::once())->method('deleteDashboard')->with('dash-arrived');

		$this->service->onMove($this->file(self::DST_PATH), '/alice/files/observe/blurn/Dash.grafana');

		self::assertSame('dash-kept', $pushed['dashboard']->uid ?? null, 'the push went to the wrong dashboard');
	}

	public function testAnOverwriteCarriesTheArrivalsTagsOntoTheAdoptedDashboard(): void {
		// TAGS TRAVEL WITH THE BODY, which is the whole of what "keep the new version"
		// chose. The destination's tags go with the bytes they belonged to; asserting it
		// here because tags reach three surfaces and a silent divergence between them is
		// the hardest kind of bug to see.
		$same = $this->mapping('m-obs', 'gf-obs', 'observe');
		$this->metadata->method('read')->willReturn($this->managed('dash-arrived'));
		$this->mappings->method('resolveForPath')->willReturnMap([
			['/alice/files/observe/blurn/Dash.grafana', $same],
			[self::DST_PATH, $same],
		]);
		$this->replaced->mark(7, 42, 'dash-kept');

		$pushed = null;
		$this->grafana->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$pushed): array {
				$pushed ??= $body;
				return ['version' => 3];
			});

		$arrival = '{"title":"Dash","uid":"stale","tags":["mustard","cookie"],"panels":[]}';
		$this->service->onMove($this->file(self::DST_PATH, $arrival), '/alice/files/observe/blurn/Dash.grafana');

		self::assertSame('dash-kept', $pushed['dashboard']->uid ?? null);
		self::assertSame(['mustard', 'cookie'], $pushed['dashboard']->tags ?? null, 'the arrival’s tags did not travel with its body');
	}

	public function testAnOverwriteFromOUTSIDEEveryMappingLeavesTheOldDashboardAlone(): void {
		// THE LINE BETWEEN THE TWO. A file arriving from an unmapped folder leaves nothing
		// behind that this app mirrors, so its old dashboard is not ours to remove — the
		// rule move.feature already states. Only a file that came FROM a mapping leaves a
		// file-less dashboard sitting where a pull would find it.
		$to = $this->mapping('m-dst', 'gf-dst', 'dst');
		$this->metadata->method('read')->willReturn($this->managed('dash-arrived'));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::UNMAPPED_PATH, null],
			[self::DST_PATH, $to],
		]);
		$this->replaced->mark(7, 42, 'dash-kept');
		$this->grafana->method('upsertDashboard')->willReturn(['version' => 3]);
		$this->grafana->expects(self::never())->method('deleteDashboard');

		$this->service->onMove($this->file(self::DST_PATH), self::UNMAPPED_PATH);
	}

	/** Rebuild with whatever the test just stubbed the bin to answer. */
	private function rebuildWithBin(): void {
		$this->service = new MotionService(
			$this->mappings,
			$this->metadata,
			$this->grafana,
			$this->folderMirror,
			$this->recycleBin,
			$this->replaced,
			$this->createService,
			new SyncGuard(),
			new NullLogger(),
		);
	}

	public function testAFailedGrafanaDeleteLeavesTheFileIdentityIntact(): void {
		$from = $this->mapping('m-src', 'gf-src', 'src');
		$this->metadata->method('read')->willReturn($this->managed('dash-stuck', Mapping::MODE_SYNC));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[self::UNMAPPED_PATH, null],
		]);

		// Delete is attempted first; if Grafana can't confirm it, we must NOT strip — the
		// file keeps its uid and stays reconcilable (never orphaned, never data-lost).
		$this->grafana->method('deleteDashboard')->willThrowException(new \RuntimeException('grafana unreachable'));
		$this->metadata->expects(self::never())->method('clear');

		$this->expectException(\RuntimeException::class);
		$this->service->onMove($this->file(self::UNMAPPED_PATH), self::SRC_PATH);
	}

	public function testAMoveToAnUnclassifiableDestinationPathNeverDeletes(): void {
		// A destination whose path shape resolveForPath can't place (no /files/ segment, e.g.
		// a special mount like a Team Folder's /__groupfolders/<id>/ path) resolves to null —
		// but that is NOT proof the file left every mapping, so we must never read it as a
		// move-out and delete the dashboard. Defence in depth: never delete on that doubt.
		$groupfolderPath = '/__groupfolders/5/Dash.grafana';
		$from = $this->mapping('m-src', 'gf-src', 'src');
		$this->metadata->method('read')->willReturn($this->managed('dash-safe', Mapping::MODE_SYNC));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[$groupfolderPath, null], // team-folder internal path resolves to nothing
		]);

		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('clear');

		$this->service->onMove($this->file($groupfolderPath), self::SRC_PATH);
	}

	public function testALinkMoveOutOfEveryMappingStripsWithoutDeleting(): void {
		// MoveGuardListener refuses a link move-out before it happens, so this path is only
		// reached defensively. A link never owned the dashboard, so nothing is deleted.
		$from = $this->mapping('m-src', 'gf-src', 'src', Mapping::MODE_LINK);
		$this->metadata->method('read')->willReturn($this->managed('dash-ptr', Mapping::MODE_LINK));
		$this->mappings->method('resolveForPath')->willReturnMap([
			[self::SRC_PATH, $from],
			[self::UNMAPPED_PATH, null],
		]);

		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->metadata->expects(self::once())->method('clear')->with(42);

		$this->service->onMove($this->file(self::UNMAPPED_PATH), self::SRC_PATH);
	}
}
