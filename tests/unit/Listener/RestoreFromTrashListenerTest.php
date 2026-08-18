<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GrafanaSync\Listener\RestoreFromTrashListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see RestoreFromTrashListener} — and specifically the folder branch, which is what
 * stops a folder trash from being a ONE-WAY DOOR.
 *
 * Nextcloud fires one restore event for a restored folder and nothing for the files
 * inside it, exactly as it fires one delete event on the way in. The delete cascade
 * reaches every dashboard under a folder; without the same walk here, nothing would ever
 * bring any of them back.
 */
#[CoversClass(RestoreFromTrashListener::class)]
final class RestoreFromTrashListenerTest extends TestCase {
	private DeleteService $deleteService;
	private FolderCascade $cascade;
	private SyncGuard $guard;

	/** @var array<int,ManagedFile|null> file id → what the metadata reads back as */
	private array $managed = [];

	protected function setUp(): void {
		$this->deleteService = $this->createMock(DeleteService::class);
		$this->cascade = $this->createMock(FolderCascade::class);
		$this->guard = new SyncGuard();
	}

	/** THE ONE-WAY DOOR. Every dashboard under the folder has to come back. */
	public function testRestoringAFolderRestoresEveryDashboardUnderIt(): void {
		$folder = $this->createStub(Folder::class);
		$this->cascade->method('dashboardFilesIn')->willReturn([
			$this->file(11), $this->file(12), $this->file(13),
		]);
		$this->deleteService->expects(self::exactly(3))->method('restore');

		$this->listener()->handle(new NodeRestoredEvent($folder));
	}

	/**
	 * A bin-OFF trash stripped every file's identity, so the files coming back are exactly
	 * the ones the cascade's own filters drop — they still have to be re-created.
	 */
	public function testStrippedFilesInARestoredFolderAreStillReCreated(): void {
		$folder = $this->createStub(Folder::class);
		$this->managed = [11 => null];
		$this->cascade->method('dashboardFilesIn')->willReturn([$this->file(11)]);
		$this->deleteService->expects(self::once())->method('restore')->with(
			self::anything(),
			self::callback(fn (ManagedFile $m): bool => !$m->isManaged()),
			self::anything(),
		);

		$this->listener()->handle(new NodeRestoredEvent($folder));
	}

	/** One unreachable dashboard must not stop the rest of the folder coming back. */
	public function testOneFailureDoesNotStopTheRestOfTheFolder(): void {
		$folder = $this->createStub(Folder::class);
		$this->cascade->method('dashboardFilesIn')->willReturn([$this->file(11), $this->file(12)]);
		$seen = [];
		$this->deleteService->method('restore')->willReturnCallback(
			function (File $f) use (&$seen): void {
				$seen[] = $f->getId();
				if ($f->getId() === 11) {
					throw new \RuntimeException('unreachable');
				}
			},
		);

		$this->listener()->handle(new NodeRestoredEvent($folder));

		self::assertSame([11, 12], $seen);
	}

	public function testRestoringASingleFileStillTakesThePerFilePath(): void {
		$this->cascade->expects(self::never())->method('dashboardFilesIn');
		$this->deleteService->expects(self::once())->method('restore');

		$this->listener()->handle(new NodeRestoredEvent($this->file(11)));
	}

	public function testANonDashboardFileIsIgnored(): void {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn('Budget.xlsx');
		$this->deleteService->expects(self::never())->method('restore');

		$this->listener()->handle(new NodeRestoredEvent($file));
	}

	/** The pull restores nothing, but its writes must never be read as a user restore. */
	public function testTheAppsOwnWriteIsNotTreatedAsARestore(): void {
		$this->deleteService->expects(self::never())->method('restore');

		$this->guard->enter();
		try {
			$this->listener()->handle(new NodeRestoredEvent($this->file(11)));
		} finally {
			$this->guard->leave();
		}
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function listener(): RestoreFromTrashListener {
		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturnCallback(
			fn (int $id): ?ManagedFile => array_key_exists($id, $this->managed)
				? $this->managed[$id]
				: new ManagedFile('d' . $id, Mapping::MODE_SYNC, '', '', 'm-demo', ''),
		);

		$mapping = Mapping::fromArray([
			'id' => 'm-demo',
			'grafana_folder_uid' => 'gf-demo',
			'nc_folder' => 'Demo',
			'mode' => Mapping::MODE_SYNC,
		]);
		$mappings = $this->createStub(MappingService::class);
		$mappings->method('getById')->willReturn($mapping);
		$mappings->method('resolveForPath')->willReturn($mapping);

		return new RestoreFromTrashListener(
			$this->deleteService,
			$mappings,
			$metadata,
			$this->cascade,
			$this->guard,
			new NullLogger(),
		);
	}

	private function file(int $id): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn('D' . $id . '.grafana');
		$file->method('getPath')->willReturn('/alice/files/Demo/Team/D' . $id . '.grafana');
		return $file;
	}
}
