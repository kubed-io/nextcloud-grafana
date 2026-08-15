<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Settings;

use OCA\GrafanaSync\Settings\InstanceSettings;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * The single Instance card carries both the base URL and the service-account token
 * (Grafana has one API + one credential, so no split). The token field renders its
 * copy from whether a token is stored — the only reliable "is it set?" signal, since
 * a sensitive field itself always shows blank. These lock the merged shape + that
 * the two token states read differently without weakening the field.
 */
final class InstanceSettingsTest extends TestCase {
	/** @return array<string,mixed> the field with the given id */
	private function field(bool $hasToken, string $id): array {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($hasToken ? 'encrypted-blob' : '');
		$schema = (new InstanceSettings($config, $this->createStub(ICrypto::class)))->getSchema();
		foreach ($schema['fields'] as $field) {
			if (($field['id'] ?? null) === $id) {
				return $field;
			}
		}
		self::fail("field '$id' not found in the Instance schema");
	}

	public function testTheCardHoldsBothTheUrlAndTheToken(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$schema = (new InstanceSettings($config, $this->createStub(ICrypto::class)))->getSchema();
		self::assertSame('instance', $schema['id']);
		$ids = array_map(static fn ($f) => $f['id'], $schema['fields']);
		self::assertContains('grafana_url', $ids);
		self::assertContains('grafana_token', $ids);
	}

	public function testTheTokenStaysASensitivePasswordFieldEitherWay(): void {
		foreach ([true, false] as $hasToken) {
			$token = $this->field($hasToken, 'grafana_token');
			self::assertTrue($token['sensitive']);
		}
	}

	public function testSignalsWhenNoTokenIsStored(): void {
		$token = $this->field(false, 'grafana_token');
		self::assertStringNotContainsStringIgnoringCase('currently stored', $token['description']);
		self::assertStringContainsStringIgnoringCase('paste the grafana', $token['placeholder']);
	}

	public function testSignalsWhenATokenIsStored(): void {
		$token = $this->field(true, 'grafana_token');
		self::assertStringContainsStringIgnoringCase('currently stored', $token['description']);
		// The placeholder carries a masked hint that a value is present.
		self::assertStringContainsString('•', $token['placeholder']);
	}
}
