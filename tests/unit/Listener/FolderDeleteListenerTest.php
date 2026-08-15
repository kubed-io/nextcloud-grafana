<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\FolderDeleteListener;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see FolderDeleteListener} — the folder half of the delete gesture.
 *
 * The reason this listener exists at all is the first test below: Nextcloud fires ONE
 * delete event for a folder and none for anything inside it, so before this, trashing a
 * folder of dashboards left every one of them live in Grafana.
 */
#[CoversClass(FolderDeleteListener::class)]
final class FolderDeleteListenerTest extends TestCase {
	private FolderCascade $cascade;
	private SyncGuard $guard;

	/** @var array<int,string> folder id → banked Grafana uid */
	private array $stamped = [];

	/** @var array<string,string> nc folder → mapping mode; absent = unmapped */
	private array $mapped = [];

	protected function setUp(): void {
		$this->cascade = $this->createMock(FolderCascade::class);
		$this->guard = new SyncGuard();
	}

	public function testTrashingAMirroredSyncFolderCascadesIntoGrafana(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->cascade->expects(self::once())->method('trash')->with(self::anything(), 'gf-team');

		$this->listener()->handle($this->deleting('/alice/files/Demo/Team', 20));
	}

	/**
	 * UNDER A LINK THE TREE IS GRAFANA'S. Honouring the trash locally and leaving Grafana
	 * alone is the half-honoured shape the single-link rule already rejects: the folder
	 * leaves the mirror, the next pull writes it straight back, and in between the two
	 * sides disagree for no reason anyone chose.
	 */
	public function testTrashingAMirroredLinkFolderIsRefused(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];
		$this->cascade->expects(self::never())->method('trash');

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/Delete it in Grafana/');
		$this->listener()->handle($this->deleting('/alice/files/Pointers/Team', 20));
	}

	/**
	 * A folder under a mapping is a plain folder until a dashboard lands beneath it, so a
	 * folder this app never stamped is the user's own — including inside a link mapping.
	 */
	public function testAnUnstampedFolderIsNotOurBusiness(): void {
		$this->stamped = [];
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];
		$this->cascade->expects(self::never())->method('trash');
		$this->cascade->expects(self::never())->method('purge');

		$this->listener()->handle($this->deleting('/alice/files/Pointers/Notes', 40));
	}

	/** A mapping's own folder is never stamped, so trashing it never reaches the cascade. */
	public function testTheMappedFolderItselfIsLeftAlone(): void {
		$this->stamped = []; // FolderMirror/FolderTreeMirror stamp subfolders only
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->cascade->expects(self::never())->method('trash');

		$this->listener()->handle($this->deleting('/alice/files/Demo', 20));
	}

	public function testAFileDeleteIsNotThisListenersBusiness(): void {
		$this->cascade->expects(self::never())->method('trash');

		$file = $this->createStub(File::class);
		$file->method('getPath')->willReturn('/alice/files/Demo/A.grafana');

		$this->listener()->handle($this->event($file));
	}

	/** The pull removes mirrored folders to follow Grafana; that must not bounce back. */
	public function testTheAppsOwnDeleteIsNotSentBack(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->cascade->expects(self::never())->method('trash');

		$this->guard->enter();
		try {
			$this->listener()->handle($this->deleting('/alice/files/Demo/Team', 20));
		} finally {
			$this->guard->leave();
		}
	}

	/** Stamped but nowhere mapped: nothing here knows which Grafana it belongs to. */
	public function testAMirroredFolderOutsideEveryMappingIsNotTouched(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = [];
		$this->cascade->expects(self::never())->method('trash');

		$this->listener()->handle($this->deleting('/alice/files/Scratch/Team', 20));
	}

	/**
	 * A trashbin path means the trash was BYPASSED — there will be no purge hook later, so
	 * this is the only chance to delete what was parked.
	 */
	public function testAFolderDeletedStraightFromTheTrashIsPurgedNotTrashed(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->cascade->expects(self::once())->method('purge');
		$this->cascade->expects(self::never())->method('trash');

		$this->listener()->handle($this->deleting('/alice/files_trashbin/files/Team.d1770000000', 20));
	}

	/** Never desync: if Grafana will not confirm, the Nextcloud delete does not happen. */
	public function testAGrafanaFailureAbortsTheNextcloudDelete(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->cascade->method('trash')->willThrowException(new \RuntimeException('unreachable'));

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/unreachable/');
		$this->listener()->handle($this->deleting('/alice/files/Demo/Team', 20));
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function listener(): FolderDeleteListener {
		$folders = $this->createStub(FolderMetadata::class);
		$folders->method('uidOf')->willReturnCallback(fn (int $id): string => $this->stamped[$id] ?? '');

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('resolveForPath')->willReturnCallback(
			function (string $path): ?Mapping {
				foreach ($this->mapped as $folder => $mode) {
					if (str_contains($path, '/files/' . $folder . '/')) {
						return Mapping::fromArray([
							'id' => 'm-' . $folder,
							'grafana_folder_uid' => 'gf-' . $folder,
							'nc_folder' => $folder,
							'mode' => $mode,
						]);
					}
				}
				return null;
			},
		);

		return new FolderDeleteListener($folders, $mappings, $this->cascade, $this->guard, new NullLogger());
	}

	private function deleting(string $path, int $id): BeforeNodeDeletedEvent {
		$folder = $this->createStub(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getId')->willReturn($id);
		return $this->event($folder);
	}

	private function event(Node $node): BeforeNodeDeletedEvent {
		return new BeforeNodeDeletedEvent($node);
	}
}
