<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\RestoreFromTrashListener;
use OCA\GrafanaSync\Listener\TrashRestoreHook;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TrashRestoreHook} — the restore leg for every trash the typed event never fires
 * for, which in practice means the Team Folders this app's mappings actually use.
 *
 * WHAT REACHES THE SHARED RULE TABLE IS THE PART WORTH TESTING. This hook fires for
 * EVERY restore on the instance, and it used to decide on its own what was worth looking
 * at — by asking whether `$params['filePath']` ended in `.grafana`.
 *
 * That filter was wrong in the one direction that mattered. A restored FOLDER's path
 * ends in a folder name, so it said no to every folder restore there has ever been, and
 * the folder branch lives in the typed listener that a groupfolder never reaches. The
 * hook now resolves the node and hands it to
 * {@see RestoreFromTrashListener::restoreTree()}, which owns the file/folder branch — so
 * "somebody's restored spreadsheet is ignored" is tested against that method
 * ({@see RestoreFromTrashListenerTest}) rather than asserted twice in two places that
 * could disagree.
 */
#[CoversClass(TrashRestoreHook::class)]
final class TrashRestoreHookTest extends TestCase {
	public function testARestoredDashboardIsHandedToTheSharedRuleTable(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::once())->method('restoreTree');

		$this->fire($restore, ['filePath' => '/Shared/Fleet Health.grafana'], $this->file());
	}

	/**
	 * THE TEAM FOLDER FIX, AND THE REGRESSION TEST FOR THE FILTER THAT CAUSED IT.
	 *
	 * A restored folder arrives with a folder path — `/Shared/Team`, no extension — and
	 * the old pre-filter returned on exactly that, before the node was even resolved. The
	 * typed listener's folder branch never runs for a groupfolder, so this hook returning
	 * here meant restoring a folder out of a Team Folder's trash reached Grafana never.
	 */
	public function testARestoredFolderIsHandedOverWhole(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::once())->method('restoreTree');

		$this->fire($restore, ['filePath' => '/Shared/Team'], $this->createStub(Folder::class));
	}

	public function testAMissingPathIsIgnored(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreTree');

		$this->fire($restore, [], $this->file());
	}

	/** The app's own writes must never re-enter through a user-facing path. */
	public function testTheAppsOwnWritesAreIgnored(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreTree');

		$guard = new SyncGuard();
		$guard->run(function () use ($restore, $guard): void {
			$this->fire($restore, ['filePath' => '/Shared/Fleet Health.grafana'], $this->file(), $guard);
		});
	}

	/**
	 * A NODE THAT CANNOT BE RESOLVED IS LOGGED, NOT THROWN. The file is already back by
	 * the time this runs, so there is nothing to abort — and throwing out of a legacy
	 * hook would surface as a failed restore on a file that restored perfectly well.
	 */
	public function testAnUnresolvableNodeDoesNotEscape(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreTree');

		$folder = $this->createStub(Folder::class);
		$folder->method('get')->willThrowException(new \RuntimeException('not found'));
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$hook = new TrashRestoreHook($root, $this->session(), $restore, new SyncGuard(), new NullLogger());
		$hook->postRestore(['filePath' => '/Shared/Fleet Health.grafana']);

		self::assertTrue(true, 'did not escape');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function file(): File {
		return $this->createStub(File::class);
	}

	private function session(): IUserSession {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/** @param array{filePath?: string} $params */
	private function fire(RestoreFromTrashListener $restore, array $params, object $node, ?SyncGuard $guard = null): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('get')->willReturn($node);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$hook = new TrashRestoreHook($root, $this->session(), $restore, $guard ?? new SyncGuard(), new NullLogger());
		$hook->postRestore($params);
	}
}
