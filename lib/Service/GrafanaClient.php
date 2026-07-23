<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the Grafana HTTP API.
 *
 * One source of truth for:
 *  - reading + decrypting the stored service-account token,
 *  - resolving the configured base URL (trim trailing slash so callers don't have to),
 *  - issuing requests through Nextcloud's IClientService (so HTTP proxying,
 *    `allow_local_address`, and TLS settings stay consistent with the rest of the
 *    platform), and
 *  - normalising error shapes so callers get a small set of typed exceptions.
 *
 * POC surface — only what the admin "Test connection" appetizer needs:
 *
 *   ping()   — cheapest call to verify URL + token (used by the Test connection
 *              button and the occ smoke command).
 *
 * The dashboard/folder read/upsert surface (listFolders / getDashboard /
 * upsertDashboard / deleteDashboard / deep-link) lands in the sync chapters — it
 * rides this same request() chokepoint, so the auth + egress trade-off is decided
 * once, here.
 *
 * Auth: Grafana takes a **service-account token** as `Authorization: Bearer
 * <token>` (unlike n8n's `X-N8N-API-KEY` header). One credential, one envelope.
 */
final class GrafanaClient {
	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Cheapest **authenticated** call — lists the folders the token can see.
	 *
	 * Deliberately NOT `/api/health`: that endpoint is unauthenticated in Grafana
	 * (returns 200 with no token), so it would prove reachability but not that the
	 * token is valid. `/api/folders` requires a working Bearer token — a bad or
	 * missing token returns 401 — so a green result proves the token decrypts, is
	 * accepted, and carries at least folder-read scope (the unit the sync maps on).
	 *
	 * Throws on auth/network failure so the caller decides how to present it.
	 *
	 * @return array{httpStatus:int, message:string}
	 */
	public function ping(): array {
		$res = $this->request('GET', '/api/folders', ['limit' => 1000]);
		$code = $res->getStatusCode();
		$body = $this->decode($res);
		$count = count($body);
		$plural = $count === 1 ? 'folder' : 'folders';
		return [
			'httpStatus' => $code,
			'message' => "Authenticated to Grafana (HTTP $code) — token valid, $count $plural visible.",
		];
	}

	/**
	 * Single chokepoint for every HTTP call. Reads + decrypts the token, applies
	 * the standard headers, and sets `allow_local_address` so the homelab's
	 * in-cluster URLs (e.g. grafana-service.observe.svc:3000) work the same way as
	 * public ones. This opts out of NC's default SSRF guard on purpose — see
	 * SECURITY.md "Network egress and local addresses" for the trade-off
	 * (admin-trust boundary, single Grafana target).
	 *
	 * Throws \RuntimeException with a friendly message for the cases we know how
	 * to label (no URL, no token, decrypt fail, local-address blocked) and lets
	 * transport errors bubble up otherwise so the caller can format them.
	 *
	 * @param array<string,mixed> $query
	 * @param array<string,mixed>|list<array<string,mixed>>|null $jsonBody
	 */
	private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): IResponse {
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'grafana_url', ''), '/');
		$enc = $this->config->getValueString(Application::APP_ID, 'grafana_token', '');
		if ($base === '') {
			throw new \RuntimeException('Set the Grafana base URL first.');
		}
		if ($enc === '') {
			throw new \RuntimeException('Set the service-account token first.');
		}
		try {
			$token = $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			throw new \RuntimeException('Stored token could not be decrypted — re-enter it.');
		}

		$url = $base . $path;
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		$opts = [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			],
			'timeout' => 10,
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($jsonBody !== null) {
			$opts['headers']['Content-Type'] = 'application/json';
			$opts['body'] = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}

		$client = $this->clientService->newClient();
		try {
			return $this->dispatch($client, $method, $url, $opts);
		} catch (LocalServerException $e) {
			throw new \RuntimeException('Refused to connect to a local address.', 0, $e);
		} catch (\Throwable $e) {
			throw $this->toApiException($e);
		}
	}

	/**
	 * Turn a transport/HTTP exception into a {@see GrafanaApiException} carrying
	 * Grafana's own error text. Grafana responds to bad requests with
	 * `{"message": "…"}` (sometimes with `messageId`/`traceID`); we surface that
	 * verbatim so it can be shown to the user. We duck-type the Guzzle exception
	 * (`getResponse()` → PSR-7 response) rather than import it, so we don't couple
	 * to a specific HTTP-client bundle.
	 */
	private function toApiException(\Throwable $e): GrafanaApiException {
		if (is_callable([$e, 'getResponse'])) {
			$res = $e->getResponse();
			if ($res instanceof \Psr\Http\Message\ResponseInterface) {
				$status = $res->getStatusCode();
				$raw = (string)$res->getBody();
				$msg = '';
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
				}
				if ($msg === '') {
					$msg = $raw !== '' ? mb_substr($raw, 0, 500) : ('HTTP ' . $status);
				}
				return new GrafanaApiException($msg, $status, $e);
			}
		}
		return new GrafanaApiException($e->getMessage(), 0, $e);
	}

	private function dispatch(IClient $client, string $method, string $url, array $opts): IResponse {
		switch (strtoupper($method)) {
			case 'GET':    return $client->get($url, $opts);
			case 'PUT':    return $client->put($url, $opts);
			case 'POST':   return $client->post($url, $opts);
			case 'DELETE': return $client->delete($url, $opts);
			default:
				throw new \LogicException('Unsupported HTTP method: ' . $method);
		}
	}

	/** @return array<string,mixed> */
	private function decode(IResponse $res): array {
		$body = (string)$res->getBody();
		if ($body === '') {
			return [];
		}
		try {
			$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('Grafana response was not valid JSON', ['exception' => $e]);
			throw new \RuntimeException('Grafana response was not valid JSON.', 0, $e);
		}
		if (!is_array($data)) {
			throw new \RuntimeException('Grafana response was not a JSON object/array.');
		}
		return $data;
	}
}
