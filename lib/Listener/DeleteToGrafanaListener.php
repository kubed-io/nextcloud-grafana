<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DeleteService;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\RecycleBin;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the user's Nextcloud delete into Grafana (`delete.feature`). NC's "Delete file" fires
 * {@see BeforeNodeDeletedEvent} **before** the storage unlink, and the View-layer dispatch honours
 * {@see AbortedEventException} — throwing it aborts the delete. That's exactly the safety we want:
 * if the Grafana step can't confirm, the file stays put rather than desyncing the two systems.
 *
 * This event's normal job is the **soft step**: the first delete, with the file at its normal
 * path, on its way to trash. The **hard step** — permanently emptying the trash — does NOT fire a
 * typed event (proven live); it rides the legacy `\OCP\Trashbin` `preDelete` hook, handled by
 * {@see TrashPurgeHook}. The `isInTrashbin` branch below is kept only for a trash-*bypassed* direct
 * delete (trash disabled, `X-NC-Skip-Trashbin`, or another listener called `disableTrashBin()`) that
 * would land a still-managed sync file's delete here with a trashbin path — we treat that as the
 * hard step so the dashboard still gets deleted. {@see DeleteService} holds the rule table.
 *
 * Restore is handled by {@see RestoreFromTrashListener}; empty-trash by {@see TrashPurgeHook}.
 *
 * @implements IEventListener<BeforeNodeDeletedEvent>
 */
final class DeleteToGrafanaListener implements IEventListener {
	public function __construct(
		private DeleteService $deleteService,
		private DashboardMetadata $metadata,
		private RecycleBin $recycleBin,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent) {
			return;
		}
		if ($this->guard->active()) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isDashboardFile($node)) {
			return;
		}
		/** @var \OCP\Files\File $node — isDashboardFile guarantees a File */

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // an untracked / already-stripped file — no Grafana side, let NC delete it
		}

		// A LINK IS NOT NEXTCLOUD'S TO DELETE. The file is a read-only projection of a
		// dashboard that lives in Grafana and is perfectly fine; removing the pointer only
		// makes the mapped folder disagree with the Grafana folder it mirrors, and the next
		// pull writes the file straight back — so the delete was never durable, it was just
		// silent. Refusing says so at the moment the user asks.
		//
		// The same rule the DAV guard already enforces for content and existence
		// ({@see \OCA\GrafanaSync\DAV\LinkWriteGuardPlugin}); this is the backstop for
		// every route that never touches Sabre. The way OUT of a link folder is to delete
		// the dashboard in Grafana, or to remove the mapping — both decisions about the
		// mapping rather than about one file.
		if ($managed->isLink()) {
			throw new AbortedEventException(
				'This file is a link to a dashboard in Grafana, so it cannot be deleted from Nextcloud. '
				. 'Delete the dashboard in Grafana instead.',
			);
		}

		$isHardStep = $this->isInTrashbin($node->getPath());
		try {
			if ($isHardStep) {
				$this->deleteService->hardDelete($managed);
			} else {
				// Resolve the bin folder (null when bin mode is off); throws if bin mode is on
				// but the folder is unusable — we abort rather than fall back to a true delete.
				$binUid = $this->recycleBin->activeFolderUid();
				$this->deleteService->softDelete($node, $managed, $binUid);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync ' . ($isHardStep ? 'purge' : 'trash') . ' failed; aborting the Nextcloud delete', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'uid' => $managed->uid,
				'exception' => $e,
			]);
			throw new AbortedEventException(
				($isHardStep
					? 'Couldn’t delete the dashboard in Grafana: '
					: 'Couldn’t sync the delete to Grafana: ')
				. $e->getMessage(),
			);
		}
	}

	/**
	 * True when the node path is inside the user's trashbin (`/<uid>/files_trashbin/files/…`).
	 * NC node paths are slash-rooted at the storage root, so a prefix match on the second
	 * segment is enough; we don't try to pin the uid. Mirrors the n8n master.
	 */
	private function isInTrashbin(string $path): bool {
		$segments = explode('/', ltrim($path, '/'));
		return count($segments) >= 3
			&& $segments[1] === 'files_trashbin'
			&& $segments[2] === 'files';
	}
}
