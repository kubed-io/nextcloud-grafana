<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GrafanaSync\BackgroundJob\ScheduledPullJob;
use OCA\GrafanaSync\Listener\CopyListener;
use OCA\GrafanaSync\Listener\CreateInGrafanaListener;
use OCA\GrafanaSync\Listener\DeleteToGrafanaListener;
use OCA\GrafanaSync\Listener\FolderDeleteListener;
use OCA\GrafanaSync\Listener\FolderMoveListener;
use OCA\GrafanaSync\Listener\FolderRenameListener;
use OCA\GrafanaSync\Listener\LoadFilesScriptListener;
use OCA\GrafanaSync\Listener\MotionListener;
use OCA\GrafanaSync\Listener\MoveGuardListener;
use OCA\GrafanaSync\Listener\NameSyncListener;
use OCA\GrafanaSync\Listener\NodeWrittenListener;
use OCA\GrafanaSync\Listener\RegisterDavPluginsListener;
use OCA\GrafanaSync\Listener\RestoreFromTrashListener;
use OCA\GrafanaSync\Listener\TrashPurgeHook;
use OCA\GrafanaSync\Notification\Notifier;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Settings\AutoSyncSettings;
use OCA\GrafanaSync\Settings\InstanceSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
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

	/** Guards the legacy preDelete hook registration so a repeated boot() can't stack it. */
	private static bool $purgeHookRegistered = false;

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
		// The folder half of a rename — every other listener filters to files on its
		// first line, so a folder gesture had nothing watching it.
		$context->registerEventListener(NodeRenamedEvent::class, FolderRenameListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FolderMoveListener::class);

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

		// The FOLDER half of the same gesture. It is a separate listener rather than a
		// branch in the one above because Nextcloud does NOT decompose a folder delete:
		// one event fires, for the folder, and none for anything inside it. Everything
		// under a trashed folder is reached by walking it — see FolderCascade.
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FolderDeleteListener::class);

		// Files-app openers (Course 5): load the frontend bundle that adds the "Open in
		// Grafana" / "Open with text editor" row actions and the "Grafana dashboard" New-menu
		// item, and ship the Grafana base URL via Initial State for its deep links.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);

		// Three-way name sync (Course 5): keep the filename stem, the JSON `title`, and the
		// Grafana dashboard title in agreement for a managed sync file. Renaming the file
		// (NodeRenamedEvent) writes the stem into the JSON title + pushes; editing the JSON
		// title and saving (NodeWrittenEvent) renames the file. The reconcile is deferred to
		// ReconcileNameJob because the file is locked mid-rename.
		$context->registerEventListener(NodeWrittenEvent::class, NameSyncListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NameSyncListener::class);

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
		// injectFn(), not getAppContainer(): the latter returns IAppContainer, which core
		// has deprecated. injectFn resolves the argument through the same container by
		// type-hint, so this is ordinary dependency injection rather than a container
		// lookup — no deprecated surface touched, and the dependency is now visible in
		// the signature instead of buried in a get() call.
		$context->injectFn(static function (DashboardMetadata $metadata, FolderMetadata $folders): void {
			$metadata->register();
			$folders->register();
		});

		// Register the scheduled Grafana→NC pull. IJobList::add is idempotent, so
		// calling it every boot just ensures the TimedJob exists; the job self-gates
		// on `schedule_enabled` and reads its interval from app config, both of which
		// the Sync Settings card has been writing since before anything read them.
		// injectFn for the same reason the metadata registration above uses it: the
		// dependency is visible in the signature rather than buried in a container
		// lookup, and it avoids the deprecated getAppContainer() surface.
		$context->injectFn(static function (IJobList $jobs): void {
			$jobs->add(ScheduledPullJob::class);
		});

		// Empty-trash (hard delete) for the delete lifecycle: permanently deleting a file from the
		// Nextcloud trash does NOT fire a typed event — the trashbin emits the legacy
		// `\OCP\Trashbin` `preDelete` hook just before it unlinks the node. Bin ON: that's when a
		// parked dashboard is permanently deleted from the Grafana bin. Connect our handler
		// instance (the legacy hook calls object+method); its deps construct without any I/O.
		// connectHook APPENDS handlers with no de-duplication, so guard against a second boot()
		// in the same PHP process (tests, repeated loadApp) stacking the handler — which would
		// fire TrashPurgeHook::preDelete more than once per purge (repeated deletes / log spam).
		if (!self::$purgeHookRegistered) {
			self::$purgeHookRegistered = true;
			$purgeHook = $context->injectFn(static fn (TrashPurgeHook $hook): TrashPurgeHook => $hook);
			// connectHook is the only entry point for the legacy \OCP\Trashbin preDelete signal
			// (there is no typed event for a trash purge), so its deprecation is unavoidable here.
			/** @psalm-suppress DeprecatedMethod */
			\OCP\Util::connectHook('\OCP\Trashbin', 'preDelete', $purgeHook, 'preDelete');
		}
	}
}
