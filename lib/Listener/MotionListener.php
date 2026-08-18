<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\MotionService;
use OCA\GrafanaSync\Service\ResolvesActingUser;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Reconciles Grafana to a completed move of a managed dashboard file (`move.feature`).
 * NC fires {@see NodeRenamedEvent} for both renames and moves; {@see MotionService}
 * decides what (if anything) to do from where the file landed. The *before* gate that
 * can refuse a move (a link move-out) is {@see MoveGuardListener}.
 *
 * A move never fires `NodeWrittenEvent`, so this is the only signal that a managed file
 * changed folders. A failure (Grafana unreachable mid re-parent/delete) is logged and
 * surfaced as a notification — the move already happened on the NC side, so we can't
 * abort here; MotionService is written so a failed Grafana delete leaves the file's
 * identity intact (reconcilable, never data-lost).
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
final class MotionListener implements IEventListener {
	use ResolvesActingUser;

	public function __construct(
		private MotionService $motionService,
		private SyncGuard $guard,
		private IUserSession $userSession,
		private SyncNotifier $notifier,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeRenamedEvent) {
			return;
		}
		if ($this->guard->active()) {
			return; // our own pull/stamp writes never re-enter
		}
		$target = $event->getTarget();
		if (!FilenameCodec::isDashboardFile($target)) {
			return;
		}
		/** @var \OCP\Files\File $target — isDashboardFile guarantees a File */

		try {
			$this->motionService->onMove($target, $event->getSource()->getPath());
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync move reconcile failed', [
				'app' => Application::APP_ID,
				'fileId' => $target->getId(),
				'path' => $target->getPath(),
				'exception' => $e,
			]);
			$uid = $this->actingUserUid($target);
			if ($uid !== '') {
				$this->notifier->failed($uid, $target->getId(), $target->getName(), GrafanaClient::describeConnectionError($e));
			}
		}
	}
}
