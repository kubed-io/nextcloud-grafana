<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * "Sync Settings" — the automatic-sync strategy for both directions: the
 * Nextcloud→Grafana push timing and the Grafana→Nextcloud scheduled pull, plus the
 * recycle-bin switch that selects which delete model {@see \OCA\GrafanaSync\Service\DeleteService}
 * follows. (User-facing title "Sync Settings"; persistence is keyed by the form id
 * `data_sync`, not the class name.) The always-available bulk buttons live in their
 * own dedicated panel ({@see SyncSettings}); this form is config only.
 *
 * Values live in appconfig under each field id, read elsewhere by:
 *   - `timing`            → {@see \OCA\GrafanaSync\Listener\NodeWrittenListener} (NC→Grafana)
 *   - `schedule_enabled`  → the scheduled-pull background job (Grafana→NC)
 *   - `schedule_interval` → that job's TimedJob interval (seconds)
 *   - `bin_enabled`       → {@see \OCA\GrafanaSync\Service\RecycleBin::isEnabled}
 *   - `bin_folder`        → {@see \OCA\GrafanaSync\Service\RecycleBin::folderTitle}
 *
 * NC schedules by **interval** (TimedJob), not cron expressions.
 *
 * Same id-prefix gotcha as InstanceSettings — the form id must NOT be prefixed with
 * the app id (the frontend strips a leading "<app>_" before the save call).
 *
 * ── WHY THIS FORM HANDLES ITS OWN STORAGE ────────────────────────────────────
 *
 * `STORAGE_TYPE_INTERNAL` CANNOT CARRY A CHECKBOX IN AN ADMIN FORM. This is a core
 * limitation, read out of `OC\Settings\DeclarativeManager` (verified on both
 * `master` and `stable32`, which are identical here), not a preference:
 *
 *   - **the write** — `saveInternalValue()` for `SECTION_TYPE_ADMIN` calls
 *     `IAppConfig::setValueString($app, $fieldId, $value)`. A CHECKBOX posts a real
 *     JSON `bool`, and `DeclarativeManager.php` declares `strict_types=1`, so the
 *     bool raises a **TypeError** and the save aborts.
 *   - **the read** — `getInternalValue()` passes the schema's `default` straight
 *     into `IConfig::getAppValue($app, $key, $default)`, whose third parameter is
 *     also typed `string`. So a `'default' => false` (which a CHECKBOX needs, and
 *     which is what core's own examples use) throws on the way *back out*.
 *
 * Both spellings are therefore broken, in opposite directions, and the toggle
 * springs back on reload with nothing shown to the admin.
 *
 * THIS APP CARRIES TWO CHECKBOXES, AND THE SECOND ONE IS THE EXPENSIVE ONE.
 * `schedule_enabled` failing means the scheduled pull quietly never runs, which is
 * annoying. `bin_enabled` failing means the admin opts into **id-preserving deletes,
 * the toggle springs back, and every subsequent trash is a true, permanent Grafana
 * delete** — the aggressive model they explicitly turned off. Grafana has no undo
 * (proven live: the service account cannot reach any soft-delete), so that is the
 * difference between a recoverable dashboard and a destroyed one. The n8n sibling
 * found the core limitation first, but it had only the cheap checkbox to lose.
 *
 * `STORAGE_TYPE_EXTERNAL` + {@see IDeclarativeSettingsFormWithHandlers} hands the
 * two operations back to this class, which coerces each value to the type its field
 * actually means. Core calls {@see getValue}/{@see setValue} directly on the
 * registered form object — **no listener class and no event wiring** (see
 * `DeclarativeManager::getValue()`, which prefers the interface and only falls back
 * to `DeclarativeSettingsGetValueEvent` when the form does not implement it).
 *
 * The KEYS AND THEIR STORAGE ARE UNCHANGED — `occ config:app:get grafana_sync timing`
 * still reads exactly what it always did. Only who does the read and write moves.
 *
 * The interface is `@since 31.0.0`, which is why `appinfo/info.xml` requires
 * Nextcloud ≥ 31.
 */
final class AutoSyncSettings implements IDeclarativeSettingsFormWithHandlers {
	/** Fallback pull cadence, used as both the placeholder and the stored default. */
	public const DEFAULT_INTERVAL = '1h';

	private const FIELD_TIMING = 'timing';
	private const FIELD_SCHEDULE_ENABLED = 'schedule_enabled';
	private const FIELD_SCHEDULE_INTERVAL = 'schedule_interval';
	private const FIELD_BIN_ENABLED = 'bin_enabled';
	private const FIELD_BIN_FOLDER = 'bin_folder';

	private const TIMING_ASYNC = 'async';
	private const TIMING_SYNC = 'sync';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'data_sync',
			'priority' => 20, // between the Instance card (5) and Folder mappings (30)
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			// EXTERNAL so getValue()/setValue() below own the coercion — see the
			// class docblock for why INTERNAL cannot carry either checkbox.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Sync Settings',
			'description' => 'How Nextcloud and Grafana stay in sync automatically. The Sync Actions buttons below run a one-off sync in either direction at any time.',
			'fields' => [
				[
					'id' => self::FIELD_TIMING,
					'title' => 'Nextcloud → Grafana: when you save a dashboard file',
					'description' => 'Async (recommended): the push runs in the background after the save. Sync: pushes during the save for instant feedback, but can briefly lock the file. Only sync mappings push back.',
					'type' => DeclarativeSettingsTypes::RADIO,
					'default' => self::TIMING_ASYNC,
					'options' => [
						['name' => 'Push in the background (asynchronous — recommended)', 'value' => self::TIMING_ASYNC],
						['name' => 'Push immediately during the save (synchronous)', 'value' => self::TIMING_SYNC],
					],
				],
				[
					'id' => self::FIELD_SCHEDULE_ENABLED,
					'title' => 'Grafana → Nextcloud: scheduled sync',
					'description' => 'Nextcloud periodically pulls dashboards from Grafana (read-only — nothing changes in Grafana). Optional; when off, use the manual “Sync from Grafana” button.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// A real bool: this is what the frontend round-trips. It is safe
					// here only because EXTERNAL storage never feeds it to
					// IConfig::getAppValue() (see the class docblock).
					'default' => false,
				],
				[
					'id' => self::FIELD_SCHEDULE_INTERVAL,
					'title' => 'Schedule — how often',
					'description' => 'How often to pull, as a number + unit (s/m/h/d). Examples: 15m, 1h, 6h, 1d. A plain number = seconds. Minimum 1m.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => self::DEFAULT_INTERVAL,
					'default' => self::DEFAULT_INTERVAL,
				],
				[
					'id' => self::FIELD_BIN_ENABLED,
					'title' => 'Deleting: preserve dashboards in a Grafana recycle-bin folder',
					'description' => 'Off (default): trashing a synced dashboard file deletes its dashboard in Grafana right then; restoring re-creates it with a new id (its full JSON is safe in the file). On: trashing instead moves the dashboard into the Grafana folder named below (keeping its id), restoring moves it back, and only emptying the Nextcloud trash deletes it for good. Grafana has no native trash, so this folder is it.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// Real bool — and the one whose failure destroys dashboards. See
					// the class docblock.
					'default' => false,
				],
				[
					'id' => self::FIELD_BIN_FOLDER,
					'title' => 'Recycle-bin folder (Grafana folder name)',
					'description' => 'The name of an existing Grafana folder to use as the recycle bin (e.g. nextcloud-trash). Used only when the option above is on. This folder must not be one you map — it has special meaning.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'nextcloud-trash',
					'default' => '',
				],
			],
		];
	}

	/**
	 * Read one field for the settings UI, in the type that field means — a real
	 * `bool` for the two checkboxes, a `string` for the radio and the text boxes.
	 */
	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			self::FIELD_TIMING => $this->readString(self::FIELD_TIMING, self::TIMING_ASYNC),
			self::FIELD_SCHEDULE_ENABLED => $this->readBool(self::FIELD_SCHEDULE_ENABLED),
			self::FIELD_SCHEDULE_INTERVAL => $this->readString(self::FIELD_SCHEDULE_INTERVAL, self::DEFAULT_INTERVAL),
			self::FIELD_BIN_ENABLED => $this->readBool(self::FIELD_BIN_ENABLED),
			self::FIELD_BIN_FOLDER => $this->readString(self::FIELD_BIN_FOLDER, ''),
			default => null,
		};
	}

	/**
	 * Persist one field, normalising what the frontend sent. The radio is pinned to
	 * its two known values so a malformed POST cannot write a `timing` nothing reads;
	 * the interval is stored verbatim-but-trimmed because the scheduled-pull job
	 * already owns parsing it (and falls back to hourly on anything it cannot read).
	 *
	 * `bin_folder` is trimmed but NOT validated against Grafana here: a settings save
	 * must not depend on Grafana being reachable, and {@see \OCA\GrafanaSync\Service\RecycleBin::activeFolderUid}
	 * already throws — aborting the delete — when bin mode is on and the folder cannot
	 * be resolved. Failing at use-time is the safe direction; failing at save-time
	 * would leave the admin unable to record their intent while Grafana is down.
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case self::FIELD_TIMING:
				$timing = is_string($value) ? strtolower(trim($value)) : '';
				$this->config->setValueString(
					Application::APP_ID,
					self::FIELD_TIMING,
					$timing === self::TIMING_SYNC ? self::TIMING_SYNC : self::TIMING_ASYNC,
				);
				break;
			case self::FIELD_SCHEDULE_ENABLED:
			case self::FIELD_BIN_ENABLED:
				// setValueBool (not a '1'/'0' string) so the readers' primary
				// getValueBool() read succeeds instead of falling through their
				// AppConfigTypeConflict rescue path.
				$this->config->setValueBool(Application::APP_ID, $fieldId, $this->toBool($value));
				break;
			case self::FIELD_SCHEDULE_INTERVAL:
				$raw = is_string($value) ? trim($value) : '';
				$this->config->setValueString(
					Application::APP_ID,
					self::FIELD_SCHEDULE_INTERVAL,
					$raw === '' ? self::DEFAULT_INTERVAL : $raw,
				);
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

	/**
	 * A checkbox, tolerant of how it was last written. A value stored by the old
	 * INTERNAL path is string-typed, so `getValueBool` raises an AppConfigTypeConflict
	 * until the admin saves once — fall back to parsing the string rather than
	 * reporting the option as off. Mirrors the same rescue in
	 * {@see \OCA\GrafanaSync\Service\RecycleBin::isEnabled}.
	 */
	private function readBool(string $key): bool {
		try {
			return $this->config->getValueBool(Application::APP_ID, $key, false);
		} catch (\Throwable) {
			return $this->toBool($this->readString($key, ''));
		}
	}

	private function readString(string $key, string $default): string {
		try {
			return $this->config->getValueString(Application::APP_ID, $key, $default);
		} catch (\Throwable) {
			return $default;
		}
	}

	private function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value !== 0;
		}
		return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}
}
