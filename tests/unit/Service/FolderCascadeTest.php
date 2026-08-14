<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\RecycleBin;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see FolderCascade} — the subtree walk Nextcloud does not do for us.
 *
 * The ordering assertions are the ones that matter. `DELETE /api/folders` cascades, so
 * with the bin ON a dashboard still inside the folder when it goes is destroyed rather
 * than preserved: parking has to happen FIRST. And with the bin OFF the folder delete has
 * to happen BEFORE the files are stripped, so a Grafana failure leaves every file still
 * carrying the identity that makes it reconcilable.
 */
#[CoversClass(FolderCascade::class)]
final class FolderCascadeTest extends TestCase {
	private GrafanaClient $grafana;
	private DeleteService $deleteService;
	private DashboardMetadata $metadata;
	private FolderMetadata $folders;
	private ?string $binUid = null;

	/** @var list<string> every Grafana-facing or metadata call, in the order it happened */
	private array $calls = [];

	/** @var array<int,ManagedFile|null> file id → what the metadata reads back as */
	private array $managed = [];

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->deleteService = $this->createMock(DeleteService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->folders = $this->createMock(FolderMetadata::class);
	}

	public function testBinOffDeletesTheFolderOnceAndLetsTheCascadeTakeTheDashboards(): void {
		$this->binUid = null;
		$folder = $this->tree(['A.grafana.json' => 11, 'B.grafana.json' => 12]);

		// One request, not one per dashboard — the cascade is the whole point.
		$this->grafana->expects(self::once())->method('deleteFolder')->with('gf-team');
		$this->deleteService->expects(self::never())->method('softDelete');
		$this->metadata->expects(self::exactly(2))->method('clear');

		$this->cascade()->trash($folder, 'gf-team');
	}

	/**
	 * DELETE FIRST, STRIP AFTER. If Grafana cannot confirm, the exception propagates and
	 * the listener aborts the Nextcloud delete — every file must still carry its uid, or
	 * it is orphaned with a live dashboard nobody can find again.
	 */
	public function testBinOffDoesNotStripAnyFileWhenGrafanaRefuses(): void {
		$this->binUid = null;
		$folder = $this->tree(['A.grafana.json' => 11]);
		$this->grafana->method('deleteFolder')->willThrowException(new \RuntimeException('unreachable'));
		$this->metadata->expects(self::never())->method('clear');

		$this->expectException(\RuntimeException::class);
		$this->cascade()->trash($folder, 'gf-team');
	}

	/**
	 * PARK FIRST, THEN DELETE THE FOLDER. The other order would hand every parked
	 * dashboard to the cascade — the bin would preserve nothing.
	 */
	public function testBinOnParksEveryDashboardBeforeDeletingTheFolder(): void {
		$this->binUid = 'gf-bin';
		$folder = $this->tree(['A.grafana.json' => 11, 'B.grafana.json' => 12]);

		$this->deleteService->method('softDelete')->willReturnCallback(
			function (File $file, ManagedFile $m, ?string $bin): void {
				self::assertSame('gf-bin', $bin);
				$this->calls[] = 'park:' . $m->uid;
			},
		);
		$this->grafana->method('deleteFolder')->willReturnCallback(
			function (string $uid): void {
				$this->calls[] = 'deleteFolder:' . $uid;
			},
		);

		$this->cascade()->trash($folder, 'gf-team');

		self::assertSame(['park:d11', 'park:d12', 'deleteFolder:gf-team'], $this->calls);
	}

	/** The folder goes either way — the bin decides what happens to the DASHBOARDS. */
	public function testTheGrafanaFolderIsDeletedWithTheBinOnToo(): void {
		$this->binUid = 'gf-bin';
		$folder = $this->tree(['A.grafana.json' => 11]);
		$this->grafana->expects(self::once())->method('deleteFolder')->with('gf-team');

		$this->cascade()->trash($folder, 'gf-team');
	}

	/** Nextcloud fires nothing for a nested folder's contents, so the walk has to be deep. */
	public function testDashboardsNestedDeeperAreReached(): void {
		$this->binUid = 'gf-bin';
		$folder = $this->tree(
			['A.grafana.json' => 11],
			['Sub' => ['B.grafana.json' => 12, 'Deeper' => ['C.grafana.json' => 13]]],
		);
		$this->deleteService->expects(self::exactly(3))->method('softDelete');

		$this->cascade()->trash($folder, 'gf-team');
	}

	/** A user's spreadsheet in a mirrored folder is not the app's to delete or strip. */
	public function testFilesThatAreNotDashboardsAreLeftAlone(): void {
		$this->binUid = null;
		$folder = $this->tree(['A.grafana.json' => 11, 'Budget.xlsx' => 12]);
		$this->metadata->expects(self::once())->method('clear')->with(11);

		$this->cascade()->trash($folder, 'gf-team');
	}

	/** A link never owned its dashboard, so there is nothing of its to park or delete. */
	public function testLinkFilesAreNotParked(): void {
		$this->binUid = 'gf-bin';
		$folder = $this->tree(['A.grafana.json' => 11]);
		$this->managed[11] = new ManagedFile('d11', Mapping::MODE_LINK, '', '', 'm-demo', '', '');
		$this->deleteService->expects(self::never())->method('softDelete');

		$this->cascade()->trash($folder, 'gf-team');
	}

	/** Already stripped, or never ours — either way there is no dashboard behind it. */
	public function testUnmanagedDashboardFilesAreSkipped(): void {
		$this->binUid = 'gf-bin';
		$folder = $this->tree(['A.grafana.json' => 11]);
		$this->managed[11] = null;
		$this->deleteService->expects(self::never())->method('softDelete');

		$this->cascade()->trash($folder, 'gf-team');
	}

	/**
	 * The Grafana folders are gone with the cascade, and a trashed node keeps its file id
	 * and therefore its metadata — so a restore would otherwise bring back a folder
	 * claiming to mirror a uid that no longer exists.
	 */
	public function testTheMirrorStampsAreClearedFromTheFolderAndItsSubfolders(): void {
		$this->binUid = null;
		$folder = $this->tree([], ['Sub' => []]);
		$cleared = [];
		$this->folders->method('clear')->willReturnCallback(
			function (int $id) use (&$cleared): void {
				$cleared[] = $id;
			},
		);

		$this->cascade()->trash($folder, 'gf-team');

		self::assertSame([20, 21], $cleared, 'the folder itself and every subfolder');
	}

	// ── purge ──────────────────────────────────────────────────────────────────

	public function testPurgeHardDeletesEveryParkedDashboardInTheSubtree(): void {
		$folder = $this->tree(['A.grafana.json' => 11], ['Sub' => ['B.grafana.json' => 12]]);
		$this->deleteService->expects(self::exactly(2))->method('hardDelete');

		$this->cascade()->purge($folder);
	}

	/**
	 * One unreachable dashboard must not stop the rest. A legacy purge hook cannot abort
	 * anyway, so stopping early would only leave MORE behind.
	 */
	public function testPurgeKeepsGoingAfterOneDashboardFails(): void {
		$folder = $this->tree(['A.grafana.json' => 11, 'B.grafana.json' => 12]);
		$seen = [];
		$this->deleteService->method('hardDelete')->willReturnCallback(
			function (ManagedFile $m) use (&$seen): void {
				$seen[] = $m->uid;
				if ($m->uid === 'd11') {
					throw new \RuntimeException('unreachable');
				}
			},
		);

		$this->cascade()->purge($folder);

		self::assertSame(['d11', 'd12'], $seen);
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function cascade(): FolderCascade {
		$recycleBin = $this->createStub(RecycleBin::class);
		$recycleBin->method('activeFolderUid')->willReturnCallback(fn (): ?string => $this->binUid);

		$this->metadata->method('read')->willReturnCallback(
			fn (int $id): ?ManagedFile => array_key_exists($id, $this->managed)
				? $this->managed[$id]
				: new ManagedFile('d' . $id, Mapping::MODE_SYNC, '', '', 'm-demo', '', ''),
		);

		return new FolderCascade(
			$this->deleteService,
			$this->metadata,
			$this->folders,
			$recycleBin,
			$this->grafana,
			new SyncGuard(),
			new NullLogger(),
		);
	}

	/**
	 * Build a folder tree. $files maps a filename to its id; $subfolders maps a folder
	 * name to its own contents (files by id, or a nested array for another level).
	 * Folder ids are handed out from 20 upward, in walk order.
	 *
	 * @param array<string,int> $files
	 * @param array<string,array<string,mixed>> $subfolders
	 */
	private function tree(array $files, array $subfolders = []): Folder {
		$nextFolderId = 20;
		return $this->folderNode($files, $subfolders, $nextFolderId);
	}

	/**
	 * @param array<string,int> $files
	 * @param array<string,array<string,mixed>> $subfolders
	 */
	private function folderNode(array $files, array $subfolders, int &$nextFolderId): Folder {
		$id = $nextFolderId++;
		/** @var list<Node> $children */
		$children = [];
		foreach ($files as $name => $fileId) {
			$file = $this->createStub(File::class);
			$file->method('getName')->willReturn($name);
			$file->method('getId')->willReturn($fileId);
			$children[] = $file;
		}
		foreach ($subfolders as $name => $contents) {
			$childFiles = [];
			$childFolders = [];
			foreach ($contents as $key => $value) {
				if (is_array($value)) {
					$childFolders[$key] = $value;
				} else {
					$childFiles[$key] = $value;
				}
			}
			$sub = $this->folderNode($childFiles, $childFolders, $nextFolderId);
			$sub->method('getName')->willReturn($name);
			$children[] = $sub;
		}

		$folder = $this->createStub(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn('/alice/files/Demo/Team');
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}
}
