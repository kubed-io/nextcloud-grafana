<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * The Grafana **instance** — the whole connection in one card: the base URL and the
 * service-account token.
 *
 * Unlike the n8n master (which splits Instance / REST-API / Webhook because it has
 * *two* credentials and two channels), Grafana has a **single API and a single
 * token**, so there's nothing to split — URL + token live together under one
 * "Instance" section. The name stays "Instance" to line up with n8n's first
 * section.
 *
 * The token is sent as `Authorization: Bearer` on every request, and it's what the
 * Test connection button (Sync Actions) and the sync chapters authenticate with.
 * Values land in appconfig under app `grafana_sync`; `grafana_token` is `sensitive`
 * so core stores it encrypted and never echoes it back.
 *
 * Because a sensitive field renders **blank** even when a value is stored (core
 * never echoes it), the admin otherwise can't tell "no token yet" from "a token is
 * saved". So the token field's copy is rendered *dynamically* from whether a token
 * is currently stored — a plain, reliable "is it set?" signal. (Whether that token
 * is *valid* is the separate question the Test connection button answers.)
 */
final class InstanceSettings implements IDeclarativeSettingsForm {
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		$hasToken = $this->config->getValueString(Application::APP_ID, 'grafana_token', '') !== '';

		$tokenDescription = $hasToken
			? '✓ A token is currently stored (encrypted). Paste a new one to replace it, or use Test connection to check it still works.'
			: 'A Grafana service-account token (role Editor is enough) — create one under Administration → Service accounts in Grafana. Sent as Authorization: Bearer once saved.';
		$tokenPlaceholder = $hasToken
			? '•••••••••••••• — a token is stored (paste to replace)'
			: 'Paste the Grafana service-account token';

		return [
			// NOTE: do NOT prefix the form id with the app id. The settings frontend
			// strips a leading "<app>_" before calling the save API, so a prefixed id
			// (e.g. grafana_sync_instance -> instance) fails the backend's exact-match
			// lookup and sensitive fields get stored unencrypted. A clean id keeps
			// both sides in agreement.
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Instance',
			'description' => 'The Grafana instance the app is scoped to — its base URL and the service-account token used to reach it.',
			'fields' => [
				[
					'id' => 'grafana_url',
					'title' => 'Grafana base URL',
					'description' => 'e.g. https://grafana.example.com (no trailing slash). In-cluster URLs like http://grafana-service.observe.svc:3000 also work.',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://grafana.example.com',
					'default' => '',
				],
				[
					'id' => 'grafana_token',
					'title' => 'Service-account token',
					'description' => $tokenDescription,
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $tokenPlaceholder,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
