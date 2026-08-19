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
use OCA\GrafanaSync\Service\FolderCascade;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
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
		private FolderCascade $cascade,
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

		// A FOLDER COPY FIRES ONCE, FOR THE FOLDER. Nextcloud satisfies a recursive
		// copy server-side and raises a single `NodeCopiedEvent` for the node the user
		// named — the files inside it get no event of their own. So this listener,
		// which only ever recognised dashboard FILES, did nothing at all when someone
		// duplicated a folder: the copies kept the originals' inherited stamps, no new
		// dashboards were made, and no Grafana folder was created to hold them.
		//
		// Walking is the whole fix. Each file goes through the same `onCopy` a
		// single-file copy uses, and the Grafana folder appears as a CONSEQUENCE of the
		// first dashboard landing in it ({@see \OCA\GrafanaSync\Service\FolderMirror})
		// rather than needing a step of its own — which is the same rule
		// `folders/create.feature` states: a folder is in Grafana when a dashboard is.
		foreach ($this->copiedDashboards($node) as $file) {
			try {
				$this->copyService->onCopy($file);
			} catch (\Throwable $e) {
				// The NC copy already happened; a failed registration is just an untracked
				// .grafana the user can re-save to retry. Log, never rethrow — and never
				// let one file stop the rest of a copied folder from registering.
				$this->logger->warning('grafana_sync copy handling failed', [
					'app' => Application::APP_ID,
					'fileId' => $file->getId(),
					'path' => $file->getPath(),
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * The dashboard files a copy produced: the node itself, or every one beneath it.
	 *
	 * The walk belongs to {@see FolderCascade}, which already does it for the trash
	 * and the purge — one tree walk with one set of edge cases, rather than a second
	 * copy of it here that would drift from the first.
	 *
	 * @return list<File>
	 */
	private function copiedDashboards(Node $node): array {
		if ($node instanceof Folder) {
			return $this->cascade->dashboardFilesIn($node);
		}
		return $node instanceof File && FilenameCodec::isDashboardFile($node) ? [$node] : [];
	}
}
