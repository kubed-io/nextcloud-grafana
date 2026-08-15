<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\BackgroundJob\ReconcileNameJob;
use OCA\GrafanaSync\Service\CopyService;
use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see CopyService} — a copy is ALWAYS a new instance. These pin that
 * the copy's inherited identity is always stripped, and it is registered as a fresh
 * dashboard only when it lands in a mapped **sync** folder (never a link folder, never
 * outside a mapping).
 */
#[CoversClass(CopyService::class)]
final class CopyServiceTest extends TestCase {
	private CreateService $create;
	private MappingService $mappings;
	private DashboardMetadata $metadata;
	private IJobList $jobList;
	private CopyService $service;

	protected function setUp(): void {
		$this->create = $this->createMock(CreateService::class);
		$this->mappings = $this->createStub(MappingService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->jobList = $this->createMock(IJobList::class);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new CopyService(
			$this->create,
			$this->mappings,
			$this->metadata,
			new SyncGuard(),
			$this->jobList,
			$session,
			new NullLogger(),
		);
	}

	private function mapping(string $mode = Mapping::MODE_SYNC): Mapping {
		return Mapping::fromArray(['grafana_folder_uid' => 'gf-demo', 'nc_folder' => 'demo', 'mode' => $mode]);
	}

	private function file(int $id = 1, string $body = '{"title":"Copy"}'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn('/demo/Copy.grafana.json');
		$node->method('getContent')->willReturn($body);
		return $node;
	}

	public function testACopyOutsideAnyMappingStripsIdentityAndDoesNotCreate(): void {
		$this->mappings->method('resolveForPath')->willReturn(null);
		$this->metadata->expects(self::once())->method('clear')->with(1);
		$this->create->expects(self::never())->method('createForFile');

		$this->service->onCopy($this->file(1));
	}

	public function testACopyIntoALinkFolderStripsIdentityButDoesNotCreate(): void {
		// A link folder is for read-only pointers — a copy there is not authored. Both
		// halves of stripIdentity must still run (metadata + ownership pill cleared).
		$this->mappings->method('resolveForPath')->willReturn($this->mapping(Mapping::MODE_LINK));
		$this->metadata->expects(self::once())->method('clear')->with(1);
		$this->create->expects(self::never())->method('createForFile');

		$this->service->onCopy($this->file(1));
	}

	public function testACopyIntoASyncFolderStripsIdentityThenCreatesFresh(): void {
		$mapping = $this->mapping(Mapping::MODE_SYNC);
		$this->mappings->method('resolveForPath')->willReturn($mapping);
		$this->metadata->expects(self::once())->method('clear')->with(1);
		$this->create->expects(self::once())->method('createForFile')->with(self::isInstanceOf(File::class), $mapping, true);

		$this->service->onCopy($this->file(1));
	}

	/**
	 * THE HIJACK. A dashboard spec carries its own `uid`, so every file a sync has ever
	 * written has one inside it — and an upsert keys on the body's uid. Left there, the
	 * copy is not a new dashboard at all: it is a new VERSION of the original, written
	 * over the top of it.
	 *
	 * Measured on a live instance before this was fixed: the source dashboard gained a
	 * `v2 "Updated by Nextcloud"` and no second dashboard ever existed.
	 *
	 * The claim here is narrow on purpose — the copy asks for a NEW dashboard rather
	 * than trusting the body to be innocent. What that flag then does to the spec is
	 * {@see CreateServiceTest}'s to pin, next to the upsert it shapes.
	 */
	public function testACopyAsksForANewDashboardRatherThanTrustingTheBody(): void {
		$mapping = $this->mapping(Mapping::MODE_SYNC);
		$this->mappings->method('resolveForPath')->willReturn($mapping);
		$this->create->expects(self::once())->method('createForFile')
			->with(self::isInstanceOf(File::class), $mapping, true);

		$this->service->onCopy($this->file(1, '{"title":"Board","uid":"original-uid"}'));
	}

	/**
	 * THE COPY'S OWN HOOK CANNOT WRITE THE FILE. Nextcloud still holds locks on the
	 * target while the copy events run, so a `putContent` here throws
	 * `LockedException: 2 shared locks` — measured live, and the same trap
	 * {@see \OCA\GrafanaSync\Listener\NameSyncListener} defers to a job to avoid.
	 * That is why the uid is dropped from the spec on its way to Grafana and the file
	 * is left exactly as the copy made it.
	 */
	public function testTheCopiedFileIsNeverRewritten(): void {
		$this->mappings->method('resolveForPath')->willReturn($this->mapping(Mapping::MODE_SYNC));

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(1);
		$node->method('getPath')->willReturn('/demo/Copy.grafana.json');
		$node->method('getContent')->willReturn('{"title":"Board","uid":"original-uid"}');
		$node->expects(self::never())->method('putContent');

		$this->service->onCopy($node);
	}

	/**
	 * THE NAME NEXTCLOUD PICKED HAS TO REACH THE FILE, and the copy's own hook cannot
	 * put it there — same locks as {@see testTheCopiedFileIsNeverRewritten()}. So the
	 * work is handed to {@see ReconcileNameJob}, which runs once they are gone.
	 *
	 * `title_from_filename` because a copy IS a naming: Nextcloud just gave this file a
	 * name, exactly as a rename does, and in both cases the filename is the authority.
	 */
	public function testACopyHandsItsNameToTheReconcileJob(): void {
		$this->mappings->method('resolveForPath')->willReturn($this->mapping(Mapping::MODE_SYNC));
		$this->jobList->expects(self::once())->method('add')->with(
			ReconcileNameJob::class,
			['fileId' => 7, 'userId' => 'alice', 'action' => 'title_from_filename'],
		);

		$this->service->onCopy($this->file(7));
	}

	/**
	 * A copy that never became a dashboard has no name to reconcile — it is a plain
	 * untracked file, and the job's own gate would drop it anyway. Enqueuing regardless
	 * would leave the queue carrying one dead job per failed copy.
	 */
	public function testAFailedRegistrationEnqueuesNothing(): void {
		$this->mappings->method('resolveForPath')->willReturn($this->mapping(Mapping::MODE_SYNC));
		$this->create->method('createForFile')->willThrowException(new \RuntimeException('Grafana said no'));
		$this->jobList->expects(self::never())->method('add');

		$this->service->onCopy($this->file(1));
	}

	/** A copy that was never registered anywhere has nothing to name either. */
	public function testACopyOutsideAMappingEnqueuesNothing(): void {
		$this->mappings->method('resolveForPath')->willReturn(null);
		$this->jobList->expects(self::never())->method('add');

		$this->service->onCopy($this->file(1));
	}
}
