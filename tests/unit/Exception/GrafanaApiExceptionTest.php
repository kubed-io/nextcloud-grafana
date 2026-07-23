<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Exception;

use OCA\GrafanaSync\Exception\GrafanaApiException;
use PHPUnit\Framework\TestCase;

/**
 * The exception is the app's only cooked non-trivial value type in the POC: it
 * carries Grafana's own error text plus the HTTP status so callers can render a
 * friendly message. These assertions lock that contract.
 */
final class GrafanaApiExceptionTest extends TestCase {
	public function testCarriesMessageAndStatus(): void {
		$e = new GrafanaApiException('Unauthorized', 401);
		self::assertSame('Unauthorized', $e->getMessage());
		self::assertSame(401, $e->httpStatus);
	}

	public function testStatusDefaultsToZeroForTransportErrors(): void {
		$e = new GrafanaApiException('connection refused');
		self::assertSame(0, $e->httpStatus);
	}

	public function testIsARuntimeExceptionSoBroadCatchesStillWork(): void {
		$e = new GrafanaApiException('boom', 500);
		self::assertInstanceOf(\RuntimeException::class, $e);
	}

	public function testPreservesPreviousForDebugging(): void {
		$prev = new \RuntimeException('root cause');
		$e = new GrafanaApiException('wrapped', 502, $prev);
		self::assertSame($prev, $e->getPrevious());
	}
}
