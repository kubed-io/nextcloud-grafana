<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ManagedFile} — the typed view of a dashboard file's
 * Files-Metadata that {@see DashboardMetadata::read()} returns. Pure value object,
 * so it runs in the standalone unit suite. These pin the exact predicates the
 * lifecycle call sites (pull/push/move) will rely on.
 */
#[CoversClass(ManagedFile::class)]
final class ManagedFileTest extends TestCase {
	private function make(string $uid, string $mode = '', string $folderUid = '', string $apiVersion = ''): ManagedFile {
		return new ManagedFile($uid, $mode, 'v3', 'abc123', 'map-alpha', $folderUid, $apiVersion);
	}

	public function testEmptyUidIsNotManaged(): void {
		$mf = new ManagedFile('', '', '', '', '', '', '');
		self::assertFalse($mf->isManaged());
	}

	public function testNonEmptyUidIsManaged(): void {
		$mf = $this->make('kel4vkt', Mapping::MODE_SYNC, 'nc-alpha', 'v1beta1');
		self::assertTrue($mf->isManaged());
		self::assertSame('kel4vkt', $mf->uid);
		self::assertSame('v3', $mf->version);
		self::assertSame('abc123', $mf->syncedHash);
		self::assertSame('map-alpha', $mf->mappingId);
		self::assertSame('nc-alpha', $mf->folderUid);
		self::assertSame('v1beta1', $mf->apiVersion);
	}

	#[DataProvider('modeCases')]
	public function testModePredicates(string $mode, string $expectedTrue): void {
		$mf = $this->make('kel4vkt', $mode);
		$flags = [
			'sync' => $mf->isSync(),
			'link' => $mf->isLink(),
			'unmapped' => $mf->isUnmapped(),
			'ignored' => $mf->isIgnored(),
		];
		foreach ($flags as $name => $value) {
			self::assertSame($name === $expectedTrue, $value, "isMode($name) for mode=$mode");
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function modeCases(): iterable {
		yield 'sync' => [Mapping::MODE_SYNC, 'sync'];
		yield 'link' => [Mapping::MODE_LINK, 'link'];
		yield 'unmapped' => [DashboardMetadata::MODE_UNMAPPED, 'unmapped'];
		yield 'ignored' => [DashboardMetadata::MODE_IGNORED, 'ignored'];
	}

	public function testEmptyModeMatchesNoPredicate(): void {
		// A managed file whose mode was never stamped reads back as '' and matches
		// none of the mode predicates.
		$mf = $this->make('kel4vkt', '');
		self::assertFalse($mf->isSync());
		self::assertFalse($mf->isLink());
		self::assertFalse($mf->isUnmapped());
		self::assertFalse($mf->isIgnored());
	}
}
