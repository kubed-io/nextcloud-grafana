<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * The move half of the file-lifecycle (Course 4 · Slice 2b, `move.feature`). Driven by
 * {@see \OCA\GrafanaSync\Listener\MotionListener} on the post-move `NodeRenamedEvent`.
 *
 * A move is classified by **where the file lands** — the resolved contract (saga Round 5):
 *
 *  - **within the same mapping** (or a relocation that crosses no mapping boundary) → nothing
 *    to do on the Grafana side (a title/rename reconcile is a separate concern).
 *  - **mapped → a different mapped folder** → a genuine Grafana **folder move**: the file's
 *    dashboard is re-parented into the destination mapping's folder (upsert with the new
 *    `folderUid`), the **uid is kept** (both sides are real folders, no delete), and the file
 *    re-stamps `grafana_mapping`. A `link` file just re-homes its pointer (Grafana untouched).
 *  - **mapped → out of every mapping** → the file's content is already safe in Nextcloud, so
 *    (recycle-bin OFF, the default) we **delete** the dashboard in Grafana and **strip the
 *    file's identity** — it becomes a plain, untracked `.grafana.json`. Moving it back into a
 *    mapping then rides create-on-land ({@see CreateService}) — a fresh dashboard, new uid.
 *    (The recycle-bin ON path — park in the bin folder, keep the uid — is a fast-follow;
 *    move.feature marks it @todo.)
 *
 * Loop safety: metadata/tag writes here run inside {@see SyncGuard}, so they never re-enter
 * the writeback listener. An unmanaged file (no uid) is left to {@see CreateService} via the
 * create listener's own `NodeRenamedEvent` handling — this service only touches managed files.
 *
 * Scope — same-storage moves only: Nextcloud fires `NodeRenamedEvent` for a move *within one
 * storage* (regular folder ↔ regular folder, and rename/subfolder within either). A move that
 * crosses a storage boundary — notably into or out of a **Team Folder** (a groupfolders mount)
 * — is a copy+delete under the hood and fires `NodeDeletedEvent` (+ a create on the far side),
 * NOT `NodeRenamedEvent`, so it never reaches this service. Team-folder re-homing therefore
 * rides the delete/create lifecycle, not the move engine; wiring that path (and the
 * team-folder-aware resolution create-on-land needs to re-adopt on the way in) is a fast-follow.
 * As defence in depth, {@see onMove} still refuses to delete on any destination path it can't
 * positively classify as a normal `…/files/…` path, so a stray delete stays impossible.
 */
final class MotionService {
	public function __construct(
		private MappingService $mappings,
		private DashboardMetadata $metadata,
		private GrafanaClient $grafana,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile Grafana to a completed move of $node (its new path) from $fromPath.
	 * Throws if a required Grafana call fails, so the caller can surface it — we never
	 * strip a file's identity unless the Grafana delete actually confirmed.
	 */
	public function onMove(File $node, string $fromPath): void {
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			return; // unmanaged → create-on-land (CreateInGrafanaListener) owns a move-in
		}
		$from = $this->mappings->resolveForPath($fromPath);
		$to = $this->mappings->resolveForPath($node->getPath());
		if (($from?->id) === ($to?->id)) {
			return; // same mapping (rename / subfolder move) or a boundary-less relocation
		}

		if ($to !== null) {
			$this->onEnterMapping($node, $managed, $to);
			return;
		}

		// $to === null means "no mapping resolved the destination". We treat that as a true
		// move-OUT (delete + strip) ONLY when the file landed on a normal per-user
		// `…/files/<folder>/…` path — the shape resolveForPath actually understands. On any
		// other path shape (a special mount whose segments the resolver can't place) a null
		// mapping is NOT proof the file left every mapping, so we never issue the destructive
		// delete on that doubt — we leave the file's identity intact. Defence in depth: with
		// today's Nextcloud event routing a same-storage rename can't hand us such a path
		// (a move that crosses into a Team Folder is a copy+delete, not a rename, so it never
		// reaches this service at all), but this keeps a stray delete impossible regardless.
		if (!$this->isUserFileTreePath($node->getPath())) {
			$this->logger->info('grafana_sync: move destination is not a classifiable user-file path; leaving the file identity intact rather than treating it as a delete', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
			]);
			return;
		}

		$this->onLeaveMapping($node, $managed);
	}

	/**
	 * True when a path is a normal per-user file-tree path (`…/files/…`) — the shape
	 * {@see MappingService::resolveForPath} understands. A false result means a path shape
	 * the resolver can't place, so a null mapping there is not proof the file left every
	 * mapping — see {@see onMove} for why we never delete on that ambiguity.
	 */
	private function isUserFileTreePath(string $path): bool {
		return (bool)preg_match('#/files/.+$#', $path);
	}

	/**
	 * mapped → a different mapped folder. Sync: re-parent the dashboard into the
	 * destination folder (uid kept). Link: just re-home the pointer's mapping.
	 */
	private function onEnterMapping(File $node, ManagedFile $managed, Mapping $to): void {
		if ($managed->isLink()) {
			$this->guard->run(fn () => $this->metadata->write($node->getId(), [DashboardMetadata::KEY_MAPPING => $to->id]));
			return;
		}

		$spec = $this->decodeSpec($node->getContent());
		$spec->uid = $managed->uid; // identity is the metadata uid, never the file's typed value
		$folderUid = $to->grafanaFolderUid === '/' ? null : $to->grafanaFolderUid;
		$resp = $this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $folderUid, $node->getName()));

		$update = [DashboardMetadata::KEY_MAPPING => $to->id];
		$version = isset($resp['version']) ? (string)$resp['version'] : '';
		if ($version !== '') {
			$update[DashboardMetadata::KEY_VERSION] = $version;
		}
		$this->guard->run(fn () => $this->metadata->write($node->getId(), $update));
	}

	/**
	 * mapped → out of every mapping. Sync: the file holds the full JSON (content safe in
	 * Nextcloud), so delete the dashboard in Grafana and strip the file's identity. Link:
	 * MoveGuardListener refuses a link move-out, so this is only reached defensively — just
	 * strip the pointer (a link never owned the dashboard, so nothing is deleted).
	 */
	private function onLeaveMapping(File $node, ManagedFile $managed): void {
		if (!$managed->isLink()) {
			// Delete FIRST; if Grafana can't confirm it, the exception propagates and we do
			// NOT strip — the file keeps its identity and stays reconcilable (no data lost).
			$this->grafana->deleteDashboard($managed->uid);
		}
		$this->stripIdentity($node);
	}

	/** Wipe the file's managed metadata + ownership pill under the guard. */
	private function stripIdentity(File $node): void {
		$this->guard->run(function () use ($node): void {
			$this->metadata->clear($node->getId());
		});
	}

	private function decodeSpec(string $content): \stdClass {
		try {
			$decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException('The dashboard file is not valid JSON: ' . $e->getMessage(), 0, $e);
		}
		if (!$decoded instanceof \stdClass) {
			throw new \RuntimeException('The dashboard file is not a JSON object.');
		}
		return $decoded;
	}
}
