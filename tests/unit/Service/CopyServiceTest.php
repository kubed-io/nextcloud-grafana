<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\CopyService;
use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\OwnershipTags;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
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
	private OwnershipTags $tags;
	private CopyService $service;

	protected function setUp(): void {
		$this->create = $this->createMock(CreateService::class);
		$this->mappings = $this->createStub(MappingService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->tags = $this->createMock(OwnershipTags::class);
		$this->service = new CopyService($this->create, $this->mappings, $this->metadata, $this->tags, new SyncGuard(), new NullLogger());
	}

	private function mapping(string $mode = Mapping::MODE_SYNC): Mapping {
		return Mapping::fromArray(['grafana_folder_uid' => 'gf-demo', 'nc_folder' => 'demo', 'mode' => $mode]);
	}

	private function file(int $id = 1): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn('/demo/Copy.grafana.json');
		return $node;
	}

	public function testACopyOutsideAnyMappingStripsIdentityAndDoesNotCreate(): void {
		$this->mappings->method('resolveForPath')->willReturn(null);
		$this->metadata->expects(self::once())->method('clear')->with(1);
		$this->tags->expects(self::once())->method('clear')->with(1);
		$this->create->expects(self::never())->method('createForFile');

		$this->service->onCopy($this->file(1));
	}

	public function testACopyIntoALinkFolderStripsIdentityButDoesNotCreate(): void {
		// A link folder is for read-only pointers — a copy there is not authored. Both
		// halves of stripIdentity must still run (metadata + ownership pill cleared).
		$this->mappings->method('resolveForPath')->willReturn($this->mapping(Mapping::MODE_LINK));
		$this->metadata->expects(self::once())->method('clear')->with(1);
		$this->tags->expects(self::once())->method('clear')->with(1);
		$this->create->expects(self::never())->method('createForFile');

		$this->service->onCopy($this->file(1));
	}

	public function testACopyIntoASyncFolderStripsIdentityThenCreatesFresh(): void {
		$mapping = $this->mapping(Mapping::MODE_SYNC);
		$this->mappings->method('resolveForPath')->willReturn($mapping);
		$this->metadata->expects(self::once())->method('clear')->with(1);
		$this->tags->expects(self::once())->method('clear')->with(1);
		// Identity is wiped first, so the created dashboard gets a brand-new uid.
		$this->create->expects(self::once())->method('createForFile')->with(self::isInstanceOf(File::class), $mapping);

		$this->service->onCopy($this->file(1));
	}
}
