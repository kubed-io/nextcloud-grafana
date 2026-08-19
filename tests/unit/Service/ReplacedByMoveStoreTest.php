<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\ReplacedByMoveStore;
use PHPUnit\Framework\TestCase;

/**
 * The store is a two-array bookkeeper, but the two arrays answer different questions
 * and conflating them is the bug worth pinning: SUPPRESSING the delete and ADOPTING an
 * identity are separate outcomes, and one overwrite can need the first without the
 * second.
 */
final class ReplacedByMoveStoreTest extends TestCase {
	public function testAMarkSuppressesTheDeleteOfTheReplacedFileOnly(): void {
		$store = new ReplacedByMoveStore();
		$store->mark(10, 20, 'dash-kept');

		self::assertTrue($store->isReplaced(10));
		// THE MOVING FILE IS NOT THE REPLACED ONE. If this answered true, a genuine
		// delete of the arriving file later in the same request would be swallowed.
		self::assertFalse($store->isReplaced(20));
		self::assertFalse($store->isReplaced(30));
	}

	public function testTheAdoptionIsKeyedByTheMovingFile(): void {
		$store = new ReplacedByMoveStore();
		$store->mark(10, 20, 'dash-kept');

		self::assertSame('dash-kept', $store->adoptedUid(20));
		self::assertNull($store->adoptedUid(10));
		self::assertNull($store->adoptedUid(99));
	}

	public function testReplacingAFileThatIsNotOursSuppressesWithoutAdopting(): void {
		// A dashboard file with no stamp — or one whose stamp could not be read, which
		// the plugin deliberately treats the same way. There is nothing to inherit, and
		// the arrival must keep the identity it came with rather than being bound to ''.
		$store = new ReplacedByMoveStore();
		$store->mark(10, 20, '');

		self::assertTrue($store->isReplaced(10));
		self::assertNull($store->adoptedUid(20));
	}

	public function testAnUnmarkedRequestChangesNothing(): void {
		$store = new ReplacedByMoveStore();

		self::assertFalse($store->isReplaced(1));
		self::assertNull($store->adoptedUid(1));
	}
}
