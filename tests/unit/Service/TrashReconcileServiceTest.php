<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TeamFolderService;
use OCA\GrafanaSync\Service\TrashControl;
use OCA\GrafanaSync\Service\TrashedFile;
use OCA\GrafanaSync\Service\TrashReconcileService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TrashReconcileService} — a mirror stays in the Nextcloud trash only while the
 * dashboard it mirrors still exists.
 *
 * THE TESTS THAT MATTER ARE THE ONES THAT DO NOT PURGE. A wrong "yes" here destroys the
 * last copy of a dashboard and Grafana has no undo, so every way of being unsure has to
 * end in leaving the entry alone. Six of the seven below assert exactly that.
 */
#[CoversClass(TrashReconcileService::class)]
final class TrashReconcileServiceTest extends TestCase {
	private const MAPPING_ID = 'm-demo';

	public function testAMirrorWhoseDashboardIsGoneIsPurged(): void {
		$purged = false;
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7, static function () use (&$purged): void {
				$purged = true;
			})],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(1, $service->reap($this->mapping()));
		self::assertTrue($purged, 'the trashed mirror was not purged');
	}

	/**
	 * PARKED IS NOT GONE, and this is the case the whole class exists to get right. A
	 * dashboard in the recycle-bin folder answers 200 — it is absent from the mapping's
	 * listing precisely BECAUSE it is parked, which is the state where its mirror
	 * belongs in the trash exactly where it is.
	 */
	public function testAParkedDashboardLeavesItsMirrorAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			$this->managed('dash-parked'),
			static fn (): array => ['meta' => ['folderUid' => 'gf-bin'], 'dashboard' => ['uid' => 'dash-parked']],
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/** An unreachable Grafana is not proof of anything. */
	public function testATransportFailureLeavesTheMirrorAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			$this->managed('dash-unknown'),
			static fn (): never => throw new \RuntimeException('connection refused'),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/** Nor is a 500 — only an explicit 404 counts. */
	public function testAServerErrorLeavesTheMirrorAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			$this->managed('dash-unknown'),
			static fn (): never => throw new GrafanaApiException('boom', 500),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	public function testAFileFromAnotherMappingIsNotOursToJudge(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			new ManagedFile('dash-gone', Mapping::MODE_SYNC, '1', 'h', 'm-other', ''),
			static fn (): never => throw new GrafanaApiException('gone', 404),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/**
	 * An `unmapped` file left its mapping deliberately, and its dashboard stopped being
	 * this app's business the moment it did — the same rule the user-driven purge states.
	 */
	public function testAnUnmappedFileIsLeftAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			new ManagedFile('dash-gone', DashboardMetadata::MODE_UNMAPPED, '1', 'h', self::MAPPING_ID, ''),
			static fn (): never => throw new GrafanaApiException('gone', 404),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/** Somebody else's trashed spreadsheet, which never had anything to do with us. */
	public function testAForeignTrashEntryIsIgnored(): void {
		$service = $this->service(
			[$this->trashed('budget.xlsx', 7)],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('gone', 404),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/**
	 * THE OTHER DIRECTION. A dashboard rescued out of the bin folder has its mirror
	 * brought back, rather than the pull writing a second file beside a trash entry for
	 * the one the user actually had.
	 */
	public function testARescuedDashboardHasItsMirrorRestored(): void {
		$restored = false;
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7, null, static function () use (&$restored): void {
				$restored = true;
			})],
			$this->managed('dash-rescued'),
			static fn (): array => ['meta' => [], 'dashboard' => ['uid' => 'dash-rescued']],
		);

		$service->restoreMirror($this->mapping(), 'dash-rescued');

		self::assertTrue($restored, 'the trashed mirror was not restored');
	}

	/** Nothing trashed for this dashboard: the caller writes a mirror as it always did. */
	public function testNoTrashedMirrorMeansNothingToRestore(): void {
		$service = $this->service([], $this->managed('dash-live'), static fn (): array => []);

		self::assertNull($service->restoreMirror($this->mapping(), 'dash-live'));
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function mapping(): Mapping {
		return Mapping::fromArray([
			'id' => self::MAPPING_ID,
			'grafana_folder_uid' => 'gf-demo',
			'nc_folder' => 'Demo',
			'mode' => Mapping::MODE_SYNC,
		]);
	}

	private function managed(string $uid): ManagedFile {
		return new ManagedFile($uid, Mapping::MODE_SYNC, '1', 'hash', self::MAPPING_ID, '');
	}

	private function trashed(string $name, int $fileId, ?\Closure $purge = null, ?\Closure $restore = null): TrashedFile {
		return new TrashedFile(
			$fileId,
			$name,
			$purge ?? static function (): void {
			},
			$restore ?? static function (): void {
			},
		);
	}

	/**
	 * @param list<TrashedFile> $inTrash
	 * @param \Closure():mixed $readDashboard what Grafana answers for the uid under test
	 */
	private function service(array $inTrash, ManagedFile $managed, \Closure $readDashboard): TrashReconcileService {
		$trash = $this->createStub(TrashControl::class);
		$trash->method('listTrashed')->willReturn($inTrash);

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturn($managed);

		$grafana = $this->createStub(GrafanaClient::class);
		$grafana->method('readDashboard')->willReturnCallback($readDashboard);

		$teamFolders = $this->createStub(TeamFolderService::class);
		$teamFolders->method('resolveActorUid')->willReturn('admin');

		return new TrashReconcileService(
			$this->createStub(IRootFolder::class),
			$trash,
			$metadata,
			$grafana,
			$teamFolders,
			new SyncGuard(),
			new NullLogger(),
		);
	}
}
