<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\MappingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Folder-mapping admin panel — the most involved bit of the section because it's
 * an editable list of objects, not a flat form. Declarative settings have no
 * array-of-objects type, so this is a classic IDelegatedSettings panel rendered
 * server-side: PHP foreach builds the initial cards, and vanilla JS does
 * add/update/delete through MappingController.
 *
 * The card layout mirrors the n8n master (Grafana folder + NC folder | mode +
 * team-folder | groups | actions). The **groups** list and the
 * **team-folder-available** flag feed the col-3 picker + the Team Folder checkbox.
 * Team Folder persists with the mapping; GROUPS DO NOT — they are read from the
 * mapped folder as this renders (see Mapping's class docblock). (The model carries
 * `use_team_folder`, mirroring n8n); the sync engine that *provisions* the folder
 * from those values lands in a later chapter.
 *
 * The Grafana folder picker itself is filled client-side (the JS fetches
 * `/apps/grafana_sync/folders`) so a render never depends on Grafana being
 * reachable.
 */
final class MappingSettings implements IDelegatedSettings {
	public function __construct(
		private readonly MappingService $service,
		private readonly IGroupManager $groupManager,
		private readonly IAppManager $appManager,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'mapping-settings');
		Util::addStyle(Application::APP_ID, 'mapping-settings');

		// All group ids, for the per-mapping group multiselect. search('') returns
		// every group; fine for a homelab — paginate later if it ever gets large.
		$groups = array_map(
			static fn ($g) => $g->getGID(),
			$this->groupManager->search(''),
		);
		sort($groups);

		return new TemplateResponse(
			Application::APP_ID,
			'mapping_settings',
			[
				// describe(), not toArray(): each card's Groups picker is checked against
				// what the FOLDER is shared with, read as this page renders. So a share
				// added in the Files app or with occ shows up here.
				'mappings' => array_map(
					fn ($m) => $this->service->describe($m),
					$this->service->list(),
				),
				'groups' => $groups,
				// Drives whether the Team Folder checkbox defaults on (groupfolders
				// present) — UI parity with n8n; not acted on yet.
				'team_folders_available' => $this->appManager->isInstalled('groupfolders'),
			],
			'blank',
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Below the connection cards (Instance 5 / Connection 10) and above Sync Actions
	 * (45): connect first, then map, then the action buttons.
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
