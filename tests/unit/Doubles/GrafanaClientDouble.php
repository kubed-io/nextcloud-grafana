<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Doubles;

use OCA\GrafanaSync\Service\GrafanaClient;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * A {@see GrafanaClient} double whose {@see GrafanaClient::writeDocument()} is REAL.
 *
 * `writeDocument()` is the one door every write goes through, and it decides which
 * wire a body takes: a classic body to {@see GrafanaClient::upsertDashboard()}, a
 * version-2 resource to {@see GrafanaClient::putDashboardResource()}. Keeping it real
 * means a service test still asserts on the request that reaches the wire — the
 * `/api/dashboards/db` envelope its version-1 assertions were written against, or the
 * resource body — rather than on an argument nobody sends.
 *
 * Every other public method is mocked, exactly as `createMock()` would.
 */
trait GrafanaClientDouble {
	/** @return GrafanaClient&MockObject */
	protected function grafanaClientDouble(): GrafanaClient {
		$mocked = [];
		foreach ((new \ReflectionClass(GrafanaClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if (!$method->isStatic() && !$method->isConstructor() && $method->getName() !== 'writeDocument') {
				$mocked[] = $method->getName();
			}
		}
		return $this->getMockBuilder(GrafanaClient::class)
			->disableOriginalConstructor()
			->onlyMethods($mocked)
			->getMock();
	}
}
