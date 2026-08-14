<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\PushService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the {@see PushService} writeback (saga Ch2 Course 3). These pin the
 * decisions that make the push safe and loop-proof:
 *
 *  - only a managed **sync** file pushes (unmanaged / link / non-File never do);
 *  - the upsert preserves the **uid** from metadata (a hand-edited file uid can't
 *    retarget a different dashboard) and places it in the mapping's folder;
 *  - on success the loop-guard hash (sha1 of the sent bytes) + the returned version
 *    are stamped; on **failure nothing is stamped** so the next save retries.
 */
#[CoversClass(PushService::class)]
final class PushServiceTest extends TestCase {
	private MappingService $mappings;
	private GrafanaClient $grafana;
	private DashboardMetadata $metadata;
	private FolderMirror $folderMirror;
	private PushService $service;

	protected function setUp(): void {
		$this->mappings = $this->createStub(MappingService::class);
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		// A file sitting directly in its mapped folder: the mirror answers with the
		// mapping's own Grafana folder and contacts nothing. The subfolder behaviour
		// belongs to FolderMirrorTest.
		$this->folderMirror = $this->createStub(FolderMirror::class);
		$this->folderMirror->method('folderUidFor')->willReturnCallback(
			static fn (Node $n, Mapping $m): ?string
				=> $m->grafanaFolderUid === '/' ? null : $m->grafanaFolderUid,
		);
		$this->service = new PushService($this->mappings, $this->grafana, $this->metadata, $this->folderMirror, new NullLogger());
	}

	private function mapping(string $folderUid = 'gf-alpha', string $id = 'map-alpha'): Mapping {
		return Mapping::fromArray([
			'id' => $id,
			'grafana_folder_uid' => $folderUid,
			'nc_folder' => 'alpha',
			'mode' => 'sync',
		]);
	}

	private function managed(string $uid = 'd1', string $mode = Mapping::MODE_SYNC, string $mappingId = 'map-alpha', string $folderUid = ''): ManagedFile {
		return new ManagedFile($uid, $mode, '', '', $mappingId, $folderUid, '');
	}

	/** A sync `.grafana.json` File with the given id + JSON content. */
	private function file(int $id, string $content, string $name = 'Board.grafana.json'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getContent')->willReturn($content);
		$node->method('getName')->willReturn($name);
		$node->method('getPath')->willReturn('/alpha/' . $name);
		return $node;
	}

	// ── the guards ────────────────────────────────────────────────────────────

	public function testNonFileNodeIsNotPushed(): void {
		$this->grafana->expects(self::never())->method('upsertDashboard');
		self::assertFalse($this->service->push($this->createStub(Folder::class)));
	}

	public function testUnmanagedFileIsNotPushed(): void {
		$this->metadata->method('read')->willReturn(null);
		$this->grafana->expects(self::never())->method('upsertDashboard');
		self::assertFalse($this->service->push($this->file(1, '{}')));
	}

	public function testLinkFileIsNotPushed(): void {
		// A link file is a read-only pointer — its reference body must never be upserted.
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_LINK));
		$this->grafana->expects(self::never())->method('upsertDashboard');
		self::assertFalse($this->service->push($this->file(1, '{"$schema":"grafana.reference/v1","uid":"d1"}')));
	}

	// ── the happy path ────────────────────────────────────────────────────────

	public function testSyncFileUpsertsPreservingUidAndFolderThenStamps(): void {
		$content = '{"uid":"d1","title":"Board","panels":[]}';
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->mappings->method('getById')->willReturn($this->mapping('gf-alpha', 'map-alpha'));

		$captured = null;
		$this->grafana->expects(self::once())->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$captured): array {
				$captured = $body;
				return ['uid' => 'd1', 'version' => 7, 'status' => 'success'];
			});

		// Loop-guard hash = sha1 of the exact bytes we sent; version = Grafana's bump.
		$this->metadata->expects(self::once())->method('write')
			->with(1, [
				DashboardMetadata::KEY_SYNCED_HASH => sha1($content),
				DashboardMetadata::KEY_VERSION => '7',
			]);

		self::assertTrue($this->service->push($this->file(1, $content)));

		self::assertIsArray($captured);
		self::assertTrue($captured['overwrite'] ?? null, 'upsert must set overwrite:true');
		self::assertSame('gf-alpha', $captured['folderUid'] ?? null, 'placed in the mapping folder');
		self::assertInstanceOf(\stdClass::class, $captured['dashboard']);
		self::assertSame('d1', $captured['dashboard']->uid, 'uid preserved as the identity');
		self::assertNull($captured['dashboard']->id, 'id forced null so Grafana keys on uid');
	}

	public function testAHandEditedUidCannotRetargetADifferentDashboard(): void {
		// The file's body says uid "evil", but metadata says the file IS "d1" — the push
		// forces the metadata uid so a typo/edit can't overwrite someone else's dashboard.
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha'));
		$this->mappings->method('getById')->willReturn($this->mapping());

		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'd1', 'version' => 2];
		});

		$this->service->push($this->file(1, '{"uid":"evil","title":"X"}'));

		self::assertSame('d1', $captured['dashboard']->uid);
	}

	public function testRootMappingPushesToGeneralWithoutAFolderUid(): void {
		// A reserved-root ("/") mapping means General / no folder → folderUid omitted.
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-root'));
		$this->mappings->method('getById')->willReturn($this->mapping('/', 'map-root'));

		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'd1', 'version' => 1];
		});

		$this->service->push($this->file(1, '{"uid":"d1","title":"X"}'));

		self::assertArrayNotHasKey('folderUid', $captured, 'General placement omits folderUid');
	}

	public function testBankedFolderUidWinsOverTheMapping(): void {
		// If the file already banks a grafana_folderUid, the push honours it directly.
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-alpha', 'banked-folder'));
		// getById should not even be needed, but return the mapping to be safe.
		$this->mappings->method('getById')->willReturn($this->mapping('gf-alpha'));

		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'd1', 'version' => 1];
		});

		$this->service->push($this->file(1, '{"uid":"d1","title":"X"}'));

		self::assertSame('banked-folder', $captured['folderUid'] ?? null);
	}

	// ── failure never stamps (so the next save retries) ─────────────────────────

	public function testMalformedJsonThrowsAndNeverStamps(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->mappings->method('getById')->willReturn($this->mapping());
		$this->metadata->expects(self::never())->method('write');

		$this->expectException(\RuntimeException::class);
		$this->service->push($this->file(1, '{not json'));
	}

	public function testUpsertFailurePropagatesAndNeverStamps(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->mappings->method('getById')->willReturn($this->mapping());
		$this->grafana->method('upsertDashboard')->willThrowException(new GrafanaApiException('title already exists', 412));
		$this->metadata->expects(self::never())->method('write');

		$this->expectException(GrafanaApiException::class);
		$this->service->push($this->file(1, '{"uid":"d1","title":"X"}'));
	}

	public function testADeletedMappingFailsThePushRatherThanRelocatingToGeneral(): void {
		// The file still points at a mapping that has since been removed. Rather than
		// silently omit folderUid (which would move the dashboard to General), the push
		// must fail — and never stamp — so it retries once the mapping is restored.
		$this->metadata->method('read')->willReturn($this->managed('d1', Mapping::MODE_SYNC, 'map-gone'));
		$this->mappings->method('getById')->willReturn(null); // mapping deleted
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('write');

		$this->expectException(\RuntimeException::class);
		$this->service->push($this->file(1, '{"uid":"d1","title":"X"}'));
	}
}
