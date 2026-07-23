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
 * The **Connection** card — the Grafana service-account token. Grafana has a
 * single credential (unlike n8n's split of REST-API key + webhook token), so this
 * one card is the whole auth story: the token is sent as `Authorization: Bearer`
 * on every request, and it's what the Test connection button (and the sync
 * chapters) authenticate with.
 *
 * Values land in appconfig under app `grafana_sync`; `grafana_token` is
 * `sensitive` so core stores it encrypted and never echoes it back.
 *
 * Because a sensitive field renders **blank** even when a value is stored (core
 * never echoes it), the admin otherwise can't tell "no token yet" from "a token is
 * saved". So the card's copy is rendered *dynamically* from whether a token is
 * currently stored — a plain, reliable "is it set?" signal that doesn't depend on
 * the framework showing the masked value. (Whether that token is *valid* is a
 * separate question the Test connection button answers.)
 */
final class ConnectionSettings implements IDeclarativeSettingsForm {
	public function __construct(
		private IAppConfig $config,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		$hasToken = $this->config->getValueString(Application::APP_ID, 'grafana_token', '') !== '';

		$fieldDescription = $hasToken
			? '✓ A token is currently stored (encrypted). Paste a new one to replace it, or use Test connection to check it still works.'
			: 'No token stored yet. Sent as Authorization: Bearer to the Grafana API once saved.';
		$placeholder = $hasToken
			? '•••••••••••••• — a token is stored (paste to replace)'
			: 'Paste the Grafana service-account token';

		return [
			// NOTE: do NOT prefix the form id with the app id. The settings
			// frontend strips a leading "<app>_" before calling the save API, so a
			// prefixed id (e.g. grafana_sync_connection -> connection) fails the
			// backend's exact-match lookup and sensitive fields get stored
			// unencrypted. A clean id keeps both sides in agreement.
			'id' => 'connection',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Connection',
			'description' => 'The Grafana service-account token. Create one in Grafana under Administration → Service accounts (role Editor is enough), then paste the token here.',
			'fields' => [
				[
					'id' => 'grafana_token',
					'title' => 'Service-account token',
					'description' => $fieldDescription,
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $placeholder,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
