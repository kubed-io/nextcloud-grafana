<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\AppInfo;

use OCA\GrafanaSync\Settings\ConnectionSettings;
use OCA\GrafanaSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * App bootstrap.
 *
 * POC scope: register only the admin **connection** surface — the Instance (URL)
 * and Connection (token) declarative forms. AdminSection (the sidebar entry) and
 * the classic AdminTest panel (the Test-connection button) are wired through
 * info.xml's <settings> block; the only IRegistrationContext settings hook is
 * registerDeclarativeSettings().
 *
 * Everything else from the master (the NodeWritten/rename/copy/delete listeners,
 * background jobs, Files-Metadata registration, the mimetype migration) is
 * deferred to the sync chapters and intentionally NOT wired here — a save on a
 * Nextcloud instance must not trigger any Grafana behaviour until that behaviour
 * is actually implemented.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'grafana_sync';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// The two connection cards shown in the grafana_sync admin section:
		// Instance URL (priority 5) → Connection token (priority 10). The
		// Test-connection button (AdminTest, priority 15) is a classic panel
		// registered via info.xml <admin>.
		$context->registerDeclarativeSettings(InstanceSettings::class);
		$context->registerDeclarativeSettings(ConnectionSettings::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// Nothing to boot yet — no metadata keys, no scheduled jobs in the POC.
	}
}
