<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\BackgroundJob;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\PushService;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Async writeback path (Course 3). Enqueued by {@see \OCA\GrafanaSync\Listener\NodeWrittenListener}
 * when the Course-1 `timing` setting is `async` (the default), so a save returns to the
 * user immediately and the push to Grafana runs on the next cron tick.
 *
 * Looks the node up by file id (re-resolved through the saving user's Files view) and
 * delegates to {@see PushService}. Same contract as the inline path: a failure surfaces
 * as a native notification rather than dying silently in the cron log.
 *
 * Argument shape (set by `IJobList::add(self::class, [...])`):
 *   - `fileId` int    — the Node id to push
 *   - `userId` string — the saving user, for node re-resolution + failure notice
 */
final class PushDashboardJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PushService $pushService,
		private IRootFolder $rootFolder,
		private SyncNotifier $notifier,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$userId = (string)($argument['userId'] ?? '');
		if ($fileId === 0 || $userId === '') {
			$this->logger->warning('PushDashboardJob skipped: missing fileId or userId', [
				'app' => Application::APP_ID,
				'argument' => $argument,
			]);
			return;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		if ($node === null) {
			$this->logger->info('PushDashboardJob: file ' . $fileId . ' no longer exists for ' . $userId, [
				'app' => Application::APP_ID,
			]);
			return;
		}

		// Same contract as the inline path: surface Grafana's complaint to the user as a
		// notification rather than failing silently in the cron log.
		try {
			if ($this->pushService->push($node)) {
				$this->notifier->cleared($fileId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('PushDashboardJob: writeback failed', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			$this->notifier->failed($userId, $fileId, $node->getName(), GrafanaClient::describeConnectionError($e));
		}
	}
}
