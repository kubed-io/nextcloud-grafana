<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\BackgroundJob\ReconcileNameJob;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\IUserSession;

/**
 * Keeps three things equal for a managed **sync** dashboard file: the **filename stem**, the
 * JSON **`title`** key, and (via the writeback / a direct push) the **Grafana dashboard title**.
 * Renaming the file, or editing the `title` inside the JSON and saving, both end up reflected
 * everywhere. The stable link is the dashboard uid, so no rename ever breaks the connection.
 *
 * This listener only **decides + enqueues** — the actual file write/rename runs in
 * {@see ReconcileNameJob}. That deferral is required: during a rename the file is locked, so a
 * synchronous `putContent` here throws `LockedException`. Reads (to compare names) use a shared
 * lock and are safe even mid-rename.
 *
 * Authority follows what the user changed (so the two paths never fight, and the follow-up event
 * the job triggers finds things in sync and no-ops):
 *   - {@see NodeRenamedEvent}  → filename changed → `title_from_filename`.
 *   - {@see NodeWrittenEvent}  → content changed  → `filename_from_title`.
 *
 * Gate mirrors the writeback listener (metadata-only, survives moves): a `grafana_uid` + `sync`
 * file. link/unmapped/ignored stay Grafana-driven (a pull renames them). Bails under
 * {@see SyncGuard::active()} so pull/create writes don't reshuffle.
 *
 * @implements IEventListener<NodeWrittenEvent|NodeRenamedEvent>
 */
final class NameSyncListener implements IEventListener {
	public function __construct(
		private DashboardMetadata $metadata,
		private SyncGuard $guard,
		private IJobList $jobList,
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($this->guard->active()) {
			return;
		}
		$node = $this->resolveNode($event);
		if (!FilenameCodec::isDashboardFile($node)) {
			return;
		}
		/** @var \OCP\Files\File $node — isDashboardFile guarantees a File */

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // not managed yet — create-on-land owns the first write
		}
		if (!$managed->isSync()) {
			return; // only sync pushes back + name-syncs; link is Grafana-driven
		}

		$parsed = FilenameCodec::parse($node->getName());
		$stem = $parsed !== null ? trim($parsed['name']) : '';
		if ($stem === '') {
			return;
		}

		// Read (shared lock — safe even during a rename) to compare names and only enqueue on a
		// real mismatch.
		try {
			$spec = json_decode($node->getContent(), false);
		} catch (\Throwable) {
			return;
		}
		$jsonTitle = ($spec instanceof \stdClass && isset($spec->title) && is_string($spec->title)) ? trim($spec->title) : '';

		// The acting user resolves the file in the job (team-folder files are mounted per-user) —
		// same approach as the writeback's async push job.
		$uid = $this->userSession->getUser()?->getUID() ?? $node->getOwner()?->getUID() ?? '';
		if ($uid === '') {
			return;
		}

		if ($event instanceof NodeRenamedEvent) {
			if ($jsonTitle !== $stem) {
				$this->enqueue($node->getId(), $uid, 'title_from_filename');
			}
		} elseif ($jsonTitle !== '' && $jsonTitle !== $stem) {
			$this->enqueue($node->getId(), $uid, 'filename_from_title');
		}
	}

	private function enqueue(int $fileId, string $uid, string $action): void {
		$this->jobList->add(ReconcileNameJob::class, [
			'fileId' => $fileId,
			'userId' => $uid,
			'action' => $action,
		]);
	}

	private function resolveNode(Event $event): ?Node {
		if ($event instanceof NodeWrittenEvent) {
			return $event->getNode();
		}
		if ($event instanceof NodeRenamedEvent) {
			return $event->getTarget();
		}
		return null;
	}
}
