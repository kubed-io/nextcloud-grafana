<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\AppInfo;

use OCA\GrafanaSync\Listener\CopyListener;
use OCA\GrafanaSync\Listener\CreateInGrafanaListener;
use OCA\GrafanaSync\Listener\NodeWrittenListener;
use OCA\GrafanaSync\Notification\Notifier;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Settings\AutoSyncSettings;
use OCA\GrafanaSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

/**
 * App bootstrap.
 *
 * Admin scope: register the two declarative admin forms — the Instance card (base
 * URL + service-account token; Grafana has one API and one credential, so it's one
 * card, unlike n8n's split) and the Sync Settings card (push timing + scheduled
 * pull). The AdminSection sidebar entry, the Folder-mappings + Sync Actions panels
 * are wired through info.xml's <settings> block.
 *
 * Writeback (Course 3): the {@see NodeWrittenListener} pushes a saved sync-mode
 * `.grafana.json` back to Grafana, and the {@see Notifier} renders its failure notices.
 *
 * Write surface (Course 4 · Slice 1): {@see CreateInGrafanaListener} turns a new file in
 * a mapped sync folder into a real dashboard, and {@see CopyListener} makes a copy its
 * own new dashboard. The rest of the mode machine (move → unmapped, delete, rename, the
 * DAV link-write guard) stays deferred to Slice 2 — it needs the "no archive verb" ruling.
 */
final class Application extends App implements IBootstrap {
	public const APP_ID = 'grafana_sync';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Declarative admin cards, top of the grafana_sync section:
		//   Instance (5)  — base URL + service-account token
		//   Sync Settings (20) — push timing + scheduled pull (config only)
		// The Folder-mappings (30) and Sync Actions (45) panels — the latter holding
		// the action buttons — are classic panels registered via info.xml.
		$context->registerDeclarativeSettings(InstanceSettings::class);
		$context->registerDeclarativeSettings(AutoSyncSettings::class);

		// Writeback (Course 3): a save of a managed sync-mode .grafana.json pushes back
		// to Grafana. NodeWrittenEvent covers the text editor, WebDAV PUTs, and desktop
		// syncs; the listener's own SyncGuard + content-hash checks keep our own pull
		// writes from looping back.
		$context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);

		// Create-on-land (Course 4 · Slice 1): a new .grafana.json with no uid landing in
		// a mapped sync folder becomes a real dashboard. NodeWrittenEvent covers make/save/
		// upload; NodeRenamedEvent covers a move-in from outside a mapping.
		$context->registerEventListener(NodeWrittenEvent::class, CreateInGrafanaListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, CreateInGrafanaListener::class);

		// Copy: NC fires NodeCopiedEvent (not NodeWrittenEvent) on a copy, so this routes a
		// copied file to strip its identity + register a brand-new dashboard.
		$context->registerEventListener(NodeCopiedEvent::class, CopyListener::class);

		// Renders the push-failure bell/toast (SyncNotifier stores {subject, params}).
		$context->registerNotifierService(Notifier::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		// Register our managed Files-Metadata keys (grafana_uid, grafana_mode, …) so
		// they're advertised over DAV as `{nc:}metadata-<key>` and the indexed ones
		// (mode + mapping) are SEARCH/REPORT-queryable. Idempotent — safe every boot —
		// and it registers nothing but the key *schema*; nothing writes a value until
		// the pull/push spine lands, so a save still triggers no Grafana behaviour yet.
		//
		// getAppContainer() resolves THIS app's services (DashboardMetadata). Its
		// IAppContainer return type is deprecated by core with no non-deprecated
		// accessor on IBootContext, so that one Psalm deprecation rides the baseline.
		$context->getAppContainer()->get(DashboardMetadata::class)->register();
	}
}
