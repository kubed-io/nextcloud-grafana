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
 * THE PRE-FILTER IS THE PART WORTH TESTING. This hook fires for EVERY restore on the
 * instance, so the expensive failure is not "the dashboard did not come back" — it is
 * the hook acting on somebody's restored spreadsheet.
 */
#[CoversClass(TrashRestoreHook::class)]
final class TrashRestoreHookTest extends TestCase {
	public function testARestoredDashboardIsHandedToTheSharedRuleTable(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::once())->method('restoreOne');

		$this->fire($restore, ['filePath' => '/Shared/Fleet Health.grafana'], $this->file());
	}

	public function testARestoredSpreadsheetIsIgnored(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreOne');

		$this->fire($restore, ['filePath' => '/Shared/budget.xlsx'], $this->file());
	}

	public function testAMissingPathIsIgnored(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreOne');

		$this->fire($restore, [], $this->file());
	}

	/**
	 * A FOLDER restored whole is not this hook's business. The typed listener walks a
	 * restored folder through the cascade; here the node is not a File and there is
	 * nothing safe to infer from the name alone.
	 */
	public function testARestoredFolderIsIgnored(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreOne');

		$this->fire($restore, ['filePath' => '/Shared/Team.grafana'], $this->createStub(Folder::class));
	}

	/** The app's own writes must never re-enter through a user-facing path. */
	public function testTheAppsOwnWritesAreIgnored(): void {
		$restore = $this->createMock(RestoreFromTrashListener::class);
		$restore->expects(self::never())->method('restoreOne');

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
		$restore->expects(self::never())->method('restoreOne');

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
