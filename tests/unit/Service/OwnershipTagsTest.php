<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\OwnershipTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see OwnershipTags::tagFor} — the pure mode → pill mapping the
 * pull stamps on every managed file: `sync` → `grafana:sync`, `link` → `grafana:link`,
 * `unmapped` → `grafana:unmapped`. `ignored` (a user-set exclude, not one of our
 * auto-managed pills) and any unknown value must throw rather than silently mis-tag.
 */
#[CoversClass(OwnershipTags::class)]
final class OwnershipTagsTest extends TestCase {
	public function testSyncMode(): void {
		self::assertSame('grafana:sync', OwnershipTags::tagFor(Mapping::MODE_SYNC));
	}

	public function testLinkMode(): void {
		self::assertSame('grafana:link', OwnershipTags::tagFor(Mapping::MODE_LINK));
	}

	public function testUnmappedMode(): void {
		self::assertSame('grafana:unmapped', OwnershipTags::tagFor(DashboardMetadata::MODE_UNMAPPED));
	}

	#[DataProvider('unknownModeProvider')]
	public function testUnknownModeThrows(string $mode): void {
		$this->expectException(\InvalidArgumentException::class);
		OwnershipTags::tagFor($mode);
	}

	/** @return array<string,array{0:string}> */
	public static function unknownModeProvider(): array {
		return [
			'ignored has no auto pill' => [DashboardMetadata::MODE_IGNORED],
			'wire-only reference' => ['reference'],
			'no such backup mode' => ['backup'],
			'empty' => [''],
		];
	}
}
