<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see CreateService} — create-on-land (Course 4 · Slice 1). These pin
 * the decisions that make "make a file → it's a dashboard" safe:
 *
 *  - the file body is upserted with the mapping's folder placement and id forced null;
 *  - the returned uid is stamped (with mode + version), the pill applied, all under the
 *    SyncGuard so the stamp doesn't echo into the writeback listener;
 *  - the loop-guard hash is the ORIGINAL file bytes (so a re-save is a no-op);
 *  - an empty body is tolerated (Grafana mints a minimal dashboard); malformed JSON and
 *    a uid-less response both fail loudly (the listener notifies).
 */
#[CoversClass(CreateService::class)]
final class CreateServiceTest extends TestCase {
	private GrafanaClient $grafana;
	private DashboardMetadata $metadata;
	private CreateService $service;

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$mimeLoader = $this->createStub(IMimeTypeLoader::class);
		$mimeLoader->method('getId')->willReturn(1);
		// A real SyncGuard: run() executes the callback (brackets it in enter/leave).
		$folderMirror = $this->createStub(FolderMirror::class);
		$folderMirror->method('folderUidFor')->willReturnCallback(
			static fn (Node $n, Mapping $m): ?string
				=> $m->grafanaFolderUid === '/' ? null : $m->grafanaFolderUid,
		);
		$this->service = new CreateService($this->grafana, $this->metadata, $folderMirror, new SyncGuard(), $mimeLoader, new NullLogger());
	}

	private function mapping(string $folderUid = 'gf-demo', string $id = 'map-demo'): Mapping {
		return Mapping::fromArray(['id' => $id, 'grafana_folder_uid' => $folderUid, 'nc_folder' => 'demo', 'mode' => 'sync']);
	}

	private function file(int $id, string $content, string $name = 'My Dash.grafana.json'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getContent')->willReturn($content);
		$node->method('getName')->willReturn($name);
		return $node;
	}

	public function testCreatesUpsertsWithFolderPlacementAndStampsTheReturnedUid(): void {
		$content = '{"title":"My Dash","panels":[]}';

		$captured = null;
		$this->grafana->expects(self::once())->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$captured): array {
				$captured = $body;
				return ['uid' => 'new-uid', 'version' => 1, 'status' => 'success'];
			});

		// Loop-guard hash = sha1 of the ORIGINAL bytes; mode + version stamped.
		$this->metadata->expects(self::once())->method('stampSynced')
			->with(1, 'new-uid', Mapping::MODE_SYNC, '1', $content, 'map-demo');

		$uid = $this->service->createForFile($this->file(1, $content), $this->mapping());

		self::assertSame('new-uid', $uid);
		self::assertSame('gf-demo', $captured['folderUid'] ?? null, 'placed in the mapping folder');
		self::assertTrue($captured['overwrite'] ?? null);
		self::assertNull($captured['dashboard']->id, 'id forced null so Grafana keys on uid');
		self::assertSame('My Dash', $captured['dashboard']->title);
	}

	public function testARootMappingCreatesInGeneralWithoutAFolderUid(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'u', 'version' => 1];
		});

		$this->service->createForFile($this->file(1, '{"title":"X"}'), $this->mapping('/', 'map-root'));

		self::assertArrayNotHasKey('folderUid', $captured, 'General placement omits folderUid');
	}

	public function testAnEmptyBodyIsToleratedAndMintsAMinimalDashboard(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'u', 'version' => 1];
		});

		$uid = $this->service->createForFile($this->file(1, '   '), $this->mapping());

		self::assertSame('u', $uid);
		// title falls back to the filename stem (via toUpsertBody).
		self::assertSame('My Dash', $captured['dashboard']->title);
	}

	public function testMalformedJsonThrowsAndNeverUpserts(): void {
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('stampSynced');

		$this->expectException(\RuntimeException::class);
		$this->service->createForFile($this->file(1, '{not json'), $this->mapping());
	}

	public function testANonObjectJsonBodyThrowsRatherThanCoercingToEmpty(): void {
		// A JSON array or scalar is a malformed dashboard — fail loudly like PushService,
		// never silently mint an empty dashboard from it.
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('stampSynced');

		$this->expectException(\RuntimeException::class);
		$this->service->createForFile($this->file(1, '["not","an","object"]'), $this->mapping());
	}

	public function testAUidLessResponseThrowsAndNeverStamps(): void {
		$this->grafana->method('upsertDashboard')->willReturn(['version' => 1]); // no uid
		$this->metadata->expects(self::never())->method('stampSynced');

		$this->expectException(\RuntimeException::class);
		$this->service->createForFile($this->file(1, '{"title":"X"}'), $this->mapping());
	}
}
