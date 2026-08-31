<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * The delete/restore half of the file-lifecycle (Course 4 · Slice 3, `delete.feature`). The
 * three steps below are the whole rule table; the listeners delegate all the branching here so
 * it audits in one place. Grafana has **no native soft-delete**, so the model is re-plated
 * around the Nextcloud trash + one optional setting, the {@see RecycleBin}:
 *
 *  - **softDelete** — user moved the file to the NC trash (file still at its normal path, so its
 *    JSON is readable here).
 *      · link → nothing in Grafana (a link never owned the dashboard; trashing severs the local
 *        pointer only).
 *      · sync, bin OFF → the file (now in the trash) holds the full JSON, so **delete** the
 *        dashboard in Grafana and **strip the file's identity** (its uid is dead).
 *      · sync, bin ON → **move the dashboard into the bin folder** (id kept); metadata is left
 *        intact so restore knows the uid + mapping.
 *  - **hardDelete** — the final purge from the trash. Reached from {@see TrashPurgeHook} (the
 *    legacy `preDelete` hook), {@see TeamFolderPurgeListener} and the folder cascade — never
 *    from a typed delete event, because the only OTHER thing that unlinks a node inside the
 *    trashbin is a restore. Only a still-managed sync file reaches here (bin OFF stripped the id
 *    at softDelete, so its purge is a no-op the caller bails on): **permanently delete** the
 *    dashboard. This is the one
 *    irreversible moment when the bin is ON — and it deletes ONLY while the dashboard is still
 *    sitting in the bin folder, because that "bin" is an ordinary Grafana folder anyone can
 *    rescue a dashboard out of. See {@see hardDelete} for the full reasoning.
 *  - **restore** — user restored the file from the NC trash.
 *      · still managed (bin ON parked it, id kept) → **move the dashboard back** into its mapped
 *        folder, same uid (an idempotent upsert on the kept uid, so it also self-heals if the
 *        dashboard had somehow gone).
 *      · no longer managed (bin OFF stripped it) → **re-create** it from the file's JSON via
 *        create-on-land — a fresh dashboard, new uid.
 *      · link → nothing (its dashboard was never touched).
 *
 * Loop safety: the metadata/tag writes run inside {@see SyncGuard}. Error policy is the
 * caller's: the delete listener **aborts** the NC delete on failure (never desync), the restore
 * listener **logs + swallows** (never block a restore just because Grafana is down).
 */
final class DeleteService {
	public function __construct(
		private GrafanaClient $grafana,
		private DashboardMetadata $metadata,
		private CreateService $createService,
		private FolderMirror $folderMirror,
		private RecycleBin $recycleBin,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Soft step (trash-move). $binUid is the resolved bin folder uid when bin mode is on, else
	 * null. Throws if a required Grafana call fails, so the caller can abort the NC delete.
	 */
	public function softDelete(File $node, ManagedFile $managed, ?string $binUid): void {
		if ($managed->isLink()) {
			return; // a link never owned the dashboard — trashing severs only the local pointer
		}

		if ($binUid !== null) {
			// BIN ON: park the dashboard in the bin folder, keeping its uid. Metadata is left
			// intact (uid + mapping) so restore can move it back to the right folder.
			$spec = $this->decodeSpec($node->getContent());
			$spec->uid = $managed->uid;
			$this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $binUid, $node->getName()));
			return;
		}

		// BIN OFF: the file (now in the trash) holds the full JSON, so delete the dashboard now.
		// Delete FIRST; if Grafana can't confirm it, the exception propagates and we do NOT strip
		// — the file keeps its identity and stays reconcilable (never orphaned).
		$this->grafana->deleteDashboard($managed->uid);
		$this->stripIdentity($node);
	}

	/**
	 * Hard step (trash-purge, or a trash-bypassed direct delete). Bin ON: the real, permanent
	 * delete of the parked dashboard when the trash is emptied. Bin OFF trash-bypass: the file
	 * was never soft-deleted, so this is its only delete. Throws so the caller can abort.
	 *
	 * ── THE BIN IS A SHIM, SO THE PURGE MUST CHECK IT IS STILL THERE ─────────────────
	 *
	 * Grafana has no trashbin. What we call the recycle bin is an ORDINARY GRAFANA FOLDER
	 * the admin nominated, which means it is visible in Grafana's own UI and anyone with
	 * access can browse it, move things out of it, or delete it. A real trashbin is
	 * privileged storage; this is not.
	 *
	 * So a parked dashboard may legitimately have been RESCUED — someone saw it in the bin
	 * folder and dragged it back where it belonged. If this method still deleted by uid, the
	 * user emptying their Nextcloud trash weeks later would permanently destroy a live,
	 * in-use dashboard that somebody had deliberately saved. Grafana has no undo.
	 *
	 * The rule: **purge deletes the dashboard only while it is still sitting in the bin.**
	 * If it has moved somewhere else, or is already gone, we leave Grafana alone and let the
	 * Nextcloud purge proceed on its own. Emptying a Nextcloud trash is never authority to
	 * delete something that is no longer in the bin we put it in.
	 *
	 * Bin OFF is unaffected: there is no bin, the soft step already deleted the dashboard and
	 * stripped the id, so a still-managed file reaching here is a trash-bypass and its only
	 * delete.
	 */
	public function hardDelete(ManagedFile $managed): void {
		if (!$managed->isManaged() || $managed->isLink()) {
			return; // already stripped (bin OFF purge) or a link — nothing to delete
		}

		try {
			$binOn = $this->recycleBin->isEnabled();
		} catch (\Throwable $e) {
			// Cannot tell which model we are in. Do not guess in the irreversible
			// direction: skip the Grafana delete and let the Nextcloud purge proceed.
			$this->logger->warning('grafana_sync purge: could not read the recycle-bin setting; leaving Grafana alone', [
				'app' => Application::APP_ID,
				'uid' => $managed->uid,
				'exception' => $e,
			]);
			return;
		}

		if ($binOn && !$this->isStillParked($managed->uid)) {
			// Rescued, re-filed, or already gone. Not ours to delete any more.
			$this->logger->info('grafana_sync purge: dashboard is no longer in the recycle-bin folder; leaving Grafana alone', [
				'app' => Application::APP_ID,
				'uid' => $managed->uid,
			]);
			return;
		}

		$this->grafana->deleteDashboard($managed->uid);
	}

	/**
	 * Is this dashboard still sitting in the configured bin folder?
	 *
	 * Answers **false** whenever we cannot prove otherwise — the dashboard is gone, the bin
	 * folder cannot be resolved, or Grafana will not answer. Every one of those is a reason
	 * NOT to issue an irreversible delete: the safe direction here is to leave a dashboard
	 * alive that could have been removed, never to remove one that should have lived. The
	 * leftover is a recoverable leak; the alternative is data loss with no undo.
	 */
	private function isStillParked(string $uid): bool {
		try {
			$binUid = $this->recycleBin->activeFolderUid();
			if ($binUid === null) {
				return false;
			}
			$record = $this->grafana->readDashboard($uid);
			$folderUid = $record['meta']['folderUid'] ?? null;
			return is_string($folderUid) && $folderUid === $binUid;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync purge: could not confirm the dashboard is still in the bin; skipping the delete', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Restore step (NC restore-from-trash). See the class docblock for the rule table. Throws on
	 * a Grafana failure; the restore listener logs + swallows it (never block the restore).
	 */
	public function restore(File $node, ManagedFile $managed, ?Mapping $mapping): void {
		if ($managed->isLink()) {
			return; // the link's dashboard was never touched
		}

		if ($managed->isManaged()) {
			// BIN ON parked it (id kept). Move it back into its mapped folder — an idempotent
			// upsert on the kept uid, which also re-creates it if it had somehow gone.
			if ($mapping === null) {
				$this->logger->info('grafana_sync restore: no mapping for a managed file; leaving it as-is', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
				]);
				return;
			}
			// THE PARKED DASHBOARD MAY BE GONE, and an upsert would not notice. The bin is
			// an ordinary Grafana folder anyone can delete out of, so a file can sit in the
			// Nextcloud trash for weeks while the dashboard it names is destroyed. Upserting
			// the kept uid then RE-CREATES a dashboard at a uid that names nothing — quietly,
			// because Grafana takes any free uid you offer it.
			//
			// That is the same situation the bin-OFF restore is in, and it gets the same
			// answer: the file carried an id in, the app decided it was not usable, so the
			// answer is a fresh one. Reusing it would also mean overwriting whatever a
			// stranger had since created at that uid, which is the concrete harm.
			if (!$this->dashboardStillExists($managed->uid)) {
				$this->logger->info('grafana_sync restore: the parked dashboard is gone; re-creating it from the file', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'uid' => $managed->uid,
				]);
				if ($mapping->mode === Mapping::MODE_SYNC) {
					$this->createService->createForFile($node, $mapping, true);
				}
				return;
			}

			$spec = $this->decodeSpec($node->getContent());
			$spec->uid = $managed->uid;
			// Where the file actually IS, not where its mapping starts. Restoring a whole
			// folder brings back files that live in subfolders, and the folder cascade
			// deleted the Grafana folders on the way in — so this both puts each dashboard
			// back under the right parent and re-creates any level that is missing.
			$folderUid = $this->folderMirror->folderUidFor($node, $mapping);
			$resp = $this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $folderUid, $node->getName()));
			$version = isset($resp['version']) ? (string)$resp['version'] : '';
			if ($version !== '') {
				$this->guard->run(fn () => $this->metadata->write($node->getId(), [DashboardMetadata::KEY_VERSION => $version]));
			}
			return;
		}

		// BIN OFF stripped the id at trash-time. The restored plain file is back in a mapped
		// folder → re-create it via create-on-land, a fresh dashboard with a new uid. Only a
		// sync mapping authors; a link/unmapped destination gets nothing.
		//
		// `asNewDashboard`, AND THE METADATA IS NOT WHAT MAKES IT NEW. Stripping the file's
		// stamp is not enough, because the file's BODY is the dashboard's full JSON and
		// carries `uid` inside it — so the upsert keyed on it and Grafana rebuilt the
		// dashboard at the id the trashing had just destroyed. The file came back wearing
		// the uid it arrived with, silently, and only the spec's `its own, not the one it
		// arrived with` caught it. This is the same flag, for the same reason, that a copy
		// sets: a birth must not inherit the id written in the bytes it was born from.
		if ($mapping !== null && $mapping->mode === Mapping::MODE_SYNC) {
			$this->createService->createForFile($node, $mapping, true);
		}
	}

	/**
	 * Is there still a dashboard at this uid?
	 *
	 * Answers **true** whenever it cannot prove otherwise, which is the opposite default
	 * from {@see isStillParked} and deliberately so. There the unsafe direction is an
	 * irreversible delete, so doubt means "leave it alone". Here the unsafe direction is
	 * MINTING: a Grafana that is merely unreachable would be read as "the dashboard is
	 * gone", and the restore would abandon a live dashboard and build a second one beside
	 * it. Only a definite 404 — Grafana answering that it looked and found nothing —
	 * counts as gone.
	 */
	private function dashboardStillExists(string $uid): bool {
		try {
			$this->grafana->readDashboard($uid);
			return true;
		} catch (GrafanaApiException $e) {
			if ($e->httpStatus === 404) {
				return false;
			}
			$this->logger->warning('grafana_sync restore: could not confirm the parked dashboard still exists; assuming it does', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync restore: could not reach Grafana to check the parked dashboard; assuming it exists', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return true;
		}
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
