<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Support;

use GuzzleHttp\Client;
use PHPUnit\Framework\Assert;

/**
 * Grafana REST transport (Guzzle, Authorization: Bearer): the assertion side —
 * did the app actually create / update / delete the dashboard or folder in
 * Grafana? Composed into {@see \OCA\GrafanaSync\Tests\Integration\FeatureContext};
 * reads the shared `$grafana` client + `$grafanaUrl` / `$grafanaToken`.
 *
 * POC surface: only the reads the connection scenarios need. The dashboard/folder
 * CRUD assertions grow here alongside the sync chapters.
 */
trait GrafanaApiTrait {
	private function grafanaClient(): Client {
		if ($this->grafana === null) {
			Assert::assertNotSame('', $this->grafanaToken, 'GRAFANA_TOKEN is not set — Grafana assertions need it');
			$this->grafana = new Client([
				'base_uri' => $this->grafanaUrl . '/api/',
				'headers' => [
					'Authorization' => 'Bearer ' . $this->grafanaToken,
					'Accept' => 'application/json',
				],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->grafana;
	}

	/**
	 * List the folders the token can see (the sync's mapping unit). Returns the
	 * decoded array; asserts a 200 so a bad token fails loudly.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function grafanaListFolders(): array {
		$res = $this->grafanaClient()->request('GET', 'folders', ['query' => ['limit' => 1000]]);
		Assert::assertSame(200, $res->getStatusCode(), 'GET Grafana folders failed: ' . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		return is_array($decoded) ? array_values($decoded) : [];
	}

	/** GET a Grafana dashboard by uid; returns the decoded body or null on 404. */
	private function grafanaGetDashboard(string $uid): ?array {
		$res = $this->grafanaClient()->request('GET', 'dashboards/uid/' . rawurlencode($uid));
		if ($res->getStatusCode() === 404) {
			return null;
		}
		Assert::assertSame(200, $res->getStatusCode(), "GET Grafana dashboard $uid failed: " . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Create (or overwrite) a minimal Grafana dashboard by uid in a folder — the
	 * control-case setup a prune scenario needs (a throwaway dashboard the app then
	 * sees leave). Straight through Grafana's own API, not the app under test.
	 */
	private function grafanaCreateDashboard(string $uid, string $title, string $folderUid): void {
		$body = json_encode([
			'dashboard' => ['uid' => $uid, 'title' => $title, 'schemaVersion' => 39, 'panels' => []],
			'folderUid' => $folderUid,
			'overwrite' => true,
			'message' => 'integration prune-case setup',
		], JSON_THROW_ON_ERROR);
		$res = $this->grafanaClient()->request('POST', 'dashboards/db', ['body' => $body]);
		Assert::assertSame(200, $res->getStatusCode(), "create Grafana dashboard $uid failed: " . (string)$res->getBody());
	}

	/** Delete a Grafana dashboard by uid. 200 = gone; 404 = already gone. */
	private function grafanaDeleteDashboard(string $uid): void {
		$res = $this->grafanaClient()->request('DELETE', 'dashboards/uid/' . rawurlencode($uid));
		Assert::assertContains(
			$res->getStatusCode(),
			[200, 404],
			"DELETE Grafana dashboard $uid failed: " . (string)$res->getBody(),
		);
	}
}
