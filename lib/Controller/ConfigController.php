<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Controller;

use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Settings\AdminTest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin "Test connection" endpoint. The actual HTTP work lives in
 * {@see GrafanaClient} so the same code path exercised here is the same one the
 * sync chapters will use for the reconciler and the writeback push — there's only
 * ever one place we read+decrypt the token and hit the Grafana API.
 *
 * The 401/403/404 friendly mapping stays here because those codes are
 * HTTP-transport noise that only the connection test cares about; deeper callers
 * want raw exceptions to drive retry/backoff.
 */
final class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private GrafanaClient $client,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[AuthorizedAdminSetting(settings: AdminTest::class)]
	public function testConnection(): JSONResponse {
		try {
			$result = $this->client->ping();
			return new JSONResponse([
				'status' => 'ok',
				'message' => $result['message'],
				'httpStatus' => $result['httpStatus'],
			]);
		} catch (\Throwable $e) {
			// One shared formatter (also used by the occ command) so the button and
			// the CLI say the same thing — and so a *rejected* token (401/403) reads
			// differently from a *missing* one. A single `catch \Throwable` is
			// deliberate: GrafanaApiException is a RuntimeException subclass, so a
			// narrower `catch \RuntimeException` here would hide the 401 mapping.
			return new JSONResponse([
				'status' => 'error',
				'message' => GrafanaClient::describeConnectionError($e),
			]);
		}
	}
}
