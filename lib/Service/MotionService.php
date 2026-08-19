<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
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
 *    file's identity** — it becomes a plain, untracked `.grafana`. Moving it back into a
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
		private FolderMirror $folderMirror,
		private RecycleBin $recycleBin,
		private ReplacedByMoveStore $replaced,
		private CreateService $createService,
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

		// AN OVERWRITE IS ANSWERED BEFORE ANY OF THE BRANCHES BELOW, because it is not a
		// KIND of move — it can be any of them. This used to live inside onEnterMapping,
		// which is only reached when the mapping CHANGES, so dragging a file onto an
		// existing name between two subfolders of ONE mapping skipped the adoption
		// entirely: the same-mapping branch reparented the arrival under its own uid and
		// the destination's dashboard, correctly not deleted, was simply left behind.
		// Two dashboards in one Grafana folder, one file — and the tags the user had put
		// on the destination stayed with the orphan.
		//
		// Found by a human dragging a file between `observe/blurn` and `observe/morton`;
		// every scenario in move.feature arrives from an UNMAPPED folder, so none of them
		// could reach this. See `features/AGENTS.md#an-overwrite-can-happen-inside-one-mapping`.
		$adopted = $this->replaced->adoptedUid($node->getId());
		if ($to !== null && !$managed->isLink() && $adopted !== null && $adopted !== $managed->uid) {
			$superseded = $managed->uid;
			$this->logger->info('grafana_sync motion: an overwrite inherits the dashboard it replaced', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'arrivedWith' => $superseded,
				'adopted' => $adopted,
			]);
			$this->bindTo($node, $adopted, $to);

			// AND THE ARRIVAL'S OWN DASHBOARD IS NOW FILE-LESS. It only needs disposing of
			// when it is sitting somewhere this app mirrors — which is exactly when the
			// file came FROM a mapping. Left there it has no mirror, so the next pull
			// writes one, and the overwrite the user performed grows a duplicate back a
			// few minutes later. A file arriving from outside every mapping leaves nothing
			// behind to clean up, which is why that case is still left alone.
			if ($from !== null) {
				$this->disposeSuperseded($node, $superseded);
			}
			return;
		}

		if (($from?->id) === ($to?->id)) {
			// SAME MAPPING, BUT NOT NECESSARILY THE SAME FOLDER. This used to return
			// outright, which made a drag into a subfolder a purely local act — the
			// dashboard stayed wherever Grafana already had it, and the subfolder was
			// never created. That is the opposite of the rule the spec states: a folder
			// is in Grafana when a dashboard is in it, however the dashboard got there.
			//
			// ONLY WHEN THE PARENT ACTUALLY CHANGED. Nextcloud fires one event for a
			// rename and a move alike, and an upsert is not free: it bumps Grafana's
			// version and its `updated`, which this app surfaces as the file's Modified
			// time — so a local rename would move a clock that records when the
			// DASHBOARD changed. {@see NameSyncListener} already owns the rename, and
			// pushing here too would send the same dashboard twice for one gesture.
			if ($to !== null
				&& !$managed->isLink()
				&& dirname($fromPath) !== dirname($node->getPath())) {
				$this->reparentWithinMapping($node, $managed, $to);
			}
			return;
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
	 * A move that stayed inside one mapping — so only the SUBFOLDER changed.
	 *
	 * {@see FolderMirror::folderUidFor()} answers "which Grafana folder should hold the
	 * thing at this path", creating any missing level on the way, which is what brings
	 * the new subfolder into existence. Moving back to the mapping root resolves to the
	 * mapping's own folder, so the dashboard comes back out of the subfolder too.
	 *
	 * The banked `grafana_folderUid` is deliberately NOT consulted and is re-stamped
	 * from the answer: it records where the dashboard was PULLED to, and a move is
	 * exactly the gesture that makes that stale. Leaving it would send the next push
	 * straight back to the old folder.
	 */
	private function reparentWithinMapping(File $node, ManagedFile $managed, Mapping $to): void {
		$folderUid = $this->folderMirror->folderUidFor($node, $to);

		$spec = $this->decodeSpec($node->getContent());
		$spec->uid = $managed->uid;
		$resp = $this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $folderUid, $node->getName()));

		$update = [DashboardMetadata::KEY_FOLDER_UID => $folderUid ?? ''];
		$version = isset($resp['version']) ? (string)$resp['version'] : '';
		if ($version !== '') {
			$update[DashboardMetadata::KEY_VERSION] = $version;
		}
		$this->guard->run(fn () => $this->metadata->write($node->getId(), $update));
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

		// The overwrite adoption is NOT here any more — {@see onMove} answers it before it
		// picks a branch, because an overwrite can arrive down any of them.
		$uid = $managed->uid;

		// TWO FILES, ONE UID, IS NOT A STATE THIS MAPPING MAY REACH. Answer "keep both
		// versions" and the Files app moves the arrival in under a FREE name — an
		// ordinary move-in, no overwrite, no mark — while it still carries the uid of the
		// file it was duplicated from. Binding it to that uid would push the arrival's
		// body over the dashboard the other file mirrors and leave two files claiming
		// one dashboard, which is the fork this whole mechanism exists to prevent. The
		// person asked to keep both; a second file is only "both" if it is a second
		// dashboard, so the arrival is born as its own.
		if ($this->hasSyncedSibling($node, $uid)) {
			$this->logger->info('grafana_sync motion: move-in duplicate of an already-mirrored dashboard; minting a new one', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'fileId' => $node->getId(),
			]);
			$this->createService->createForFile($node, $to, true);
			return;
		}

		$this->bindTo($node, $uid, $to);
	}

	/**
	 * Point $node at dashboard $uid inside mapping $to: push the file's body there and
	 * stamp the file to agree.
	 *
	 * PUBLIC BECAUSE AN OVERWRITE CAN ARRIVE CARRYING NOTHING. A copy does not inherit
	 * the metadata row, so dragging a copied `.grafana` over a synced file lands in
	 * create-on-land rather than here — and create-on-land must be able to hand the file
	 * to this instead of minting a second dashboard beside the one it replaced.
	 * {@see \OCA\GrafanaSync\Listener\CreateInGrafanaListener} is that caller.
	 */
	public function bindTo(File $node, string $uid, Mapping $to): void {
		$spec = $this->decodeSpec($node->getContent());
		$spec->uid = $uid; // identity is the metadata uid, never the file's typed value
		// THE SUBFOLDER, NOT THE MAPPING ROOT. Using $to->grafanaFolderUid put a file
		// dragged into another mapping's subfolder at that mapping's top level, which
		// is the same bug the same-mapping path had — one rule, so one resolution.
		$folderUid = $this->folderMirror->folderUidFor($node, $to);
		$resp = $this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $folderUid, $node->getName()));

		// THE MODE COMES BACK TOO. A file that left with the bin on is stamped `unmapped`,
		// and re-adopting it without re-stating the mode left a live mirror in a sync
		// mapping still claiming to be unmapped — which every later gesture reads to
		// decide what it may do. The upsert above already moved the dashboard out of the
		// bin folder and into this mapping's; the stamp has to agree with it.
		// THE UID IS WRITTEN TOO, because an overwrite may have changed it. For every
		// other move-in it is the value already stamped and this is a no-op.
		$update = [
			DashboardMetadata::KEY_UID => $uid,
			DashboardMetadata::KEY_MAPPING => $to->id,
			DashboardMetadata::KEY_MODE => $to->mode,
			DashboardMetadata::KEY_FOLDER_UID => $folderUid ?? '',
		];
		$version = isset($resp['version']) ? (string)$resp['version'] : '';
		if ($version !== '') {
			$update[DashboardMetadata::KEY_VERSION] = $version;
		}
		$this->guard->run(fn () => $this->metadata->write($node->getId(), $update));
	}

	/**
	 * Does another file in the same folder already mirror this dashboard?
	 *
	 * The incoming file is skipped by id, not by path: at this point it is sitting in
	 * the folder it just arrived in, so a path comparison would have to know which of
	 * two spellings the event used.
	 */
	private function hasSyncedSibling(File $node, string $uid): bool {
		if ($uid === '') {
			return false;
		}
		$parent = $node->getParent();
		if (!$parent instanceof Folder) {
			return false;
		}
		foreach ($parent->getDirectoryListing() as $sibling) {
			if ($sibling->getId() === $node->getId() || !FilenameCodec::isDashboardFile($sibling)) {
				continue; // skip the incoming file itself and anything not a dashboard file
			}
			$managed = $this->metadata->read($sibling->getId());
			if ($managed !== null && $managed->uid === $uid) {
				return true;
			}
		}
		return false;
	}

	/**
	 * mapped → out of every mapping. Sync: the file holds the full JSON (content safe in
	 * Nextcloud), so delete the dashboard in Grafana and strip the file's identity. Link:
	 * MoveGuardListener refuses a link move-out, so this is only reached defensively — just
	 * strip the pointer (a link never owned the dashboard, so nothing is deleted).
	 */
	private function onLeaveMapping(File $node, ManagedFile $managed): void {
		if ($managed->isLink()) {
			// MoveGuardListener refuses a link move-out, so this is only reached
			// defensively. A link never owned the dashboard, so nothing is deleted.
			$this->stripIdentity($node);
			return;
		}

		// THE SAME FORK THE TRASH GESTURE ALREADY MAKES, and it belongs here for the same
		// reason: moving a file out of every mapping and trashing it are one Grafana
		// operation chosen by one setting ({@see DeleteService::softDelete}). This path
		// used to delete unconditionally, so the bin was honoured when you deleted a file
		// and ignored when you dragged it out — the same gesture, two answers.
		$binUid = $this->recycleBin->activeFolderUid();
		if ($binUid !== null) {
			// BIN ON: park it, keeping the uid. Grafana has no archive, so an ordinary
			// folder move is the only reversible removal there is — and because nothing
			// was destroyed, the file KEEPS its uid and can restore to the same dashboard.
			// It stops belonging to a mapping, which is what `unmapped` means.
			$spec = $this->decodeSpec($node->getContent());
			$spec->uid = $managed->uid;
			$this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $binUid, $node->getName()));
			$this->guard->run(fn () => $this->metadata->write($node->getId(), [
				DashboardMetadata::KEY_MAPPING => '',
				DashboardMetadata::KEY_MODE => DashboardMetadata::MODE_UNMAPPED,
				DashboardMetadata::KEY_FOLDER_UID => $binUid,
			]));
			return;
		}

		// BIN OFF: the file holds the full JSON, so the content is safe in Nextcloud before
		// Grafana is touched. Delete FIRST; if Grafana can't confirm it, the exception
		// propagates and we do NOT strip — the file keeps its identity and stays
		// reconcilable (no data lost).
		$this->grafana->deleteDashboard($managed->uid);
		$this->stripIdentity($node);
	}

	/**
	 * Get rid of the dashboard an overwrite superseded — the one the ARRIVING file was
	 * bound to before it inherited the destination's identity.
	 *
	 * Its file is gone: the same node now points at the dashboard it landed on. So this
	 * dashboard is sitting in a folder this app mirrors with nothing mirroring it, which
	 * is precisely the state a pull reads as "a dashboard with no file" and answers by
	 * WRITING ONE. Without this the overwrite grows its duplicate back on the next tick,
	 * a few minutes after the user thought they had merged two files into one.
	 *
	 * THE SAME FORK AS EVERY OTHER REMOVAL IN THIS APP, and deliberately so: the bin is
	 * the difference between a removal you can undo and one you cannot, and a gesture
	 * that quietly ignored it would be the third place this app has had to learn that
	 * lesson. Bin ON parks it; bin OFF deletes it, which is what "keep the new version"
	 * already means for the bytes.
	 *
	 * Failures are logged and swallowed. The overwrite itself has already happened and is
	 * correct; a leftover dashboard is a duplicate an admin can remove, and throwing here
	 * would abort a rename event whose file has already moved.
	 */
	private function disposeSuperseded(File $node, string $uid): void {
		if ($uid === '') {
			return;
		}
		try {
			$binUid = $this->recycleBin->activeFolderUid();
			if ($binUid === null) {
				$this->grafana->deleteDashboard($uid);
				$this->logger->info('grafana_sync motion: deleted the dashboard the overwrite superseded', [
					'app' => Application::APP_ID,
					'uid' => $uid,
				]);
				return;
			}
			// PARKED WITH ITS OWN BODY, read back from Grafana rather than taken from the
			// file — the file now holds the ARRIVAL's bytes, and parking those under this
			// uid would overwrite the very thing the bin exists to preserve.
			$record = $this->grafana->readDashboard($uid);
			$body = $record['dashboard'] ?? null;
			if (!is_array($body)) {
				return;
			}
			$spec = json_decode(json_encode($body, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
			if (!$spec instanceof \stdClass) {
				return;
			}
			$spec->uid = $uid;
			$this->grafana->upsertDashboard(DashboardBody::toUpsertBody($spec, $binUid, $node->getName()));
			$this->logger->info('grafana_sync motion: parked the dashboard the overwrite superseded', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'binUid' => $binUid,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync motion: could not dispose of the superseded dashboard; it may reappear as a duplicate', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
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
