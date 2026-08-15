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
	 * Every write Grafana recorded for a dashboard, oldest first, as
	 * `v1 at 04:08:19 "Initial save"`.
	 *
	 * DIAGNOSTIC ONLY — never assert on it. A clock disagreement ("the file is dated
	 * X, the dashboard changed at Y") has two causes that look identical in the
	 * message and want opposite fixes: the app never stamped the file, or it stamped
	 * it and then wrote to Grafana a SECOND time, moving the clock out from under a
	 * correct stamp. The version list separates them in one glance — and a CI run is
	 * gone by the time anyone could go and ask Grafana themselves.
	 *
	 * @return list<string>
	 */
	private function grafanaDashboardWrites(string $uid): array {
		$res = $this->grafanaClient()->request('GET', 'dashboards/uid/' . rawurlencode($uid) . '/versions');
		if ($res->getStatusCode() !== 200) {
			return [];
		}
		$decoded = json_decode((string)$res->getBody(), true);
		// Grafana 11+ answers {versions: [...]}, older ones a bare list.
		$rows = is_array($decoded['versions'] ?? null) ? $decoded['versions'] : $decoded;
		if (!is_array($rows)) {
			return [];
		}
		$writes = [];
		foreach (array_reverse(array_values($rows)) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$at = strtotime((string)($row['created'] ?? ''));
			$writes[] = 'v' . (string)($row['version'] ?? '?')
				. ' at ' . (is_int($at) ? gmdate('H:i:s', $at) : '?')
				. ' "' . (string)($row['message'] ?? '') . '"';
		}
		return $writes;
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
		$res = $this->grafanaClient()->request('POST', 'dashboards/db', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => $body,
		]);
		Assert::assertSame(200, $res->getStatusCode(), "create Grafana dashboard $uid failed: " . (string)$res->getBody());
	}

	/**
	 * Create (or overwrite) a dashboard carrying tags — the seed a tag-import
	 * assertion needs. Separate from {@see grafanaCreateDashboard()} rather than an
	 * extra parameter on it, because every existing caller wants the untagged shape
	 * and an optional array would make the common case read as if tags mattered.
	 *
	 * @param list<string> $tags
	 */
	private function grafanaCreateTaggedDashboard(string $uid, string $title, string $folderUid, array $tags): void {
		$body = json_encode([
			'dashboard' => [
				'uid' => $uid,
				'title' => $title,
				'schemaVersion' => 39,
				'panels' => [],
				'tags' => array_values($tags),
			],
			'folderUid' => $folderUid,
			'overwrite' => true,
			'message' => 'integration tag-case setup',
		], JSON_THROW_ON_ERROR);
		$res = $this->grafanaClient()->request('POST', 'dashboards/db', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => $body,
		]);
		Assert::assertSame(200, $res->getStatusCode(), "create tagged Grafana dashboard $uid failed: " . (string)$res->getBody());
	}

	/**
	 * A Grafana FOLDER's tags, which live in an annotation on the app-platform API
	 * rather than anywhere the legacy `/api/folders` surface can see.
	 *
	 * The assertion side deliberately re-derives nothing: it reads the same annotation
	 * the app writes, through Grafana's own API, so a test passes only if the value
	 * really landed on the object.
	 *
	 * @return list<string>
	 */
	private function grafanaFolderTags(string $uid): array {
		$res = $this->grafanaClient()->request('GET', $this->folderResourcePath($uid), [
			'http_errors' => false,
		]);
		// A 404 IS A BROKEN ARRANGE, NOT AN ANSWER. Returning an empty set here would
		// satisfy any "the tags are X" assertion whose X happens to be empty, on a
		// folder that does not exist — the same trap the app's own readFolderTags was
		// changed to avoid, and it belongs on the assertion side too.
		Assert::assertNotSame(
			404,
			$res->getStatusCode(),
			"Grafana has no folder '$uid' — the scenario's arrange did not create what it asserts on",
		);
		Assert::assertSame(200, $res->getStatusCode(), "GET Grafana folder $uid failed: " . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		$raw = $decoded['metadata']['annotations']['nextcloud.kubed.io/tags'] ?? '';
		return $this->splitTags(is_string($raw) ? $raw : '');
	}

	/**
	 * Put tags on a Grafana folder, as a person with API access would.
	 *
	 * A merge patch, so `grafana.app/*` — the internal ids and audit fields Grafana
	 * maintains — is untouched. A PUT would carry the whole metadata map and take
	 * them with it.
	 *
	 * @param list<string> $tags
	 */
	private function grafanaSetFolderTags(string $uid, array $tags): void {
		$value = implode(', ', array_values($tags));
		$body = json_encode([
			'metadata' => ['annotations' => [
				'nextcloud.kubed.io/tags' => $value === '' ? null : $value,
			]],
		], JSON_THROW_ON_ERROR);
		$res = $this->grafanaClient()->request('PATCH', $this->folderResourcePath($uid), [
			'headers' => ['Content-Type' => 'application/merge-patch+json'],
			'body' => $body,
		]);
		Assert::assertSame(200, $res->getStatusCode(), "PATCH Grafana folder $uid tags failed: " . (string)$res->getBody());
	}

	/** The app-platform path for one folder, relative to the client's `/api/` base. */
	private function folderResourcePath(string $uid): string {
		// The Grafana client is based at `<url>/api/`, and this resource sits outside
		// that prefix — hence the climb. Kept in one place so the oddity is explained
		// once rather than puzzled over at three call sites.
		return '../apis/folder.grafana.app/v1beta1/namespaces/default/folders/' . rawurlencode($uid);
	}

	/**
	 * Split a comma-joined tag string the way the app does: trimmed, empties dropped.
	 *
	 * @return list<string>
	 */
	private function splitTags(string $joined): array {
		$out = [];
		foreach (explode(',', $joined) as $tag) {
			$trimmed = trim($tag);
			if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
				$out[] = $trimmed;
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * Create a Grafana folder under a parent, as a person in Grafana would. Returns
	 * the uid Grafana minted — a folder cannot be created with a chosen one.
	 */
	private function grafanaCreateFolder(string $title, string $parentUid): string {
		$res = $this->grafanaClient()->request('POST', 'folders', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode(['title' => $title, 'parentUid' => $parentUid], JSON_THROW_ON_ERROR),
		]);
		Assert::assertSame(200, $res->getStatusCode(), "create Grafana folder '$title' failed: " . (string)$res->getBody());
		$decoded = json_decode((string)$res->getBody(), true);
		$uid = is_array($decoded) ? (string)($decoded['uid'] ?? '') : '';
		Assert::assertNotSame('', $uid, "Grafana returned no uid for folder '$title'");
		return $uid;
	}

	/** Delete a Grafana folder by uid. 200 = gone; 404 = already gone. Best-effort teardown. */
	private function grafanaDeleteFolder(string $uid): void {
		$this->grafanaClient()->request('DELETE', 'folders/' . rawurlencode($uid), ['http_errors' => false]);
	}

	/** Delete a Grafana dashboard by uid. 200 = gone; 404 = already gone. */	/** Delete a Grafana dashboard by uid. 200 = gone; 404 = already gone. */
	private function grafanaDeleteDashboard(string $uid): void {
		$res = $this->grafanaClient()->request('DELETE', 'dashboards/uid/' . rawurlencode($uid));
		Assert::assertContains(
			$res->getStatusCode(),
			[200, 404],
			"DELETE Grafana dashboard $uid failed: " . (string)$res->getBody(),
		);
	}
}
