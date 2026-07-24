<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * "Sync Actions" panel — the single home for all of the section's action buttons,
 * kept parallel to the n8n master's panel of the same name so the two apps stay
 * alike (and reduce cleanly into a shared library later).
 *
 * Nextcloud's declarative settings can't host buttons, and putting a button in its
 * own panel beside each data card makes the section a stack of thin strips — so the
 * house rule (settled on n8n) is **one classic panel for every button, rendered
 * last**: connection cards → Sync Settings → Folder mappings → **Sync Actions**.
 *
 * Holds the master's full button layout — **Sync to Grafana** / **Sync from
 * Grafana** / **Purge** (rendered disabled until the sync engine lands, Course 2/3)
 * plus **Test connection** (live today; handler in `admin-test.js`, endpoint gated
 * by {@see AdminTest}). Enabling the bulk buttons later is deleting a `disabled`
 * attribute + porting the master's `sync-settings.js` — the panel already matches.
 */
final class SyncSettings implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		// The panel's own layout styles, plus the Test-connection button's handler +
		// styles. Loaded via Util so they pick up the CSP nonce — inline
		// <script>/<style> in templates is blocked by NC's strict CSP.
		Util::addStyle(Application::APP_ID, 'sync-settings');
		// The pull button's handler (Course 2). Push + Purge stay disabled until
		// their engines land, so no extra handler is loaded for them yet.
		Util::addScript(Application::APP_ID, 'sync-settings');
		Util::addScript(Application::APP_ID, 'admin-test');
		Util::addStyle(Application::APP_ID, 'admin-test');

		return new TemplateResponse(Application::APP_ID, 'sync_settings', [], 'blank');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 45 — the **last** panel in the section, below Folder mappings (30),
	 * so every action button lives beneath the data it acts on. Matches the n8n
	 * master's Sync Actions priority.
	 */
	#[\Override]
	public function getPriority(): int {
		return 45;
	}

	#[\Override]
	public function getName(): ?string {
		// The heading is rendered inside the template (see sync_settings.php).
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Buttons hit dedicated controllers gated by their own
		// #[AuthorizedAdminSetting]; no generic appconfig writes here.
		return [];
	}
}
