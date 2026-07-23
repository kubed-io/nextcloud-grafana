<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Controller;

use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Settings\MappingSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST CRUD for folder mappings, plus a read-only folder picker. Admin-gated via
 * the framework attribute (same canonical pattern as the Test connection button).
 *
 * Routes (see appinfo/routes.php):
 *   GET    /apps/grafana_sync/mappings           → list
 *   POST   /apps/grafana_sync/mappings           → add   { grafana_folder_uid, grafana_folder_title, nc_folder, mode, format }
 *   PUT    /apps/grafana_sync/mappings/{id}      → update
 *   DELETE /apps/grafana_sync/mappings/{id}      → delete (drops the binding only —
 *                                                  no files or Grafana dashboards are
 *                                                  touched; there is nothing synced yet)
 *   GET    /apps/grafana_sync/folders            → the Grafana folders the token can
 *                                                  see, for the panel's folder picker
 */
final class MappingController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private MappingService $service,
		private GrafanaClient $client,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse([
			'mappings' => array_map(fn (Mapping $m) => $m->toArray(), $this->service->list()),
		]);
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function create(): JSONResponse {
		try {
			$mapping = Mapping::fromArray($this->request->getParams());
			$saved = $this->service->add($mapping);
			return new JSONResponse(['mapping' => $saved->toArray()], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id): JSONResponse {
		try {
			$mapping = Mapping::fromArray($this->request->getParams() + ['id' => $id]);
			$saved = $this->service->update($id, $mapping);
			return new JSONResponse(['mapping' => $saved->toArray()]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function destroy(string $id): JSONResponse {
		try {
			$this->service->delete($id);
			return new JSONResponse(['status' => 'ok']);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The Grafana folders the saved token can see — feeds the panel's folder picker
	 * so the admin binds a real folder (capturing its uid) instead of typing one.
	 * Best-effort: if Grafana is unreachable or the token is unset, return an empty
	 * list plus the reason, and the panel falls back to manual entry.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function folders(): JSONResponse {
		try {
			return new JSONResponse(['folders' => $this->client->listFolders()]);
		} catch (\Throwable $e) {
			return new JSONResponse(['folders' => [], 'message' => $e->getMessage()]);
		}
	}
}
