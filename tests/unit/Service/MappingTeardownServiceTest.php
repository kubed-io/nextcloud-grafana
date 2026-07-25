<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MappingTeardownService;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\StorageService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see MappingTeardownService} — removing a mapping trashes ONLY its connected
 * files and leaves everything else strictly alone, and a partial failure keeps the binding for
 * a retry rather than stranding a still-managed file.
 */
#[CoversClass(MappingTeardownService::class)]
final class MappingTeardownServiceTest extends TestCase {
	private const ID = 'm-1';

	private MappingService $mappings;
	private StorageService $storage;
	private DashboardMetadata $metadata;
	private MappingTeardownService $service;

	protected function setUp(): void {
		$this->mappings = $this->createMock(MappingService::class);
		$this->storage = $this->createMock(StorageService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->service = new MappingTeardownService($this->mappings, $this->storage, $this->metadata, new NullLogger());
	}

	private function mapping(): Mapping {
		return Mapping::fromArray(['id' => self::ID, 'grafana_folder_uid' => 'gf', 'nc_folder' => 'alpha', 'mode' => 'sync']);
	}

	private function dashFile(int $id): File {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn('D' . $id . '.grafana.json');
		$f->method('getId')->willReturn($id);
		$f->method('getPath')->willReturn('/alice/files/alpha/D' . $id . '.grafana.json');
		return $f;
	}

	private function managed(string $mappingId, string $uid = 'dash'): ManagedFile {
		return new ManagedFile($uid, 'sync', '1', 'h', $mappingId, '', '');
	}

	public function testTrashesOnlyConnectedFilesThenDropsTheBinding(): void {
		$connected = $this->dashFile(1);   // managed, our mapping → trashed
		$other = $this->dashFile(2);       // managed, a DIFFERENT mapping → left alone
		$loose = $this->dashFile(3);       // unmanaged standalone → left alone
		$plain = $this->createMock(File::class);
		$plain->method('getName')->willReturn('notes.txt'); // not a dashboard file → ignored

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$connected, $other, $loose, $plain]);
		$this->mappings->method('getById')->with(self::ID)->willReturn($this->mapping());
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturnMap([
			[1, $this->managed(self::ID)],
			[2, $this->managed('m-other')],
			[3, null],
		]);

		$connected->expects(self::once())->method('delete');
		$other->expects(self::never())->method('delete');
		$loose->expects(self::never())->method('delete');
		$plain->expects(self::never())->method('delete');
		$this->mappings->expects(self::once())->method('delete')->with(self::ID);

		$this->service->remove(self::ID);
	}

	public function testAPartialFailureKeepsTheMappingAndAttemptsEveryFile(): void {
		$bad = $this->dashFile(1);   // its delete throws (e.g. the delete listener aborted)
		$good = $this->dashFile(2);  // still attempted despite the earlier failure

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$bad, $good]);
		$this->mappings->method('getById')->willReturn($this->mapping());
		$this->storage->method('findFolder')->willReturn($folder);
		$this->metadata->method('read')->willReturnMap([
			[1, $this->managed(self::ID)],
			[2, $this->managed(self::ID)],
		]);

		$bad->method('delete')->willThrowException(new \RuntimeException('grafana unreachable'));
		$good->expects(self::once())->method('delete'); // the walk continues past the failure
		$this->mappings->expects(self::never())->method('delete'); // binding kept for retry

		$this->expectException(\RuntimeException::class);
		$this->service->remove(self::ID);
	}

	public function testAnUnknownMappingThrowsOutOfBounds(): void {
		$this->mappings->method('getById')->willReturn(null);
		$this->storage->expects(self::never())->method('findFolder');
		$this->mappings->expects(self::never())->method('delete');

		$this->expectException(\OutOfBoundsException::class);
		$this->service->remove('nope');
	}
}
