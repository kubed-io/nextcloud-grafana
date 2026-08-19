<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\DAV;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Listener\RestoreFromTrashListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\RestoreInProgress;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Brackets a WebDAV restore-from-trash: says so before it starts, and re-attaches the
 * restored file to its dashboard when it lands.
 *
 * {@see RestoreInProgress} carries the full account of why this is needed — in short,
 * `Sabre\DAV\Tree::move()` cannot rename across collections, so a restore is a copy plus
 * a delete, the delete half runs the real purge and destroys the parked dashboard, and
 * no restore event fires anywhere.
 *
 * ## BOTH HALVES ARE SET HERE, BECAUSE ONLY HERE KNOWS
 *
 * `beforeMove` is the last moment the trashed node still exists to be read, and the
 * first moment anything knows a restore is happening. It does two things: raises the
 * flag the purge hook checks, and copies the file's stamp aside. `afterMove` then
 * re-applies that stamp to the file that landed, so the mirror points at the dashboard
 * it always pointed at rather than at a newly minted one.
 *
 * ## THE SOURCE MAKES IT A RESTORE
 *
 * A move whose DESTINATION is the trash is an ordinary delete and must keep behaving
 * like one. Only a move OUT of `trashbin/` is this gesture.
 *
 * ## IT NEVER REFUSES ANYTHING
 *
 * Observing only. Every lookup that cannot answer leaves the mark unset, which restores
 * the behaviour that existed before this plugin — a bug here costs what the bug it fixes
 * already cost, and never more.
 */
final class TrashRestorePlugin extends ServerPlugin {
	public function __construct(
		private RestoreInProgress $restore,
		private DashboardMetadata $metadata,
		private RestoreFromTrashListener $restoreListener,
		private IRootFolder $rootFolder,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	private ?Server $server = null;

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		// PRIORITY 10, ahead of Sabre's own `httpMove` (100): the mark has to exist
		// before the delete it describes, and the stamp has to be read before the node
		// carrying it is destroyed.
		$server->on('beforeMove', [$this, 'beforeMove'], 10);
		$server->on('afterMove', [$this, 'afterMove'], 10);
	}

	public function beforeMove(string $source, string $destination): bool {
		if (!$this->isTrashPath($source)) {
			return true;
		}
		$this->restore->mark();

		if (!FilenameCodec::isDashboardName(basename($destination))) {
			return true;
		}
		$fileId = $this->trashedFileId($source);
		if ($fileId === null) {
			return true;
		}

		// THE STAMP, READ WHILE IT STILL EXISTS. The copy that lands at the destination
		// gets a NEW file id and carries no metadata row of its own — a copy never does —
		// so without this the restored file looks brand new and create-on-land mints a
		// second dashboard beside the one being restored.
		$managed = $this->metadata->read($fileId);
		if ($managed === null || !$managed->isManaged()) {
			return true;
		}
		$this->restore->carry($destination, $managed);
		$this->logger->info('grafana_sync restore: a trashbin MOVE is under way; the dashboard is not being purged', [
			'app' => Application::APP_ID,
			'from' => $source,
			'to' => $destination,
			'uid' => $managed->uid,
		]);
		return true;
	}

	/** @return bool always true; this plugin observes and never refuses a move */
	public function afterMove(string $source, string $destination): bool {
		$managed = $this->restore->claim($destination);
		if ($managed === null) {
			return true;
		}
		try {
			$node = $this->server?->tree->getNodeForPath($destination);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync restore: could not resolve the restored node to re-stamp it', [
				'app' => Application::APP_ID,
				'path' => $destination,
				'exception' => $e,
			]);
			return true;
		}
		if (!$node instanceof DavFile) {
			return true;
		}

		// The stamp goes back on the NEW file id, inside the guard so the write does not
		// echo back as a user edit.
		$fileId = $node->getId();
		$this->guard->run(fn () => $this->metadata->write($fileId, [
			DashboardMetadata::KEY_UID => $managed->uid,
			DashboardMetadata::KEY_MAPPING => $managed->mappingId,
			DashboardMetadata::KEY_MODE => $managed->mode,
		]));

		// AND GRAFANA IS RECONCILED HERE TOO, because on this path nothing else will.
		// `Trashbin::restore()` never ran — Sabre did the copy and the delete itself — so
		// neither `NodeRestoredEvent` nor `post_restore` was emitted, and the two
		// listeners that answer a restore are both asleep. Left alone, the dashboard
		// would sit in the recycle-bin folder forever while its file looked restored.
		$file = $this->fileFor($fileId);
		if ($file !== null) {
			$this->restoreListener->restoreOne($file);
		}
		$this->logger->info('grafana_sync restore: re-attached the restored file to its dashboard', [
			'app' => Application::APP_ID,
			'path' => $destination,
			'fileId' => $fileId,
			'uid' => $managed->uid,
		]);
		return true;
	}

	/** The OCP file node behind an id, for handing to the restore listener. */
	private function fileFor(int $fileId): ?File {
		try {
			foreach ($this->rootFolder->getById($fileId) as $node) {
				if ($node instanceof File) {
					return $node;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync restore: could not resolve the restored file to reconcile Grafana', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
		return null;
	}

	/**
	 * The Nextcloud file id behind a node in the TRASHBIN tree.
	 *
	 * NOT `instanceof DavFile`, which is what this looked like at first and why the
	 * whole mechanism silently did nothing on its first live run: a node under
	 * `trashbin/` is a `Files_Trashbin\Sabre\AbstractTrash`, not the DAV connector's
	 * `File`, so the type check never matched and no stamp was ever carried.
	 *
	 * Duck-typed rather than imported, because `AbstractTrash` ships in an app that is
	 * REMOVABLE — the same reason {@see \OCA\GrafanaSync\Service\TrashControl}
	 * resolves its manager lazily. `getFileId()` is the one method needed and it is
	 * part of the `ITrash` interface, so anything answering it is answering honestly.
	 */
	private function trashedFileId(string $path): ?int {
		try {
			$node = $this->server?->tree->getNodeForPath($path);
		} catch (\Throwable $e) {
			$this->logger->debug('grafana_sync restore: could not resolve the trashed node', [
				'app' => Application::APP_ID,
				'path' => $path,
				'exception' => $e,
			]);
			return null;
		}
		if (!is_object($node) || !method_exists($node, 'getFileId')) {
			return null;
		}
		$id = $node->getFileId();
		return is_int($id) && $id > 0 ? $id : null;
	}

	private function isTrashPath(string $path): bool {
		return str_starts_with(ltrim($path, '/'), 'trashbin/');
	}
}
