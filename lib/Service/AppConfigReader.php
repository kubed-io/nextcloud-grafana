<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Type-tolerant reads of this app's own appconfig keys.
 *
 * A value written by the old INTERNAL declarative path is string-typed, so the
 * matching typed getter raises an AppConfigTypeConflict until the admin saves
 * once. Reporting a setting as off because of how it was *stored* would be a
 * silent behaviour change, so every read here tries the natural getter first
 * and falls back to parsing the stored string. This rescue used to live in two
 * private copies ({@see \OCA\GrafanaSync\Settings\AutoSyncSettings} and
 * {@see \OCA\GrafanaSync\BackgroundJob\ScheduledPullJob}) that had to agree by
 * convention; now it lives once.
 *
 * {@see \OCA\GrafanaSync\Service\RecycleBin::isEnabled} is a DELIBERATE non-user.
 * It reads the same shape but THROWS instead of defaulting to false, because a false
 * there permanently destroys dashboards — that divergence is load-bearing and
 * documented in place. Folding it in here would silently reintroduce "treat as off".
 *
 * Reads only. Writes stay with their owners, which each pick the stored type
 * deliberately (see AutoSyncSettings::setValue on why the checkbox must be
 * written bool-typed).
 */
final class AppConfigReader {
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	/**
	 * A bool-typed read with a string-parse rescue. The default on a rescue is
	 * false — the parse itself decides, so an unreadable value reads as off,
	 * matching what the typed getter would have said about a missing key.
	 */
	public function bool(string $key): bool {
		try {
			return $this->config->getValueBool(Application::APP_ID, $key, false);
		} catch (\Throwable) {
			return self::coerceBool($this->string($key, ''));
		}
	}

	public function string(string $key, string $default): string {
		try {
			return $this->config->getValueString(Application::APP_ID, $key, $default);
		} catch (\Throwable) {
			return $default;
		}
	}

	/**
	 * What the settings frontend may round-trip for a checkbox: a real bool,
	 * an int, or one of the usual string spellings.
	 */
	public static function coerceBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value !== 0;
		}
		return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}
}
