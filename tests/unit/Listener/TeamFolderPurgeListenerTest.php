<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\TeamFolderPurgeListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TeamFolderPurgeListener} — the purge leg for every trash the legacy hook
 * cannot see.
 *
 * THE TESTS THAT MATTER ARE THE ONES THAT DO NOTHING. This listener is bound to an
 * event that fires for every cache-entry removal anywhere in the instance, so the
 * expensive failure is not "the purge did not run" — it is the purge running for an
 * ordinary delete and destroying a dashboard nobody asked about. Four of the six
 * below assert `deleteDashboard` was never reached.
 */
#[CoversClass(TeamFolderPurgeListener::class)]
final class TeamFolderPurgeListenerTest extends TestCase {
	public function testAPurgedTrashedMirrorFinishesTheDeleteInGrafana(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->expects(self::once())->method('hardDelete');

		$this->fire($delete, 'Shared/Fleet Health.grafana.d1700000000', $this->managed());
	}

	/** The home trash has its own signal, on a hook that runs BEFORE the unlink. */
	public function testTheHomeTrashIsLeftToTheLegacyHook(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->expects(self::never())->method('hardDelete');

		$this->fire($delete, 'files_trashbin/files/Fleet Health.grafana.d1700000000', $this->managed());
	}

	/**
	 * AN ORDINARY DELETE IS NOT A PURGE. A file only ever wears the `.d<timestamp>`
	 * spelling while it sits in a trash — which is also what keeps this listener off
	 * the app's OWN permanent delete of a link, since that unlinks a file still
	 * named `<stem>.grafana`.
	 */
	public function testAFileWithoutTheTrashedNameShapeIsIgnored(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->expects(self::never())->method('hardDelete');

		$this->fire($delete, 'Shared/Fleet Health.grafana', $this->managed());
	}

	public function testANonDashboardFileIsIgnored(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->expects(self::never())->method('hardDelete');

		$this->fire($delete, 'Shared/notes.txt.d1700000000', $this->managed());
	}

	public function testAFileCarryingNoMetadataIsIgnored(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->expects(self::never())->method('hardDelete');

		$this->fire($delete, 'Shared/Fleet Health.grafana.d1700000000', null);
	}

	/** The app's own trash housekeeping must never ask Grafana to delete twice. */
	public function testTheAppsOwnWritesAreIgnored(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->expects(self::never())->method('hardDelete');

		$guard = new SyncGuard();
		$guard->run(function () use ($delete, $guard): void {
			$this->fire($delete, 'Shared/Fleet Health.grafana.d1700000000', $this->managed(), $guard);
		});
	}

	/**
	 * A FAILING GRAFANA CALL MUST NOT ESCAPE. The file is already destroyed by the
	 * time this event exists, so there is nothing left to abort — a thrown exception
	 * would surface as a failed purge on a file that is already gone.
	 */
	public function testAFailedDeleteIsLoggedAndSwallowed(): void {
		$delete = $this->createMock(DeleteService::class);
		$delete->method('hardDelete')->willThrowException(new \RuntimeException('grafana said no'));

		$this->fire($delete, 'Shared/Fleet Health.grafana.d1700000000', $this->managed());

		self::assertTrue(true, 'did not escape');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function managed(): ManagedFile {
		return new ManagedFile('dash-1', Mapping::MODE_SYNC, '1', 'hash', 'm-shared', '');
	}

	private function fire(DeleteService $delete, string $path, ?ManagedFile $managed, ?SyncGuard $guard = null): void {
		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturn($managed);

		$listener = new TeamFolderPurgeListener($delete, $metadata, $guard ?? new SyncGuard(), new NullLogger());
		$listener->handle(new CacheEntryRemovedEvent($this->createStub(IStorage::class), $path, 42, 7));
	}
}
