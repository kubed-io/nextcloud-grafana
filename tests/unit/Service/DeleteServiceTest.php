<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\OwnershipTags;
use OCA\GrafanaSync\Service\RecycleBin;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see DeleteService} — the delete/restore rule table. These pin the two
 * invariants that matter most for a system with no undo: a **failed Grafana delete never
 * strips** the file (it stays reconcilable), and the **bin ON path never deletes** on a
 * trash-move (it parks, id kept). The bin ON/OFF split is exercised end to end.
 */
#[CoversClass(DeleteService::class)]
final class DeleteServiceTest extends TestCase {
	private const BIN_UID = 'bin-folder-uid';

	private GrafanaClient $grafana;
	private DashboardMetadata $metadata;
	private OwnershipTags $tags;
	private CreateService $create;
	private RecycleBin $recycleBin;
	private DeleteService $service;

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->tags = $this->createMock(OwnershipTags::class);
		$this->create = $this->createMock(CreateService::class);
		$this->recycleBin = $this->createMock(RecycleBin::class);
		$this->service = new DeleteService(
			$this->grafana,
			$this->metadata,
			$this->tags,
			$this->create,
			$this->recycleBin,
			new SyncGuard(),
			new NullLogger(),
		);
	}

	private function managed(string $uid, string $mode = Mapping::MODE_SYNC, string $mappingId = 'm-1'): ManagedFile {
		return new ManagedFile($uid, $mode, '3', 'hash', $mappingId, '', '');
	}

	private function mapping(string $folderUid = 'gf-alpha', string $mode = Mapping::MODE_SYNC): Mapping {
		return Mapping::fromArray(['id' => 'm-1', 'grafana_folder_uid' => $folderUid, 'nc_folder' => 'alpha', 'mode' => $mode]);
	}

	private function file(int $id = 5, string $content = '{"title":"Board","panels":[]}'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn('Board.grafana.json');
		$node->method('getPath')->willReturn('/alice/files/alpha/Board.grafana.json');
		$node->method('getContent')->willReturn($content);
		return $node;
	}

	// ── softDelete (trash-move) ──────────────────────────────────────────────────────

	public function testSoftDeleteBinOffDeletesThenStrips(): void {
		$this->grafana->expects(self::once())->method('deleteDashboard')->with('dash-1');
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::once())->method('clear')->with(5);
		$this->tags->expects(self::once())->method('clear')->with(5);

		$this->service->softDelete($this->file(5), $this->managed('dash-1'), null);
	}

	public function testSoftDeleteBinOnParksAndKeepsIdentity(): void {
		// Bin ON: the dashboard is MOVED into the bin folder (uid kept), never deleted, and the
		// file's metadata is left intact so restore can move it back.
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->grafana->expects(self::once())
			->method('upsertDashboard')
			->with(self::callback(fn (array $b): bool => $b['dashboard']->uid === 'dash-1' && ($b['folderUid'] ?? null) === self::BIN_UID))
			->willReturn(['version' => 4]);
		$this->metadata->expects(self::never())->method('clear');
		$this->tags->expects(self::never())->method('clear');

		$this->service->softDelete($this->file(5), $this->managed('dash-1'), self::BIN_UID);
	}

	public function testSoftDeleteBinOffFailedDeleteNeverStrips(): void {
		// The single most important delete invariant: if Grafana can't confirm the delete, the
		// exception propagates and we do NOT strip — the file keeps its identity, reconcilable.
		$this->grafana->method('deleteDashboard')->willThrowException(new \RuntimeException('grafana down'));
		$this->metadata->expects(self::never())->method('clear');
		$this->tags->expects(self::never())->method('clear');

		$this->expectException(\RuntimeException::class);
		$this->service->softDelete($this->file(5), $this->managed('dash-1'), null);
	}

	public function testSoftDeleteLinkNeverTouchesGrafana(): void {
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('clear');

		$this->service->softDelete($this->file(5), $this->managed('dash-1', Mapping::MODE_LINK), null);
	}

	// ── hardDelete (purge / trash-bypass) ────────────────────────────────────────────

	public function testHardDeleteRemovesAManagedSyncDashboard(): void {
		$this->recycleBin->method('isEnabled')->willReturn(false);
		$this->grafana->expects(self::once())->method('deleteDashboard')->with('dash-1');
		$this->service->hardDelete($this->managed('dash-1'));
	}

	public function testHardDeleteIsANoopForAnAlreadyStrippedFile(): void {
		// Bin OFF stripped the id at trash-time, so the purge finds an unmanaged file → no-op.
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->service->hardDelete($this->managed('', DashboardMetadata::MODE_UNMAPPED));
	}

	public function testHardDeleteIsANoopForALink(): void {
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->service->hardDelete($this->managed('dash-1', Mapping::MODE_LINK));
	}

	// ── restore (restore-from-trash) ─────────────────────────────────────────────────

	public function testRestoreBinOnMovesTheDashboardBackKeepingId(): void {
		$this->grafana->expects(self::once())
			->method('upsertDashboard')
			->with(self::callback(fn (array $b): bool => $b['dashboard']->uid === 'dash-1' && ($b['folderUid'] ?? null) === 'gf-alpha'))
			->willReturn(['version' => 8]);
		$this->grafana->expects(self::never())->method('deleteDashboard');
		$this->metadata->expects(self::once())->method('write')->with(5, [DashboardMetadata::KEY_VERSION => '8']);
		$this->create->expects(self::never())->method('createForFile');

		$this->service->restore($this->file(5), $this->managed('dash-1'), $this->mapping());
	}

	public function testRestoreBinOffRecreatesFromTheFile(): void {
		// The id was stripped at trash-time, so the restored file is unmanaged → create-on-land
		// re-creates it (a fresh dashboard, new uid) because it landed back in a sync mapping.
		$mapping = $this->mapping();
		$unmanaged = new ManagedFile('', '', '', '', '', '', '');
		$this->create->expects(self::once())->method('createForFile')->with(self::isInstanceOf(File::class), $mapping);
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->grafana->expects(self::never())->method('deleteDashboard');

		$this->service->restore($this->file(5), $unmanaged, $mapping);
	}

	public function testRestoreBinOffDoesNotRecreateOutsideASyncMapping(): void {
		$unmanaged = new ManagedFile('', '', '', '', '', '', '');
		$this->create->expects(self::never())->method('createForFile');

		// restored into a link mapping → nothing to author
		$this->service->restore($this->file(5), $unmanaged, $this->mapping('gf-alpha', Mapping::MODE_LINK));
		// restored outside any mapping → nothing
		$this->service->restore($this->file(5), $unmanaged, null);
	}

	public function testRestoreLinkIsANoop(): void {
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->create->expects(self::never())->method('createForFile');

		$this->service->restore($this->file(5), $this->managed('dash-1', Mapping::MODE_LINK), $this->mapping());
	}

	/**
	 * THE RESCUE CASE. The "recycle bin" is an ordinary Grafana folder, visible in
	 * Grafana's own UI, so anyone can drag a parked dashboard back out of it. If the
	 * purge still deleted by uid, emptying a Nextcloud trash weeks later would destroy
	 * a live dashboard somebody had deliberately saved — and Grafana has no undo.
	 */
	public function testHardDeleteLeavesADashboardThatWasRescuedOutOfTheBin(): void {
		$this->recycleBin->method('isEnabled')->willReturn(true);
		$this->recycleBin->method('activeFolderUid')->willReturn(self::BIN_UID);
		// Someone moved it back to its real folder.
		$this->grafana->method('readDashboard')->willReturn(['meta' => ['folderUid' => 'gf-alpha']]);
		$this->grafana->expects(self::never())->method('deleteDashboard');

		$this->service->hardDelete($this->managed('d1'));
	}

	public function testHardDeleteStillDeletesADashboardThatIsActuallyParked(): void {
		$this->recycleBin->method('isEnabled')->willReturn(true);
		$this->recycleBin->method('activeFolderUid')->willReturn(self::BIN_UID);
		$this->grafana->method('readDashboard')->willReturn(['meta' => ['folderUid' => self::BIN_UID]]);
		$this->grafana->expects(self::once())->method('deleteDashboard')->with('d1');

		$this->service->hardDelete($this->managed('d1'));
	}

	/**
	 * Cannot prove it is still parked → do not delete. Leaving a dashboard alive that
	 * could have gone is a recoverable leak; deleting one that should have lived is not.
	 */
	public function testHardDeleteSkipsTheDeleteWhenGrafanaCannotBeAsked(): void {
		$this->recycleBin->method('isEnabled')->willReturn(true);
		$this->recycleBin->method('activeFolderUid')->willReturn(self::BIN_UID);
		$this->grafana->method('readDashboard')->willThrowException(new \RuntimeException('Grafana is down'));
		$this->grafana->expects(self::never())->method('deleteDashboard');

		$this->service->hardDelete($this->managed('d1'));
	}

	/** Bin OFF has no bin to check: a still-managed file here is a trash-bypass, and this is its only delete. */
	public function testHardDeleteWithTheBinOffDeletesWithoutCheckingAnyFolder(): void {
		$this->recycleBin->method('isEnabled')->willReturn(false);
		$this->grafana->expects(self::never())->method('readDashboard');
		$this->grafana->expects(self::once())->method('deleteDashboard')->with('d1');

		$this->service->hardDelete($this->managed('d1'));
	}
}
