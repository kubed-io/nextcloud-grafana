<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\FolderMoveListener;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
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
 * Most of these assert that it does NOT act: on a rename (its sibling's), on a folder
 * this app never stamped, on its own writes, and on a folder that has left the mapped
 * set — which is a cascade rather than a move, and doing half of it would put the
 * Grafana folder somewhere nobody chose.
 */
#[CoversClass(FolderMoveListener::class)]
final class FolderMoveListenerTest extends TestCase {
	private GrafanaClient $grafana;
	private SyncGuard $guard;
	private SyncNotifier $notifier;

	/** @var array<int,string> folder id → banked Grafana uid */
	private array $stamped = [];

	/** @var array<string,string> nc folder prefix → mapping mode; absent = unmapped */
	private array $mapped = [];

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->guard = new SyncGuard();
		$this->notifier = $this->createMock(SyncNotifier::class);
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
	 * LEAVING THE MAPPED SET IS A CASCADE, NOT A MOVE — every dashboard inside has to
	 * be deleted or parked. Re-parenting a folder that belongs nowhere would drop the
	 * Grafana folder somewhere nobody chose.
	 */
	public function testAFolderLeavingEveryMappingIsNotReparented(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC]; // Scratch is not mapped
		$this->grafana->expects(self::never())->method('moveFolder');

		$this->listener()->handle($this->moved('/alice/files/Demo/Team', '/alice/files/Scratch/Team', 20));
	}

	public function testAnUnstampedFolderIsIgnored(): void {
		$this->stamped = [];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];
		$this->grafana->expects(self::never())->method('moveFolder');

		$this->listener()->handle($this->moved('/alice/files/Demo/Notes', '/alice/files/Demo/Archive/Notes', 40));
	}

	public function testAFileMoveIsNotThisListenersBusiness(): void {
		$this->grafana->expects(self::never())->method('moveFolder');

		$source = $this->createStub(File::class);
		$source->method('getPath')->willReturn('/alice/files/Demo/A.grafana.json');
		$target = $this->createStub(File::class);
		$target->method('getPath')->willReturn('/alice/files/Reports/A.grafana.json');

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

		return new FolderMoveListener(
			$folders,
			$mappings,
			$mirror,
			$this->grafana,
			$this->guard,
			$this->notifier,
			$session,
			new NullLogger(),
		);
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
