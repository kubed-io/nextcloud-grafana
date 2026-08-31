<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Trashing a mirrored FOLDER (`folders/delete.feature`) — the folder counterpart of
 * {@see DeleteToGrafanaListener}, and the highest blast radius in the app.
 *
 * ## WHY IT IS NOT THE FILE LISTENER DOING ITS JOB N TIMES
 *
 * Because the file listener never runs. Nextcloud fires exactly ONE
 * {@see BeforeNodeDeletedEvent} for a folder delete and none for anything inside it —
 * see {@see FolderCascade} for the live verification. Until this listener existed,
 * dragging a folder of dashboards to the trash did nothing at all in Grafana.
 *
 * ## THE TWO ANSWERS
 *
 * **A link folder is refused.** Under a link mapping the tree is Grafana's — the
 * dashboards, the folders, and the shape of both — and Nextcloud is a read-only mirror
 * with the one addition that other file types may sit alongside. Honouring the trash
 * locally and leaving Grafana alone is the half-honoured shape the single-link rule
 * already rejects: the folder leaves the mirror, the next pull writes it straight back,
 * and in between the two sides disagree for no reason anyone chose.
 *
 * **A sync folder is carried through**, dashboards and folder both, by
 * {@see FolderCascade::trash()} — which holds the bin-on / bin-off rule table.
 *
 * ## WHAT IT NEVER TOUCHES
 *
 * An unstamped folder. A folder under a mapping is a plain folder until a dashboard
 * lands beneath it, so a folder this app never stamped is the user's own and its delete
 * is none of our business. That also keeps the MAPPED folder out of reach — a mapping's
 * own folder is never stamped — which is deliberate: see {@see FolderCascade}.
 *
 * @implements IEventListener<BeforeNodeDeletedEvent>
 */
final class FolderDeleteListener implements IEventListener {
	public function __construct(
		private FolderMetadata $folders,
		private MappingService $mappings,
		private FolderCascade $cascade,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent) {
			return;
		}
		// The pull removes mirrored folders to follow Grafana; without this that would
		// bounce straight back as a fresh delete.
		if ($this->guard->active()) {
			return;
		}

		$folder = $event->getNode();
		if (!$folder instanceof Folder) {
			return; // files are DeleteToGrafanaListener's
		}

		try {
			$uid = $this->folders->uidOf($folder->getId());
		} catch (\Throwable) {
			return; // cannot classify it → never act, and never block
		}
		if ($uid === '') {
			return; // a folder the user made for their own reasons
		}

		// A folder arriving here at a trashbin path is a trash-BYPASSED delete (trash
		// disabled, or X-NC-Skip-Trashbin): there will be no purge hook later, so this is
		// the only chance to finish the job. The ordinary purge rides TrashPurgeHook.
		if ($this->isInTrashbin($folder->getPath())) {
			$this->cascade->purge($folder);
			return;
		}

		$mapping = $this->mappings->resolveForPath($folder->getPath());
		if ($mapping === null) {
			// Stamped but out of every mapping. Nothing here knows which Grafana this
			// folder belongs to any more, and a delete is the wrong gesture to guess on.
			$this->logger->info('grafana_sync: a mirrored folder outside every mapping was deleted; Grafana not changed', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'path' => $folder->getPath(),
			]);
			return;
		}

		if ($mapping->mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'This folder mirrors Grafana, which owns the folders under a link mapping. '
				. 'Delete it in Grafana and the mirror will follow.',
			);
		}

		try {
			$this->cascade->trash($folder, $uid);
		} catch (\Throwable $e) {
			// Never desync: if Grafana would not confirm, the Nextcloud delete does not
			// happen either, and every dashboard keeps the identity that lets the next
			// sync settle it.
			$this->logger->warning('grafana_sync: folder trash failed; aborting the Nextcloud delete', [
				'app' => Application::APP_ID,
				'folderId' => $folder->getId(),
				'uid' => $uid,
				'exception' => $e,
			]);
			throw new AbortedEventException('Couldn’t sync the folder delete to Grafana: ' . $e->getMessage());
		}
	}

	/**
	 * True when the node path is inside the user's trashbin (`/<uid>/files_trashbin/files/…`).
	 * Same shape as {@see DeleteToGrafanaListener::isInTrashbin()}.
	 */
	private function isInTrashbin(string $path): bool {
		$segments = explode('/', ltrim($path, '/'));
		return count($segments) >= 3
			&& $segments[1] === 'files_trashbin'
			&& $segments[2] === 'files';
	}
}
