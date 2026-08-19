<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\TrashPurgeHook;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\RestoreInProgress;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TrashPurgeHook} — emptying the Nextcloud trash, which is the irreversible moment
 * when the recycle bin is on.
 *
 * These are the tests it never had, and the folder ones are why it needed them: the hook
 * used to skip anything whose name did not contain `.grafana`, and a trashed folder
 * is named after the folder. Nextcloud emits ONE purge hook for it and none for its
 * contents, so every dashboard parked from that folder stayed in Grafana forever.
 *
 * Every test supplies a session user, so the `\OC_User::getUser()` fallback for background
 * retention jobs is not exercised here — that static has no seam to stub.
 */
#[CoversClass(TrashPurgeHook::class)]
final class TrashPurgeHookTest extends TestCase {
	private DeleteService $deleteService;
	private FolderCascade $cascade;
	private SyncGuard $guard;
	private ?Node $resolved = null;

	protected function setUp(): void {
		$this->deleteService = $this->createMock(DeleteService::class);
		$this->cascade = $this->createMock(FolderCascade::class);
		$this->guard = new SyncGuard();
		$this->restoring = new RestoreInProgress();
	}

	/**
	 * THE ONE THAT COST A DASHBOARD ON A LIVE INSTANCE. A WebDAV restore is a MOVE out
	 * of `trashbin/`, and Sabre cannot rename across collections — so it copies and then
	 * DELETES the trashed node through `Trashbin::delete()`, the same function emptying
	 * the trash calls, which emits the very hook this class listens to. The purge ran,
	 * and the dashboard the user was restoring was permanently deleted.
	 *
	 * Every restore scenario in the integration suite passes regardless: on CI's local
	 * storage the same MOVE is a rename, so no delete happens and this hook never fires.
	 * This test is the only place that failure is reachable.
	 */
	public function testARestoreIsNotAPurge(): void {
		// The same arrangement as the purge test below — a managed dashboard file being
		// removed from the trash. The ONLY difference is the flag, which is the point.
		$this->resolved = $this->file(11);
		$this->restoring->mark();
		$this->deleteService->expects(self::never())->method('hardDelete');

		$this->hook()->preDelete(['path' => '/files_trashbin/files/A.grafana.d1770000000']);
	}

	/** THE BUG. One hook for the whole subtree, and the name says nothing about it. */
	public function testAPurgedFolderIsHandedToTheCascade(): void {
		$this->resolved = $this->createStub(Folder::class);
		$this->cascade->expects(self::once())->method('purge');
		$this->deleteService->expects(self::never())->method('hardDelete');

		$this->hook()->preDelete(['path' => '/files_trashbin/files/Team.d1770000000']);
	}

	public function testAPurgedDashboardFileStillTakesTheSingleFilePath(): void {
		$this->resolved = $this->file(11);
		$this->deleteService->expects(self::once())->method('hardDelete');
		$this->cascade->expects(self::never())->method('purge');

		$this->hook()->preDelete(['path' => '/files_trashbin/files/A.grafana.d1770000000']);
	}

	/** Bin OFF stripped the identity at trash-time, so there is nothing left to delete. */
	public function testAnUnmanagedFileIsLeftAlone(): void {
		$this->resolved = $this->file(11, managed: false);
		$this->deleteService->expects(self::never())->method('hardDelete');

		$this->hook()->preDelete(['path' => '/files_trashbin/files/A.grafana.d1770000000']);
	}

	public function testAPathThatCannotBeResolvedIsSkipped(): void {
		$this->resolved = null;
		$this->cascade->expects(self::never())->method('purge');
		$this->deleteService->expects(self::never())->method('hardDelete');

		$this->hook()->preDelete(['path' => '/files_trashbin/files/Gone.d1770000000']);
	}

	public function testAnEmptyPathIsSkipped(): void {
		$this->cascade->expects(self::never())->method('purge');

		$this->hook()->preDelete([]);
	}

	/** The app's own writes go through the guard; a purge it caused is not a user purge. */
	public function testTheAppsOwnPurgeIsIgnored(): void {
		$this->resolved = $this->createStub(Folder::class);
		$this->cascade->expects(self::never())->method('purge');

		$this->guard->enter();
		try {
			$this->hook()->preDelete(['path' => '/files_trashbin/files/Team.d1770000000']);
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * A legacy hook cannot cleanly abort a purge, and a parked dashboard left behind is a
	 * recoverable leak rather than data loss — so a Grafana failure never escapes.
	 */
	public function testAGrafanaFailureDoesNotEscape(): void {
		$this->resolved = $this->file(11);
		$this->deleteService->method('hardDelete')->willThrowException(new \RuntimeException('unreachable'));

		$this->hook()->preDelete(['path' => '/files_trashbin/files/A.grafana.d1770000000']);

		self::assertTrue(true, 'the purge was allowed to proceed');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function hook(): TrashPurgeHook {
		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturnCallback(
			fn (int $id): ?ManagedFile => $id > 0
				? new ManagedFile('d' . $id, Mapping::MODE_SYNC, '', '', 'm-demo', '')
				: null,
		);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// The hook resolves the trash path against the home folder's PARENT, because the
		// trash lives beside /files rather than inside it.
		$home = $this->createStub(Folder::class);
		$storageRoot = $this->createStub(Folder::class);
		$storageRoot->method('get')->willReturnCallback(
			fn (string $path): Node => $this->resolved ?? throw new \RuntimeException('not found'),
		);
		$home->method('getParent')->willReturn($storageRoot);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($home);

		return new TrashPurgeHook(
			$this->restoring,
			$this->deleteService,
			$metadata,
			$this->cascade,
			$this->guard,
			$session,
			$rootFolder,
			new NullLogger(),
		);
	}

	/**
	 * A REAL FLAG, NOT A STUB — it is a request-scoped bool with no I/O, and the test
	 * below that raises it is the whole point of the class.
	 */
	private RestoreInProgress $restoring;

	private function file(int $id, bool $managed = true): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn($managed ? $id : 0);
		$file->method('getName')->willReturn('A.grafana.d1770000000');
		return $file;
	}
}
