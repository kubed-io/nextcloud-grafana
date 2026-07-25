<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GrafanaSync\Listener\CopyListener;
use OCA\GrafanaSync\Listener\CreateInGrafanaListener;
use OCA\GrafanaSync\Listener\DeleteToGrafanaListener;
use OCA\GrafanaSync\Listener\MotionListener;
use OCA\GrafanaSync\Listener\MoveGuardListener;
use OCA\GrafanaSync\Listener\NodeWrittenListener;
use OCA\GrafanaSync\Listener\RegisterDavPluginsListener;
use OCA\GrafanaSync\Listener\RestoreFromTrashListener;
use OCA\GrafanaSync\Listener\TrashPurgeHook;
use OCA\GrafanaSync\Notification\Notifier;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Settings\AutoSyncSettings;
use OCA\GrafanaSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
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
 * own new dashboard.
 *
 * Move + guards (Course 4 · Slice 2b): {@see MoveGuardListener} refuses a link move-out
 * before it happens, {@see MotionListener} reconciles a completed move (re-parent on
 * mapped→mapped, delete + strip on move-out, bin OFF the default), and
 * {@see RegisterDavPluginsListener} bolts the link-write guard onto every Sabre server.
 *
 * Delete lifecycle (Course 4 · Slice 3): {@see DeleteToGrafanaListener} mirrors a trash/purge
 * into Grafana (aborting the NC delete if Grafana can't confirm) and {@see RestoreFromTrashListener}
 * reverses it — both driven by the optional Grafana **recycle-bin folder** setting (OFF = true
 * delete + re-create on restore; ON = park in the bin folder + move back, id kept).
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

		// Move (Course 4 · Slice 2b): a completed move of a managed file. MotionService
		// re-parents the dashboard on a mapped→mapped move (uid kept) or deletes it +
		// strips the file on a move out of every mapping (bin OFF, the default). The
		// before-gate refuses only a link move-out; mapped→mapped is allowed (a real
		// Grafana folder move), which is where we diverge from the tag-mapped n8n master.
		// CreateInGrafanaListener (also on NodeRenamedEvent) owns the unmanaged move-in;
		// the two never overlap — it bails on managed files, MotionService on unmanaged.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, MoveGuardListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, MotionListener::class);

		// DAV link-write guard (Course 4 · Slice 2b): attach LinkWriteGuardPlugin to every
		// Sabre server so a raw WebDAV PUT / desktop-client edit can't overwrite a link
		// file's pointer. The Files UI already routes a link's click to "Open in Grafana".
		$context->registerEventListener(SabrePluginAddEvent::class, RegisterDavPluginsListener::class);

		// Delete lifecycle (Course 4 · Slice 3): BeforeNodeDeletedEvent fires on the trash-move
		// (soft step) — the listener parks/deletes the dashboard per the recycle-bin setting and
		// aborts the NC delete if Grafana can't confirm. Restore-from-trash reverses it (move the
		// parked dashboard back, or re-create it). The permanent-delete-from-trash (hard step) is
		// NOT a typed event — it's the legacy \OCP\Trashbin preDelete hook, wired in boot().
		$context->registerEventListener(BeforeNodeDeletedEvent::class, DeleteToGrafanaListener::class);
		$context->registerEventListener(NodeRestoredEvent::class, RestoreFromTrashListener::class);

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

		// Empty-trash (hard delete) for the delete lifecycle: permanently deleting a file from the
		// Nextcloud trash does NOT fire a typed event — the trashbin emits the legacy
		// `\OCP\Trashbin` `preDelete` hook just before it unlinks the node. Bin ON: that's when a
		// parked dashboard is permanently deleted from the Grafana bin. Connect our handler
		// instance (the legacy hook calls object+method); its deps construct without any I/O.
		$purgeHook = $context->getAppContainer()->get(TrashPurgeHook::class);
		\OCP\Util::connectHook('\OCP\Trashbin', 'preDelete', $purgeHook, 'preDelete');
	}
}
