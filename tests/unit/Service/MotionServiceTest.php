<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MotionService;
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
	private const SRC_PATH = '/alice/files/src/Dash.grafana.json';
	private const DST_PATH = '/alice/files/dst/Dash.grafana.json';
	private const UNMAPPED_PATH = '/alice/files/loose/Dash.grafana.json';

	private MappingService $mappings;
	private DashboardMetadata $metadata;
	private GrafanaClient $grafana;
	private FolderMirror $folderMirror;
	private MotionService $service;

	protected function setUp(): void {
		$this->mappings = $this->createStub(MappingService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->grafana = $this->createMock(GrafanaClient::class);
		// A tree that always answers with the destination subfolder's uid — the folder
		// creation it does on the way is FolderMirror's own business, covered there.
		$this->folderMirror = $this->createStub(FolderMirror::class);
		$this->folderMirror->method('folderUidFor')->willReturn('gf-subfolder');

		$this->service = new MotionService(
			$this->mappings,
			$this->metadata,
			$this->grafana,
			$this->folderMirror,
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
		return new ManagedFile($uid, $mode, $version, 'hash', 'm-src', $folderUid = '', $apiVersion = '');
	}

	private function file(string $path, string $content = '{"title":"Dash","uid":"stale","panels":[]}'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn('Dash.grafana.json');
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

		$this->service->onMove($this->file('/a/sub/Dash.grafana.json'), '/a/Dash.grafana.json');

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

		$this->service->onMove($this->file('/a/sub/New.grafana.json'), '/a/sub/Old.grafana.json');
	}

	/** A link is a pointer; nothing about it moves in Grafana. */
	public function testALinkMovedWithinItsMappingIsNotReparented(): void {
		$same = $this->mapping('m-a', 'gf-a', 'a');
		$this->metadata->method('read')->willReturn($this->managed('dash-1', Mapping::MODE_LINK));
		$this->mappings->method('resolveForPath')->willReturn($same);
		$this->grafana->expects(self::never())->method('upsertDashboard');

		$this->service->onMove($this->file('/a/sub/Dash.grafana.json'), '/a/Dash.grafana.json');
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
				DashboardMetadata::KEY_MAPPING => 'm-dst',
				DashboardMetadata::KEY_FOLDER_UID => 'gf-subfolder',
				DashboardMetadata::KEY_VERSION => '9',
			]);
		$this->metadata->expects(self::never())->method('clear');

		$this->service->onMove($this->file(self::DST_PATH), self::SRC_PATH);
	}

	public function testALinkMoveIntoADifferentMappingOnlyRehomesThePointer(): void {
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
		$groupfolderPath = '/__groupfolders/5/Dash.grafana.json';
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
