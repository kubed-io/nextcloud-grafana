<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Settings;

use OCA\GrafanaSync\Settings\ConnectionSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The Connection card renders its copy from whether a token is stored — the only
 * reliable "is it set?" signal, since a sensitive field itself always shows blank.
 * These lock that the two states read differently without weakening the field
 * (still a sensitive PASSWORD either way).
 */
final class ConnectionSettingsTest extends TestCase {
	/** @return array<string,mixed> the single token field's schema */
	private function tokenField(bool $hasToken): array {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($hasToken ? 'encrypted-blob' : '');
		$schema = (new ConnectionSettings($config))->getSchema();
		return $schema['fields'][0];
	}

	public function testStaysASensitivePasswordFieldEitherWay(): void {
		foreach ([true, false] as $hasToken) {
			$field = $this->tokenField($hasToken);
			self::assertSame('grafana_token', $field['id']);
			self::assertTrue($field['sensitive']);
		}
	}

	public function testSignalsWhenNoTokenIsStored(): void {
		$field = $this->tokenField(false);
		self::assertStringContainsStringIgnoringCase('no token', $field['description']);
		self::assertStringContainsStringIgnoringCase('paste the grafana', $field['placeholder']);
	}

	public function testSignalsWhenATokenIsStored(): void {
		$field = $this->tokenField(true);
		self::assertStringContainsStringIgnoringCase('stored', $field['description']);
		// The placeholder carries a masked hint that a value is present.
		self::assertStringContainsString('•', $field['placeholder']);
	}
}
