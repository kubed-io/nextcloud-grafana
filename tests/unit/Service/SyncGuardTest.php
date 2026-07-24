<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\SyncGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see SyncGuard} is the loop-prevention core — the pull's own file writes must not
 * trip the save listener into pushing them back. Everything hinges on the depth
 * counter being decremented even when the wrapped callable throws, so these pin that
 * exact contract (including nesting) against a future tweak reintroducing a loop or
 * sticking the guard permanently active.
 */
#[CoversClass(SyncGuard::class)]
final class SyncGuardTest extends TestCase {
	public function testInactiveByDefault(): void {
		self::assertFalse((new SyncGuard())->active());
	}

	public function testEnterLeaveTogglesActive(): void {
		$g = new SyncGuard();
		$g->enter();
		self::assertTrue($g->active());
		$g->leave();
		self::assertFalse($g->active());
	}

	public function testLeaveNeverGoesNegative(): void {
		$g = new SyncGuard();
		$g->leave(); // underflow guard — stays at 0
		self::assertFalse($g->active());
		$g->enter();
		self::assertTrue($g->active());
	}

	public function testNestingStaysActiveUntilFullyUnwound(): void {
		$g = new SyncGuard();
		$g->enter();
		$g->enter();
		self::assertTrue($g->active());
		$g->leave();
		self::assertTrue($g->active(), 'still active after one of two leaves');
		$g->leave();
		self::assertFalse($g->active());
	}

	public function testRunIsActiveInsideAndFalseAfter(): void {
		$g = new SyncGuard();
		$seenInside = null;
		$result = $g->run(function () use ($g, &$seenInside) {
			$seenInside = $g->active();
			return 'ok';
		});
		self::assertTrue($seenInside, 'guard active inside run()');
		self::assertFalse($g->active(), 'guard released after run()');
		self::assertSame('ok', $result);
	}

	public function testRunReleasesEvenWhenTheCallableThrows(): void {
		$g = new SyncGuard();
		try {
			$g->run(function (): void {
				throw new \RuntimeException('boom');
			});
			self::fail('exception should propagate');
		} catch (\RuntimeException $e) {
			self::assertSame('boom', $e->getMessage());
		}
		self::assertFalse($g->active(), 'finally released the guard despite the throw');
	}
}
