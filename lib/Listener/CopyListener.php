<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\CopyService;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use Psr\Log\LoggerInterface;

/**
 * Copy handling (Course 4 · Slice 1, `copy.feature`). NC fires `NodeCopiedEvent`
 * (not `NodeWrittenEvent`) when a file is copied, so create-on-land alone would miss a
 * copied dashboard. This listener routes the copy to {@see CopyService}, which strips
 * the inherited identity and — if the copy landed in a mapped sync folder — registers
 * it as a brand-new dashboard with its own uid.
 *
 * @implements IEventListener<NodeCopiedEvent>
 */
final class CopyListener implements IEventListener {
	public function __construct(
		private CopyService $copyService,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof NodeCopiedEvent) {
			return;
		}
		if ($this->guard->active()) {
			return; // our own writes never re-enter
		}
		$node = $event->getTarget();
		if (!FilenameCodec::isDashboardFile($node)) {
			return;
		}
		/** @var \OCP\Files\File $node — isDashboardFile guarantees a File */

		try {
			$this->copyService->onCopy($node);
		} catch (\Throwable $e) {
			// The NC copy already happened; a failed registration is just an untracked
			// .grafana the user can re-save to retry. Log, never rethrow.
			$this->logger->warning('grafana_sync copy handling failed', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
				'exception' => $e,
			]);
		}
	}
}
