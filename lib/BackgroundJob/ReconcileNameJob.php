<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\BackgroundJob;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardBody;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\PushService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Deferred half of the three-way name sync (`rename.feature`). The reconciliation has to
 * happen **out of the triggering request** because the file is locked during a rename —
 * writing its content inside the NodeRenamedEvent handler throws `OCP\Lock\LockedException`
 * ("existing lock on file"). So {@see \OCA\GrafanaSync\Listener\NameSyncListener} just enqueues
 * this job; by the time it runs the lock is released.
 *
 * Argument: `{ fileId:int, userId:string, action:'title_from_filename'|'filename_from_title' }`.
 *
 * - `title_from_filename` (a rename happened, or a copy landed): write the filename stem into
 *   the JSON `title` (guarded so the writeback doesn't echo), then push to Grafana directly so
 *   the dashboard title updates in one tick.
 * - `filename_from_title` (the JSON `title` was edited + saved): rename the file to match (the
 *   original save already pushed the title to Grafana via the writeback).
 *
 * Both actions first put the file into OUR spelling of a collision — see
 * {@see canonicaliseSpelling()}. That has to happen here rather than at the gesture for the
 * same reason the rest of this job does: a copy's own hook holds locks on the file it made.
 *
 * The stem this job reads is the filename's `display` name — the counter INCLUDED. A file
 * called `Board (1).grafana.json` is a dashboard called `Board (1)` when Nextcloud is the one
 * that named it, and taking the counter-stripped `name` instead is what let a copy reach
 * Grafana wearing the original's title.
 *
 * Idempotent: re-checks the gate + current values and no-ops if already in sync, so a
 * stale/duplicate enqueue is harmless.
 */
final class ReconcileNameJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private DashboardMetadata $metadata,
		private PushService $pushService,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!is_array($argument)) {
			return; // malformed / legacy payload — nothing to reconcile
		}
		$fileId = (int)($argument['fileId'] ?? 0);
		$uid = (string)($argument['userId'] ?? '');
		$action = (string)($argument['action'] ?? '');
		if ($fileId === 0 || $uid === '') {
			return;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->getById($fileId)[0] ?? null;
			if (!FilenameCodec::isDashboardFile($node)) {
				return;
			}
			/** @var \OCP\Files\File $node — isDashboardFile guarantees a File */
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged() || !$managed->isSync()) {
				return; // only a managed sync file name-syncs; link/unmapped are Grafana-driven
			}
			$dashUid = $managed->uid;

			$this->canonicaliseSpelling($node);

			$stem = FilenameCodec::displayName($node->getName());
			$spec = json_decode($node->getContent(), false);
			if (!$spec instanceof \stdClass) {
				return;
			}
			$jsonTitle = isset($spec->title) && is_string($spec->title) ? trim($spec->title) : '';

			if ($action === 'title_from_filename') {
				if ($stem === '' || $jsonTitle === $stem) {
					return; // already in sync
				}
				$spec->title = $stem;
				// The codebase's shared encode flags (includes JSON_THROW_ON_ERROR, so an encode
				// failure throws → caught + logged by the outer try, never a silent bad write).
				$encoded = json_encode($spec, DashboardBody::JSON_PRETTY);
				// Write the JSON title guarded (so the writeback / name-sync don't echo),
				// then push to Grafana ourselves — one tick, one push.
				$this->guard->run(function () use ($node, $encoded): void {
					$node->putContent($encoded);
				});
				$this->pushService->push($node);
			} elseif ($action === 'filename_from_title') {
				if ($jsonTitle === '' || $jsonTitle === $stem) {
					return; // already in sync (Grafana was pushed by the save's writeback)
				}
				$this->renameTo($node, $jsonTitle, $dashUid);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync name reconcile failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'action' => $action,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Put the file into OUR spelling of a collision counter, if it is wearing
	 * Nextcloud's.
	 *
	 * Nextcloud names a colliding copy `Board.grafana (1).json`, counting before the
	 * last extension because to Nextcloud our file is a `.json` called `Board.grafana`.
	 * Ours is `Board (1).grafana.json`, with the counter on the dashboard's name.
	 * {@see FilenameCodec::canonicalise()} reads both, which is what keeps the app
	 * WORKING; this is what stops the user having to look at a name that puts a counter
	 * inside a file extension.
	 *
	 * THIS IS THE ONLY THING THAT RENAMES A COPIED FILE, and it deliberately runs late.
	 * Rewriting the COPY's `Destination` header from a Sabre plugin gets the name right
	 * before the file exists — and breaks the Files app, which stats the path IT chose
	 * the moment the copy returns, and does so only when the copy landed in the folder it
	 * came from, which is exactly the case that collides. Measured live: intercepting
	 * gives COPY 201 then STAT 404 and an error dialog; deferring gives COPY 201, STAT
	 * 207, and the right name a tick later. See CopyService for the whole story.
	 *
	 * **EXACTLY THE CANONICAL NAME, OR NOTHING.** This must not go through
	 * {@see renameTo()}, which steps the counter until it finds a free name: with
	 * `Fleet Health (1).grafana.json` already taken, a client copy landing at
	 * `Fleet Health.grafana (1).json` would be renamed to `Fleet Health (1) (1).grafana.json` —
	 * a name nobody chose, in place of one that was working. The plugin's rule is that
	 * the client's name wins when ours is occupied, and the backstop has to agree with
	 * it or the two disagree about the same gesture depending on how it arrived.
	 */
	private function canonicaliseSpelling(File $node): void {
		$current = $node->getName();
		if (!FilenameCodec::isNextcloudSpelling($current)) {
			return;
		}
		$wanted = FilenameCodec::canonicalise($current);
		$parent = $node->getParent();
		if ($parent->nodeExists($wanted)) {
			return; // ours is taken; the client's name is the one that works
		}
		$node->move($parent->getPath() . '/' . $wanted);
	}

	/**
	 * Rename $node so its stem reads $display, stepping the collision counter until the
	 * name is free. No-ops when the file already has the name it wants — including the
	 * case where the free name IS the current one, which is how a file that legitimately
	 * carries a counter keeps it instead of fighting the file that took the plain name.
	 *
	 * The 1000 bound is a runaway guard, not a policy: a thousand files sharing one
	 * dashboard title is a broken mapping, and looping forever would be worse than
	 * leaving the name alone.
	 */
	private function renameTo(File $node, string $display, string $dashUid): void {
		if ($display === '') {
			return;
		}
		$parent = $node->getParent();
		$current = $node->getName();
		$collision = 0;
		while (true) {
			$candidate = FilenameCodec::format($display, $dashUid, false, $collision);
			if ($candidate === $current) {
				return; // already the name it wants
			}
			if (!$parent->nodeExists($candidate)) {
				break;
			}
			if (++$collision > 1000) {
				return;
			}
		}
		$node->move($parent->getPath() . '/' . $candidate);
	}
}
