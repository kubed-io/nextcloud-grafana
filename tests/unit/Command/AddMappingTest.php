<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Command;

use OCA\GrafanaSync\Command\AddMapping;
use OCA\GrafanaSync\Exception\ExistingDashboardsException;
use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ grafana_sync:add-mapping` — every refusal reads as one error line and a non-zero
 * exit, whichever exception family it comes from. `InvalidArgumentException` is a
 * LogicException, not a RuntimeException, so catching only the latter let every refusal
 * escape as a raw console exception (found in review).
 */
#[CoversClass(AddMapping::class)]
final class AddMappingTest extends TestCase {
	private function runCommand(\Throwable|Mapping $outcome, string $json = '{"grafana_folder_uid":"apps","nc_folder":"apps","version":2}'): CommandTester {
		$service = $this->createStub(MappingService::class);
		if ($outcome instanceof \Throwable) {
			$service->method('add')->willThrowException($outcome);
		} else {
			$service->method('add')->willReturn($outcome);
		}
		$tester = new CommandTester(new AddMapping($service));
		$tester->execute(['json' => $json]);
		return $tester;
	}

	/** @return iterable<string, array{\Throwable}> */
	public static function refusals(): iterable {
		yield 'a refusal' => [new \InvalidArgumentException('version must be 1 or 2')];
		yield 'the purge question' => [new ExistingDashboardsException('"apps" already holds 18 dashboard files.', 18, 'apps')];
		yield 'Grafana could not be asked' => [new GrafanaApiException('Bad gateway', 502)];
		yield 'no token' => [new \RuntimeException('No Grafana service-account token is set — add one first.')];
	}

	#[DataProvider('refusals')]
	public function testEveryRefusalIsOneErrorLine(\Throwable $e): void {
		$tester = $this->runCommand($e);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString($e->getMessage(), $tester->getDisplay());
	}

	public function testTheSuccessLineNamesModeAndVersion(): void {
		$saved = Mapping::fromArray(['id' => 'm1', 'grafana_folder_uid' => 'apps', 'nc_folder' => 'apps', 'mode' => 'sync', 'version' => 2]);

		$tester = $this->runCommand($saved);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('sync, version 2', $tester->getDisplay());
	}
}
