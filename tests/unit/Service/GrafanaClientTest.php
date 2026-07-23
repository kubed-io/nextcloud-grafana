<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCA\GrafanaSync\Service\GrafanaClient;
use PHPUnit\Framework\TestCase;

/**
 * {@see GrafanaClient::describeConnectionError} — the one formatter behind both the
 * Test connection button and the occ command. Its whole job is to keep the two
 * failure classes distinct: a token that isn't set vs. one that was set and
 * rejected. The 401 case doubly guards a regression: GrafanaApiException is a
 * RuntimeException subclass, so a naive `catch (RuntimeException)` would show
 * Grafana's raw text instead of a clear "rejected".
 */
final class GrafanaClientTest extends TestCase {
	public function testDescribesAMissingTokenAsSetupNotRejection(): void {
		$msg = GrafanaClient::describeConnectionError(
			new \RuntimeException('No Grafana service-account token is set — add one first.'),
		);
		self::assertStringContainsString('add one first', $msg);
		self::assertStringNotContainsStringIgnoringCase('rejected', $msg);
	}

	public function testDescribesA401AsARejectedToken(): void {
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('Invalid API key', 401));
		self::assertStringContainsString('401', $msg);
		self::assertStringContainsStringIgnoringCase('rejected', $msg);
		// The raw upstream text must NOT be what the user sees for an auth failure.
		self::assertStringNotContainsString('Invalid API key', $msg);
	}

	public function testDescribesA403AsARejectedToken(): void {
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('Forbidden', 403));
		self::assertStringContainsStringIgnoringCase('rejected', $msg);
	}

	public function testDescribesA404AsABaseUrlProblem(): void {
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('not found', 404));
		self::assertStringContainsStringIgnoringCase('base url', $msg);
	}

	public function testDescribesATransportErrorAsUnreachable(): void {
		// httpStatus 0 = no response at all — genuinely "could not reach".
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('connection refused', 0));
		self::assertStringContainsStringIgnoringCase('could not reach', $msg);
	}

	public function testDescribesA500AsAReachedHttpErrorNotUnreachable(): void {
		// Grafana WAS reached and returned 500 — must not claim "could not reach".
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('internal error', 500));
		self::assertStringContainsString('500', $msg);
		self::assertStringNotContainsStringIgnoringCase('could not reach', $msg);
	}
}
