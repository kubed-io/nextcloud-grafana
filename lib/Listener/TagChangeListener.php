<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\NextcloudTags;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TagSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use Psr\Log\LoggerInterface;

/**
 * A tag was put on or taken off something in the Files app (`dashboards/tags.feature`,
 * `folders/tags.feature`).
 *
 * ## WHY `MapperEvent` AND NOT THE TYPED EVENTS
 *
 * Nextcloud has `TagAssignedEvent` / `TagUnassignedEvent`, which are the nicer pair —
 * and they are `@since 32`, while `info.xml` declares `min-version="31"`. `MapperEvent`
 * has been dispatched since 9 and is still dispatched today alongside the typed ones,
 * so it is the version-portable choice. It is registered by its **string** event
 * names: the mapper sends it through `dispatch($name, $event)` rather than
 * `dispatchTyped()`, so a `MapperEvent::class` registration would never fire.
 *
 * ## WHY IT RE-READS INSTEAD OF USING THE EVENT'S TAGS
 *
 * The event carries only the tags that moved, and only in one direction. Adding two
 * tags and removing one is three ids across two events, and acting on each in turn
 * would push three times and race itself. So the event is treated purely as a
 * NOTIFICATION — the current whole set is read back, and that set is what travels.
 * It also means an assign and its matching unassign converge on the same answer.
 *
 * ## THE GUARD
 *
 * A pull importing tags assigns them, which lands right back here. {@see SyncGuard}
 * covers that, and {@see TagSyncService} compares sets on top of it for the case the
 * guard cannot see — a scheduled pull and a user's click in two different requests.
 *
 * @implements IEventListener<MapperEvent>
 */
final class TagChangeListener implements IEventListener {
	public function __construct(
		private TagSyncService $tagSync,
		private NextcloudTags $ncTags,
		private SyncGuard $guard,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof MapperEvent) {
			return;
		}
		if ($event->getObjectType() !== 'files') {
			return;
		}
		if ($this->guard->active()) {
			return; // our own import, arriving back
		}

		$fileId = (int)$event->getObjectId();
		if ($fileId <= 0) {
			return;
		}

		$node = $this->resolve($fileId);
		if ($node === null) {
			return;
		}

		try {
			// The whole current set, not the delta the event carries — see the class
			// docblock. INSIDE the try on purpose: reading the tags can now throw, and
			// acting on a partial set would push a tag removal nobody asked for.
			$wanted = $this->ncTags->of($fileId);

			if ($node instanceof Folder) {
				$this->tagSync->pushFolder($node, $wanted);
				return;
			}
			if ($node instanceof File && FilenameCodec::isDashboardFile($node)) {
				$this->tagSync->pushDashboard($node, $wanted);
			}
		} catch (\Throwable $e) {
			// Never let a tag click fail. The tag is already applied in Nextcloud; the
			// far side catches up on the next sync.
			$this->logger->warning('grafana_sync: could not carry a tag change to Grafana', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * The node behind a file id.
	 *
	 * A tag event carries an id and nothing else — no user, no path — so the node has
	 * to be looked up. The session user is tried first because that is whose Files app
	 * raised it and whose mount the node lives in; the id-based fallback covers an
	 * `occ` or background context with no session.
	 */
	private function resolve(int $fileId): File|Folder|null {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid !== null && $uid !== '') {
			try {
				$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
				if ($node instanceof File || $node instanceof Folder) {
					return $node;
				}
			} catch (\Throwable) {
				// fall through to the id lookup
			}
		}

		try {
			$node = $this->rootFolder->getFirstNodeById($fileId);
			return $node instanceof File || $node instanceof Folder ? $node : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
