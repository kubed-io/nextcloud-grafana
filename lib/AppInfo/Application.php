<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\AppInfo;

use OCA\GrafanaSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * App bootstrap.
 *
 * POC scope: register only the admin **connection** surface — the single Instance
 * declarative form (base URL + service-account token; Grafana has one API and one
 * credential, so it's one card, unlike n8n's split). AdminSection (the sidebar
 * entry), the Folder-mappings + Sync Actions panels are wired through info.xml's
 * <settings> block; the only IRegistrationContext settings hook is
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
		// The single connection card shown at the top of the grafana_sync admin
		// section: Instance (base URL + service-account token, priority 5). The
		// Folder-mappings (30) and Sync Actions (45) panels — the latter holding the
		// Test-connection button — are classic panels registered via info.xml.
		$context->registerDeclarativeSettings(InstanceSettings::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// Nothing to boot yet — no metadata keys, no scheduled jobs in the POC.
	}
}
