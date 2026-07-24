<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Controller;

use OCA\GrafanaSync\Service\SyncService;
use OCA\GrafanaSync\Settings\SyncSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Manual bulk-sync endpoint — the "Sync from Grafana" button in the Sync Actions
 * panel (saga Ch2 Course 2, "the pull").
 *
 * Pull (Grafana → Nextcloud) is fully wired through {@see SyncService::pullAll}:
 * every mapping's folder is provisioned and reconciled against the dashboards in its
 * Grafana folder, files are matched by uid, and stale mirrors are pruned. It runs
 * **inline** — homelab-scale instances finish in one request, and the counts come
 * straight back to the toast. (Push + purge, and an async background-job path, ride
 * this same controller in later courses; the panel already renders their buttons,
 * disabled.)
 *
 * Push (Grafana ← Nextcloud, Course 3) is wired the same way through
 * {@see SyncService::pushAll}: every mapping's sync files are sent up as upserts on
 * their uid. (Purge stays out until Course 4's delete machine.)
 *
 * Routes:
 *   POST /apps/grafana_sync/sync/pull → Grafana → NC (bulk populate)
 *   POST /apps/grafana_sync/sync/push → NC → Grafana (bulk writeback)
 */
final class SyncController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $sync,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reconcile every mapping from Grafana, inline, and return the run counts.
	 */
	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function pull(): JSONResponse {
		try {
			// Through dispatch (inline, all mappings) — the same entry point the master's
			// controller uses, so the async branch drops in here later without a change.
			$res = $this->sync->dispatch(SyncService::DIR_PULL, null, false);
		} catch (\Throwable $e) {
			// Per-mapping failures are caught + curated inside pullAll (it returns
			// status=error with a friendly message, never throws). Reaching here means
			// an unexpected error, so log the detail and show the admin a generic line
			// rather than leaking raw internals — consistent with the app's other endpoints.
			$this->logger->error('grafana_sync pull failed', ['exception' => $e]);
			return new JSONResponse([
				'status' => 'error',
				'message' => 'Sync failed — check the server log for details.',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($res, Http::STATUS_OK);
	}

	/**
	 * Push every mapping's sync files up to Grafana, inline, and return the run counts.
	 */
	#[AuthorizedAdminSetting(settings: SyncSettings::class)]
	public function push(): JSONResponse {
		try {
			$res = $this->sync->dispatch(SyncService::DIR_PUSH, null, false);
		} catch (\Throwable $e) {
			// Per-mapping/per-file failures are caught + curated inside pushAll (it
			// returns status=error with a friendly message, never throws). Reaching here
			// means an unexpected error — log the detail, show the admin a generic line.
			$this->logger->error('grafana_sync push failed', ['exception' => $e]);
			return new JSONResponse([
				'status' => 'error',
				'message' => 'Sync failed — check the server log for details.',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($res, Http::STATUS_OK);
	}
}
