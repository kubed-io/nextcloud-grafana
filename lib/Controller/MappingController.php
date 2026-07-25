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
use OCA\GrafanaSync\Service\MappingTeardownService;
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
		private readonly MappingService $service,
		private readonly GrafanaClient $client,
		private readonly MappingTeardownService $teardown,
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
			// `id` is server-assigned — never honour a client-supplied one on create,
			// or two mappings could collide on the stable handle update/delete key off.
			$params = $this->request->getParams();
			unset($params['id']);
			$mapping = Mapping::fromArray($params);
			$saved = $this->service->add($mapping);
			return new JSONResponse(['mapping' => $saved->toArray()], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id): JSONResponse {
		try {
			// The path id is authoritative — assign it explicitly. (Array union
			// `getParams() + ['id' => $id]` would keep a body-supplied id, since union
			// favours the left operand's keys — the opposite of what we want.)
			$params = $this->request->getParams();
			$params['id'] = $id;
			$mapping = Mapping::fromArray($params);
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
			// Tear-down cascade: trash the mapping's connected files (their delete rides the
			// recycle-bin setting) before dropping the binding. Standalone files are left alone.
			$this->teardown->remove($id);
			return new JSONResponse(['status' => 'ok']);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			// Partial tear-down (a connected file couldn't be removed, e.g. Grafana unreachable):
			// the mapping was kept for retry. 409 conveys "not done, try again".
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
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
			// Same friendly formatter as the connection test — so this never leaks
			// Grafana's raw auth text ("Invalid API key") into the picker UI.
			return new JSONResponse(['folders' => [], 'message' => GrafanaClient::describeConnectionError($e)]);
		}
	}
}
