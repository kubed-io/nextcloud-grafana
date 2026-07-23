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
 * The Grafana **instance** — just the base URL, in its own card at the top of the
 * section. The URL scopes everything the app does; the credential lives in its own
 * card ({@see ConnectionSettings}).
 */
final class InstanceSettings implements IDeclarativeSettingsForm {
	#[\Override]
	public function getSchema(): array {
		return [
			// See ConnectionSettings for the "do NOT prefix the id with the app id"
			// gotcha — applies to every declarative form in this section.
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Instance',
			'description' => 'The Grafana instance everything is scoped to.',
			'fields' => [
				[
					'id' => 'grafana_url',
					'title' => 'Grafana base URL',
					'description' => 'e.g. https://grafana.example.com (no trailing slash). In-cluster URLs like http://grafana-service.observe.svc:3000 also work.',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://grafana.example.com',
					'default' => '',
				],
			],
		];
	}
}
