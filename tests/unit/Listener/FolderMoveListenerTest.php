<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\FolderMoveListener;
use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MotionService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see FolderMoveListener} — the parent-changed half of a folder's path changing.
 *
 * A folder move has three endings and this covers all three: the re-parent, the
 * cascade a folder leaving the mapped set becomes, and the walk a folder arriving in
 * one becomes. The rest assert that it does NOT act — on a rename (its sibling's), on
 * a file, and on its own writes.
 */
#[CoversClass(FolderMoveListener::class)]
final class FolderMoveListenerTest extends TestCase {
	private GrafanaClient $grafana;
	private SyncGuard $guard;
	private SyncNotifier $notifier;
	private FolderCascade $cascade;
	private MotionService $motion;
	private CreateService $create;

	/** @var list<File> what the walk finds under the moved folder */
	private array $inside = [];

	/** @var array<int,ManagedFile> file id → its identity; absent = unmanaged */
	private array $identities = [];

	/** @var array<int,string> folder id → banked Grafana uid */
	private array $stamped = [];

	/** @var array<string,string> nc folder prefix → mapping mode; absent = unmapped */
	private array $mapped = [];

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->guard = new SyncGuard();
		$this->notifier = $this->createMock(SyncNotifier::class);
		$this->cascade = $this->createMock(FolderCascade::class);
		$this->motion = $this->createMock(MotionService::class);
		$this->create = $this->createMock(CreateService::class);
	}

	public function testAFolderMovedInsideItsMappingIsReparented(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::once())->method('moveFolder')->with('gf-team', 'gf-resolved');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team', 20));
	}

	public function testAFolderMovedIntoAnotherMappingIsReparented(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Reports' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::once())->method('moveFolder')->with('gf-team', 'gf-resolved');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Reports/Team', 20));
	}

	/**
	 * A RENAME IS THE SIBLING'S. Nextcloud fires one event for both, and acting on
	 * both here would re-parent a folder that never changed parent.
	 */
	public function testARenameIsLeftToTheRenameListener(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::never())->method('moveFolder');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team A', '/alice/files/Demo/Team B', 20));
	}

	/**
	 * LEAVING THE MAPPED SET IS A CASCADE, NOT A MOVE — every dashboard inside has to be
	 * deleted or parked, and the Grafana folder goes with them. Re-parenting a folder
	 * that belongs nowhere would drop it somewhere nobody chose, so the move call is
	 * exactly what must NOT happen here.
	 */
	public function testAFolderLeavingEveryMappingCascades(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC]; // Scratch is not mapped
		$this->grafana->expects(self::never())->method('moveFolder');
		$this->cascade->expects(self::once())->method('trash')->with(self::anything(), 'gf-team');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Scratch/Team', 20));
	}

	/** And a cascade Grafana refuses is reported, not thrown — the move already happened. */
	public function testACascadeFailureDoesNotEscapeAndIsReported(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->cascade->method('trash')->willThrowException(new \RuntimeException('unreachable'));
		$this->notifier->expects(self::once())->method('failed')->with('alice', 20, 'Team', self::isString());

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Scratch/Team', 20));
	}

	/**
	 * ARRIVING IN A MAPPING MAKES EVERY DASHBOARD IN THE FOLDER REAL. Nextcloud raises
	 * one event for the folder and none for the files inside it, so create-on-land never
	 * sees them — without the walk they stayed invisible to Grafana.
	 */
	public function testAnArrivingFolderRegistersTheDashboardsInIt(): void {
		$this->stamped = []; // Grafana has never seen this folder
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->inside = [$this->dashboardFile(7, '/alice/files/Demo/Team/Alpha.grafana')];
		$this->create->expects(self::once())->method('createForFile');
		$this->motion->expects(self::never())->method('onMove');

		$this->listener()->handle($this->moved('/alice/files/Scratch/Team', '/alice/files/Demo/Team', 40));
	}

	/**
	 * A PARKED FOLDER COMING HOME IS NOT A REBUILD. Its files still carry the uids they
	 * left with, so each one is a move — the per-file service un-parks it — and minting
	 * new dashboards would strand the parked ones in the bin for ever.
	 */
	public function testAnArrivingFolderMovesTheDashboardsThatKeptTheirIdentity(): void {
		$this->stamped = [];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->inside = [$this->dashboardFile(7, '/alice/files/Demo/Team/Alpha.grafana')];
		$this->identities = [7 => new ManagedFile('gs-alpha', 'sync', '1', '', 'm-Demo', '')];
		$this->create->expects(self::never())->method('createForFile');
		$this->motion->expects(self::once())->method('onMove')
			->with(self::anything(), '/alice/files/Scratch/Team/Alpha.grafana');

		$this->listener()->handle($this->moved('/alice/files/Scratch/Team', '/alice/files/Demo/Team', 40));
	}

	/** A link mapping is filled from Grafana, so an arriving folder creates nothing in it. */
	public function testAnArrivingFolderCreatesNothingInALinkMapping(): void {
		$this->stamped = [];
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];
		$this->inside = [$this->dashboardFile(7, '/alice/files/Pointers/Team/Alpha.grafana')];
		$this->create->expects(self::never())->method('createForFile');

		$this->listener()->handle($this->moved('/alice/files/Scratch/Team', '/alice/files/Pointers/Team', 40));
	}

	/** Outside every mapping and never mirrored: a folder the user made for their own reasons. */
	public function testAnUnstampedFolderOutsideEveryMappingIsIgnored(): void {
		$this->stamped = [];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::never())->method('moveFolder');
		$this->cascade->expects(self::never())->method('trash');
		$this->create->expects(self::never())->method('createForFile');

		$this->listener()->handle($this->moved('/alice/files/Notes/A', '/alice/files/Notes/B/A', 40));
	}

	public function testAFileMoveIsNotThisListenersBusiness(): void {
		$this->grafana->expects(self::never())->method('moveFolder');

		$source = $this->createStub(File::class);
		$source->method('getPath')->willReturn('/alice/files/Demo/A.grafana');
		$target = $this->createStub(File::class);
		$target->method('getPath')->willReturn('/alice/files/Reports/A.grafana');

		$this->listener()->handle($this->event($source, $target));
	}

	/** The pull's tree reconcile moves folders to follow Grafana; that must not bounce back. */
	public function testTheAppsOwnMoveIsNotSentBack(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::never())->method('moveFolder');

		$this->guard->enter();
		try {
			$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team', 20));
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * A WebDAV MOVE can change the parent AND the basename in one operation, and the
	 * rename listener steps aside the moment the parent differs — so without this the
	 * Grafana folder would be correctly placed under a stale title, with nothing else
	 * coming to fix it. Grafana has no call that does both.
	 */
	public function testAMoveThatAlsoRenamesSendsBoth(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::once())->method('moveFolder')->with('gf-team', 'gf-resolved');
		$this->grafana->expects(self::once())->method('renameFolder')->with('gf-team', 'Squad');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Squad', 20));
	}

	/** A move that keeps the name sends only the move — no pointless title write. */
	public function testAMoveThatKeepsTheNameDoesNotRename(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::once())->method('moveFolder');
		$this->grafana->expects(self::never())->method('renameFolder');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team', 20));
	}

	public function testAGrafanaFailureDoesNotEscapeAndIsReported(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->method('moveFolder')->willThrowException(new \RuntimeException('unreachable'));
		$this->notifier->expects(self::once())->method('failed')->with('alice', 20, 'Team', self::isString());

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team', 20));
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function listener(): FolderMoveListener {
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

		$mirror = $this->createStub(FolderMirror::class);
		$mirror->method('folderUidFor')->willReturn('gf-resolved');

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturnCallback(
			fn (int $id): ?ManagedFile => $this->identities[$id] ?? null,
		);
		$this->cascade->method('dashboardFilesIn')->willReturnCallback(fn (): array => $this->inside);

		return new FolderMoveListener(
			$folders,
			$metadata,
			$mappings,
			$mirror,
			$this->cascade,
			$this->motion,
			$this->create,
			$this->grafana,
			$this->guard,
			$this->notifier,
			$session,
			new NullLogger(),
		);
	}

	private function dashboardFile(int $id, string $path): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		return $file;
	}

	private function moved(string $from, string $to, int $id): NodeRenamedEvent {
		$source = $this->createStub(Folder::class);
		$source->method('getPath')->willReturn($from);
		$source->method('getName')->willReturn(basename($from));

		$target = $this->createStub(Folder::class);
		$target->method('getPath')->willReturn($to);
		$target->method('getName')->willReturn(basename($to));
		$target->method('getId')->willReturn($id);

		return $this->event($source, $target);
	}

	private function event(Node $source, Node $target): NodeRenamedEvent {
		$event = $this->createStub(NodeRenamedEvent::class);
		$event->method('getSource')->willReturn($source);
		$event->method('getTarget')->willReturn($target);
		return $event;
	}
}
