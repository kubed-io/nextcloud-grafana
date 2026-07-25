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
 * "Sync Settings" — the automatic-sync strategy for both directions: the
 * Nextcloud→Grafana push timing and the Grafana→Nextcloud scheduled pull.
 * (User-facing title "Sync Settings"; persistence is keyed by the form id
 * `data_sync`, not the class name.) The always-available bulk buttons live in their
 * own dedicated panel ({@see SyncSettings}); this form is config only.
 *
 * A near-verbatim re-cook of the n8n master's AutoSyncSettings — same field ids,
 * same defaults, same TimedJob-interval model — so the two reduce cleanly into a
 * shared base later. The only edits are the copy (n8n → Grafana) and the dropped
 * webhook mention (Grafana has one API and one credential, no second push path).
 *
 * Declarative + STORAGE_TYPE_INTERNAL → values auto-persist to appconfig under
 * each field id, read (once the sync engine lands, Course 2/3) by:
 *   - `timing`            → the NodeWritten push listener (NC→Grafana)
 *   - `schedule_enabled`  → the scheduled-pull background job (Grafana→NC)
 *   - `schedule_interval` → that job's TimedJob interval (seconds)
 *
 * NC schedules by **interval** (TimedJob), not cron expressions — hence presets.
 *
 * Same id-prefix gotcha as InstanceSettings — the form id must NOT be prefixed with
 * the app id (the frontend strips a leading "<app>_" before the save call).
 */
final class AutoSyncSettings implements IDeclarativeSettingsForm {
	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'data_sync',
			'priority' => 20, // between the Instance card (5) and Folder mappings (30)
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Sync Settings',
			'description' => 'How Nextcloud and Grafana stay in sync automatically. The Sync Actions buttons below run a one-off sync in either direction at any time.',
			'fields' => [
				[
					'id' => 'timing',
					'title' => 'Nextcloud → Grafana: when you save a dashboard file',
					'description' => 'Async (recommended): the push runs in the background after the save. Sync: pushes during the save for instant feedback, but can briefly lock the file. Only sync mappings push back.',
					'type' => DeclarativeSettingsTypes::RADIO,
					'default' => 'async',
					'options' => [
						['name' => 'Push in the background (asynchronous — recommended)', 'value' => 'async'],
						['name' => 'Push immediately during the save (synchronous)', 'value' => 'sync'],
					],
				],
				[
					'id' => 'schedule_enabled',
					'title' => 'Grafana → Nextcloud: scheduled sync',
					'description' => 'Nextcloud periodically pulls dashboards from Grafana (read-only — nothing changes in Grafana). Optional; when off, use the manual “Sync from Grafana” button.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// NC's DeclarativeManager does NO type coercion — a CHECKBOX default MUST
					// be a real bool (matches core, e.g. dav SystemAddressBookSettings). A
					// string '0' breaks the frontend boolean round-trip, so the toggle
					// silently never persists to appconfig (the scheduled pull then reads it
					// as off and never runs). Found + fixed in the n8n sibling first.
					'default' => false,
				],
				[
					'id' => 'schedule_interval',
					'title' => 'Schedule — how often',
					'description' => 'How often to pull, as a number + unit (s/m/h/d). Examples: 15m, 1h, 6h, 1d. A plain number = seconds. Minimum 1m.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '1h',
					'default' => '1h',
				],
				[
					'id' => 'bin_enabled',
					'title' => 'Deleting: preserve dashboards in a Grafana recycle-bin folder',
					'description' => 'Off (default): trashing a synced dashboard file deletes its dashboard in Grafana right then; restoring re-creates it with a new id (its full JSON is safe in the file). On: trashing instead moves the dashboard into the Grafana folder named below (keeping its id), restoring moves it back, and only emptying the Nextcloud trash deletes it for good. Grafana has no native trash, so this folder is it.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// Real bool, not '0' — see schedule_enabled above for why.
					'default' => false,
				],
				[
					'id' => 'bin_folder',
					'title' => 'Recycle-bin folder (Grafana folder name)',
					'description' => 'The name of an existing Grafana folder to use as the recycle bin (e.g. nextcloud-trash). Used only when the option above is on. This folder must not be one you map — it has special meaning.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'nextcloud-trash',
					'default' => '',
				],
			],
		];
	}
}
