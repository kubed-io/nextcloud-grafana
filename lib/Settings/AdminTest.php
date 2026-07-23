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
 * Classic (non-declarative) "Test" panel: one button that hits an authenticated
 * Grafana endpoint to prove the saved URL + token actually work. Declarative
 * settings cannot include buttons, so the test lives here.
 *
 * Implements IDelegatedSettings so the controller can gate the test endpoint with
 * the canonical #[AuthorizedAdminSetting] attribute.
 */
final class AdminTest implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		// JS + CSS must be added via Util so they pick up the CSP nonce — inline
		// <script>/<style> in templates is blocked by NC's strict CSP.
		Util::addScript(Application::APP_ID, 'admin-test');
		Util::addStyle(Application::APP_ID, 'admin-test');
		// 'blank' render mode: NC wraps the template in the section container but
		// does not inject a full page shell.
		return new TemplateResponse(Application::APP_ID, 'admin_test', [], 'blank');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Priority 15 — rendered just below the Connection card (10) so the button
	 * sits right after the token it tests.
	 */
	#[\Override]
	public function getPriority(): int {
		return 15;
	}

	#[\Override]
	public function getName(): ?string {
		// The heading is rendered inside the template (see admin_test.php).
		return null;
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Read-only test endpoint — no appconfig keys are modified.
		return [];
	}
}
