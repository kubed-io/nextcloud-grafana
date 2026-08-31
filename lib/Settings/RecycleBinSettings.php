<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\AppConfigReader;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * "Recycle Bin" — what a delete MEANS, and where a deleted dashboard goes.
 *
 * ## WHY THIS IS ITS OWN CARD
 *
 * These two fields used to sit at the bottom of "Sync Settings", under a heading
 * about how often Nextcloud pulls. They are not sync settings. Sync is about
 * keeping two sides equal on a schedule; this is about whether deleting is
 * reversible — the one setting in the app that can cost you a dashboard.
 *
 * Grouping them under a sync heading also made the pair read as an afterthought to
 * the schedule above, when the toggle below decides whether trashing a file is
 * recoverable or permanent. A setting with that much consequence gets its own
 * heading so an admin reads it deliberately rather than scrolling past it.
 *
 * ## THE CHECKBOX IS THE EXPENSIVE ONE, WHICH IS WHY STORAGE IS EXTERNAL
 *
 * `STORAGE_TYPE_INTERNAL` CANNOT CARRY A CHECKBOX IN AN ADMIN FORM — core's
 * `DeclarativeManager` throws a TypeError on both the read and the write of a bool
 * (the full reading is in {@see AutoSyncSettings}, which hit it first). The toggle
 * springs back on reload with nothing shown to the admin.
 *
 * That failure is merely annoying for a schedule. Here it is destructive: the admin
 * opts into **id-preserving deletes, the toggle silently reverts, and every
 * subsequent trash is a true, permanent Grafana delete** — the aggressive model they
 * explicitly turned off. Grafana has no undo (proven live: the service account
 * cannot reach any soft-delete), so that is the difference between a recoverable
 * dashboard and a destroyed one.
 *
 * So this form is `STORAGE_TYPE_EXTERNAL` + {@see IDeclarativeSettingsFormWithHandlers},
 * which hands the read and the write back to this class where the bool can be
 * coerced honestly.
 *
 * Same id-prefix gotcha as the other forms — the form id must NOT be prefixed with
 * the app id. The KEYS ARE UNCHANGED (`bin_enabled`, `bin_folder`): only which card
 * renders them moved, so `occ config:app:get grafana_sync bin_folder` still reads
 * exactly what it always did.
 */
final class RecycleBinSettings implements IDeclarativeSettingsFormWithHandlers {
	private const FIELD_BIN_ENABLED = 'bin_enabled';
	private const FIELD_BIN_FOLDER = 'bin_folder';

	public function __construct(
		private readonly IAppConfig $config,
		private readonly AppConfigReader $reader,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'recycle_bin',
			'priority' => 25, // after Sync Settings (20), before Folder mappings (30)
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			// EXTERNAL so getValue()/setValue() below own the coercion — see the
			// class docblock for why INTERNAL cannot carry the checkbox.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Recycle Bin',
			'description' => 'What happens in Grafana when you delete a dashboard file here. Grafana has no trash of its own, so this is where you give it one.',
			'fields' => [
				[
					'id' => self::FIELD_BIN_ENABLED,
					'title' => 'Keep deleted dashboards in a Grafana folder',
					'description' => 'On: trashing a dashboard file parks its dashboard in the folder below, keeping its id, and restoring moves it back. Off: trashing deletes it in Grafana, and restoring rebuilds it with a new id.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// Real bool — and the one whose failure destroys dashboards. See
					// the class docblock.
					'default' => false,
				],
				[
					'id' => self::FIELD_BIN_FOLDER,
					'title' => 'Which Grafana folder to use',
					'description' => 'An existing Grafana folder to park them in. It cannot also be a mapped folder.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'nextcloud-trash',
					'default' => '',
				],
			],
		];
	}

	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			self::FIELD_BIN_ENABLED => $this->reader->bool(self::FIELD_BIN_ENABLED),
			self::FIELD_BIN_FOLDER => $this->reader->string(self::FIELD_BIN_FOLDER, ''),
			default => null,
		};
	}

	/**
	 * `bin_folder` is trimmed but NOT validated against Grafana here: a settings save
	 * must not depend on Grafana being reachable, and
	 * {@see \OCA\GrafanaSync\Service\RecycleBin::activeFolderUid} already throws —
	 * aborting the delete — when bin mode is on and the folder cannot be resolved.
	 * Failing at use-time is the safe direction; failing at save-time would leave the
	 * admin unable to record their intent while Grafana is down.
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case self::FIELD_BIN_ENABLED:
				// setValueBool (not a '1'/'0' string) so the reader's primary
				// getValueBool() read succeeds instead of falling through its
				// AppConfigTypeConflict rescue path.
				$this->config->setValueBool(Application::APP_ID, self::FIELD_BIN_ENABLED, AppConfigReader::coerceBool($value));
				break;
			case self::FIELD_BIN_FOLDER:
				$this->config->setValueString(
					Application::APP_ID,
					self::FIELD_BIN_FOLDER,
					is_string($value) ? trim($value) : '',
				);
				break;
		}
	}
}
