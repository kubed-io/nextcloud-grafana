<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * The purge step for **every trash that is not the home one** — which in this app
 * means the Team Folders its `team folder` mappings actually use.
 *
 * ## THE HOME TRASH'S PURGE SIGNAL DOES NOT EXIST FOR A TEAM FOLDER
 *
 * {@see TrashPurgeHook} listens to the legacy `\OCP\Trashbin` `preDelete` hook, and
 * that hook is emitted by exactly one place in Nextcloud: `Files_Trashbin\Trashbin`.
 * groupfolders implements its own trash backend, and its `removeItem()` is four lines
 * that emit **nothing at all** — no legacy hook, no typed event:
 *
 *     $node->getStorage()->unlink($node->getInternalPath());
 *     $node->getStorage()->getCache()->remove($node->getInternalPath());
 *
 * So emptying a Team Folder's trash was completely silent, and the dashboard it was
 * supposed to finish off stayed parked in the recycle-bin folder forever. Found on the
 * n8n sibling in live use (its saga is blunt about how a fully-tested feature file
 * missed it); ported here before this app could reproduce the outage.
 *
 * ## `CacheEntryRemovedEvent` IS THE SIGNAL, BECAUSE IT IS THE ONE THING BOTH DO
 *
 * Whatever a trash backend emits, it must drop the file's cache entry to destroy it —
 * that line is right there in groupfolders' `removeItem()`. `Cache::remove()` dispatches
 * {@see CacheEntryRemovedEvent}, a typed OCP event carrying the file id, so this needs no
 * node, no session, no filesystem setup and no knowledge of which backend ran.
 *
 * **The metadata is still readable when it fires — on every supported server, only
 * because of the registration priority.** See the note beside the registration in
 * {@see \OCA\GrafanaSync\AppInfo\Application}: on Nextcloud 32/33 core cleans up
 * `files_metadata` from a listener on this SAME event at default priority, so this one
 * must run earlier or the stamp is gone before it can be read.
 *
 * ## SCOPED THREE WAYS, BECAUSE THIS EVENT IS NOT ABOUT THE TRASH
 *
 * It fires for every cache-entry removal anywhere in the instance:
 *
 *   1. NOT the home trash. `files_trashbin/…` is {@see TrashPurgeHook}'s, which runs
 *      BEFORE the unlink with the node still resolvable. That path works and is left
 *      exactly as it is; this covers what it cannot see.
 *   2. The TRASHED name shape (`<stem>.grafana.d<timestamp>`). A file only ever
 *      carries that spelling while it is in a trash, so this rules out every ordinary
 *      delete — including this app's own permanent delete of a `link` file, which
 *      unlinks a file still named `<stem>.grafana`.
 *   3. This app's metadata. {@see DeleteService::hardDelete} enforces the mode and the
 *      still-parked check itself; a link's dashboard is never Nextcloud's to destroy.
 *
 * Plus the {@see SyncGuard}, so the app's own trash housekeeping never comes back
 * round and asks Grafana to delete a dashboard it just proved was already gone.
 *
 * ## A TRASHED FOLDER PURGED WHOLE IS STILL NOT COVERED, DELIBERATELY
 *
 * `removeChildren()` dispatches the plural `CacheEntriesRemovedEvent` FIRST — so by the
 * time the per-child events arrive, core has already dropped their metadata and there
 * is no dashboard uid left to act on. The home trash covers folders through
 * {@see \OCA\GrafanaSync\Service\FolderCascade}; the Team Folder half of that story is
 * future work and is recorded as such rather than half-done here.
 *
 * @implements IEventListener<CacheEntryRemovedEvent>
 */
final class TeamFolderPurgeListener implements IEventListener {
	/** The home trash, which {@see TrashPurgeHook} already covers on a better signal. */
	private const HOME_TRASH_PREFIX = 'files_trashbin/';

	public function __construct(
		private DeleteService $deleteService,
		private DashboardMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof CacheEntryRemovedEvent) {
			return;
		}
		if ($this->guard->active()) {
			return;
		}

		$path = $event->getPath();
		if (str_starts_with($path, self::HOME_TRASH_PREFIX)) {
			return;
		}
		// Cheapest first: this event fires for every cache-entry removal in the
		// instance, and the overwhelming majority are not even `.grafana` files.
		// Nothing is logged above this line for the same reason.
		if (!FilenameCodec::isTrashedDashboardName(basename($path))) {
			return;
		}

		// PAST THIS POINT EVERY BAIL SAYS WHY. A trashed dashboard file really was
		// purged out of some trash; if this app then does nothing, the reason it did
		// nothing is the only thing worth knowing.
		$fileId = $event->getFileId();
		$managed = $this->metadata->read($fileId);
		if (!$managed?->isManaged()) {
			$this->logger->debug('grafana_sync purge: purged trashed file carries no Grafana metadata', [
				'app' => Application::APP_ID,
				'path' => $path,
				'fileId' => $fileId,
			]);
			return;
		}

		// "a trash the legacy hook cannot see", not "a Team Folder", even though a Team
		// Folder is the only one in practice: this listener never learns which backend
		// ran, so naming one in the log would be a guess printed as a fact.
		$this->logger->debug('grafana_sync purge: finishing the delete of a file purged from a trash the legacy hook cannot see', [
			'app' => Application::APP_ID,
			'path' => $path,
			'fileId' => $fileId,
			'uid' => $managed->uid,
		]);

		try {
			$this->deleteService->hardDelete($managed);
		} catch (\Throwable $e) {
			// Log and swallow, exactly as the home-trash purge does: the file is already
			// destroyed by the time this event exists, so there is nothing left to abort.
			// A dashboard left parked in Grafana is a leak the admin can clean up by hand
			// — never data loss, and never a reason to fail the delete that caused it.
			$this->logger->warning('grafana_sync purge: could not delete the parked dashboard in Grafana', [
				'app' => Application::APP_ID,
				'path' => $path,
				'fileId' => $fileId,
				'uid' => $managed->uid,
				'exception' => $e,
			]);
		}
	}
}
