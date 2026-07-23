<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\MappingService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Folder-mapping admin panel — the most involved bit of the section because it's
 * an editable list of objects, not a flat form. Declarative settings have no
 * array-of-objects type, so this is a classic IDelegatedSettings panel rendered
 * server-side: PHP foreach builds the initial cards, and vanilla JS does
 * add/update/delete through MappingController.
 *
 * The Grafana folder picker is filled client-side (the JS fetches
 * `/apps/grafana_sync/folders`) so a render never depends on Grafana being
 * reachable — an unconfigured or offline instance still shows the panel, just with
 * manual uid entry.
 *
 * Implements IDelegatedSettings so the controller can use
 * #[AuthorizedAdminSetting(settings: MappingSettings::class)] to gate the REST
 * endpoints — same canonical pattern as the Test connection button.
 */
final class MappingSettings implements IDelegatedSettings {
	public function __construct(
		private MappingService $service,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'mapping-settings');
		Util::addStyle(Application::APP_ID, 'mapping-settings');

		return new TemplateResponse(
			Application::APP_ID,
			'mapping_settings',
			[
				'mappings' => array_map(fn ($m) => $m->toArray(), $this->service->list()),
			],
			'blank',
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Below the connection cards (Instance 5 / Connection 10 / Test 15) and before
	 * anything the sync chapters add: connect first, then map.
	 */
	#[\Override]
	public function getPriority(): int {
		return 30;
	}

	#[\Override]
	public function getName(): ?string {
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Mappings are edited via the dedicated REST controller (which carries its
		// own #[AuthorizedAdminSetting]), not via the generic appconfig write
		// endpoint, so no entries here.
		return [];
	}
}
