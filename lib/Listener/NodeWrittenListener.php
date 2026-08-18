<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\BackgroundJob\PushDashboardJob;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\PushService;
use OCA\GrafanaSync\Service\ResolvesActingUser;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCA\GrafanaSync\Service\WritebackStrategy;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Pushes a saved dashboard file back to Grafana (Course 3 writeback). NodeWrittenEvent
 * fires for the text editor, WebDAV PUTs, and desktop-client syncs.
 *
 * The decision is made entirely from the file's own Files-Metadata (stamped on pull),
 * so it survives renames/moves and needs no path/mapping lookup. We push only when
 * **all** hold:
 *   - guard not active (i.e. not our own pull/push write),
 *   - name ends in `.grafana` (cheap bail for everything else),
 *   - the file is ours (`grafana_uid` set) and its mode is `sync` (link and
 *     unmapped never push),
 *   - the content actually changed since the last sync (sha1 ≠ `grafana_syncedHash`) —
 *     the loop guard against re-pushing our own / unchanged content.
 *
 * @implements IEventListener<NodeWrittenEvent>
 */
final class NodeWrittenListener implements IEventListener {
	use ResolvesActingUser;

	public function __construct(
		private IJobList $jobList,
		private WritebackStrategy $strategy,
		private PushService $pushService,
		private DashboardMetadata $metadata,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private SyncNotifier $notifier,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof NodeWrittenEvent)) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isDashboardFile($node)) {
			return;
		}

		// NO MIMETYPE RE-STAMP HERE ANY MORE. Under the old `.grafana.json` extension this
		// listener re-ran a table-wide filecache UPDATE on every single write, because NC's
		// scanner re-detected the mime off the path's last extension (`.json` →
		// application/json) and clobbered our row each time. `.grafana` is the last
		// extension, so core's own detector returns application/grafana+json and there is
		// nothing left to correct. The registration in RegisterMimetype is the whole story.
		if ($this->guard->active()) {
			return;
		}

		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // not (yet) one of ours — new-file create is Course 4
		}
		if (!$managed->isSync()) {
			return; // only sync pushes; link/unmapped never do
		}

		try {
			$content = $node->getContent();
		} catch (\Throwable) {
			return;
		}
		if ($managed->syncedHash === sha1($content)) {
			return; // unchanged since last sync (or our own write) — loop guard
		}

		// Who to notify if the push fails (and which Files view the async job re-resolves
		// the node through).
		$uid = $this->actingUserUid($node);

		// QUEUED WHEN THAT WILL ACTUALLY RUN, INLINE OTHERWISE. The admin radio that
		// used to answer this is gone; {@see WritebackStrategy} derives it from whether
		// there is a user for the job to act as and whether anything drains the queue.
		if ($this->strategy->canQueue($uid)) {
			// Defer to the job, which pushes and surfaces its own failure toast.
			$this->jobList->add(PushDashboardJob::class, ['fileId' => $node->getId(), 'userId' => $uid]);
			return;
		}

		// Inline push. Best-effort: never let a writeback failure break the user's save —
		// surface it as a notification (Grafana's own message) instead.
		try {
			if ($this->pushService->push($node)) {
				$this->notifier->cleared($node->getId());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync writeback failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'exception' => $e,
			]);
			// Curate the toast the same way the rest of the app does: a 401/403 reads as
			// "token rejected", not Grafana's raw text.
			$this->notifier->failed($uid, $node->getId(), $node->getName(), GrafanaClient::describeConnectionError($e));
		}
	}
}
