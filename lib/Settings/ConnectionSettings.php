<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

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
 */
final class ConnectionSettings implements IDeclarativeSettingsForm {
	#[\Override]
	public function getSchema(): array {
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
					'description' => 'Stored encrypted. Sent as Authorization: Bearer to the Grafana API.',
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => 'Paste the Grafana service-account token',
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}
}
