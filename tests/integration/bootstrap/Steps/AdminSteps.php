<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\PyStringNode;
use PHPUnit\Framework\Assert;

/**
 * Admin setup + connection steps: the "admin configures the app and makes the
 * Grafana connection" use case (app config get/set, base URL, service-account
 * token, test-connection). Composed into
 * {@see \OCA\GrafanaSync\Tests\Integration\FeatureContext}.
 */
trait AdminSteps {
	// ── generic admin-config steps (used by mapping scenarios, @todo for now) ──

	/** @When I set app config :key to :value */
	public function iSetAppConfig(string $key, string $value): void {
		$res = $this->occ('config:app:set ' . self::APP_ID . ' ' . escapeshellarg($key) . ' --value=' . escapeshellarg($value));
		Assert::assertSame(0, $res['exit'], "config:app:set $key failed:\n{$res['output']}");
	}

	/**
	 * Multi-line (PyString) form, e.g. for the mappings JSON.
	 *
	 * @When I set app config :key to:
	 */
	public function iSetAppConfigMultiline(string $key, PyStringNode $value): void {
		$this->iSetAppConfig($key, $value->getRaw());
	}

	/** @Then app config :key is :expected */
	public function appConfigIs(string $key, string $expected): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' ' . escapeshellarg($key));
		Assert::assertSame($expected, trim($res['output']), "config $key mismatch");
	}

	/** @Then app config :key contains :needle */
	public function appConfigContains(string $key, string $needle): void {
		$res = $this->occ('config:app:get ' . self::APP_ID . ' ' . escapeshellarg($key));
		Assert::assertStringContainsString($needle, $res['output'], "config $key did not contain '$needle'");
	}

	// ── connection steps (the "admin makes connection" use case) ──────────────

	/** @Given the app is installed and enabled */
	public function theAppIsInstalledAndEnabled(): void {
		$this->occ('app:enable --force ' . self::APP_ID);
	}

	/** @When the admin sets the Grafana base URL */
	public function theAdminSetsTheGrafanaBaseUrl(): void {
		$url = getenv('GRAFANA_URL') ?: 'http://localhost:3000';
		$res = $this->occ('config:app:set ' . self::APP_ID . ' grafana_url --value=' . escapeshellarg($url));
		Assert::assertSame(0, $res['exit'], "setting the base URL failed:\n{$res['output']}");
	}

	/**
	 * Store the real, CI-minted token the way the admin UI does (encrypted).
	 *
	 * @When the admin provides the Grafana service-account token
	 */
	public function theAdminProvidesTheGrafanaToken(): void {
		$token = getenv('GRAFANA_TOKEN') ?: '';
		Assert::assertNotSame('', $token, 'GRAFANA_TOKEN is not set — the test setup must provide it');
		$res = $this->occStdin($this->occ . ' grafana_sync:set-token', $token);
		Assert::assertSame(0, $res['exit'], "providing the token failed:\n{$res['output']}");
	}

	/** @When the admin provides an invalid service-account token */
	public function theAdminProvidesAnInvalidToken(): void {
		$res = $this->occStdin($this->occ . ' grafana_sync:set-token', 'not-a-real-token');
		Assert::assertSame(0, $res['exit'], "storing the (invalid) token failed:\n{$res['output']}");
	}

	/** @Given the admin has set the Grafana base URL */
	public function theAdminHasSetTheGrafanaBaseUrl(): void {
		$this->theAdminSetsTheGrafanaBaseUrl();
	}

	/**
	 * One-line connection setup for feature Backgrounds: app enabled + base URL +
	 * the CI-minted token. The canonical "ready to talk to Grafana" precondition —
	 * Backgrounds say this single line instead of repeating the admin steps (which
	 * {@see admin-connection.feature} still spells out because *that* feature is
	 * what tests the connection flow itself).
	 *
	 * @Given the app is connected to Grafana
	 */
	public function theAppIsConnectedToGrafana(): void {
		$this->theAppIsInstalledAndEnabled();
		$this->theAdminSetsTheGrafanaBaseUrl();
		$this->theAdminProvidesTheGrafanaToken();
	}

	/** @When the admin tests the connection */
	public function theAdminTestsTheConnection(): void {
		$this->occ('grafana_sync:test-connection');
	}

	/** @Then the connection is verified */
	public function theConnectionIsVerified(): void {
		Assert::assertSame(0, $this->lastExit, "the connection test failed:\n{$this->lastOutput}");
	}

	/** @Then the connection test reports a failure */
	public function theConnectionTestReportsAFailure(): void {
		Assert::assertNotSame(0, $this->lastExit, "the connection test unexpectedly succeeded:\n{$this->lastOutput}");
	}
}
