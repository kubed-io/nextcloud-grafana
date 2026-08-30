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
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * The pull reconciler (Grafana → Nextcloud) — Course 2, "the protein plated".
 *
 * For each mapping it provisions the target folder ({@see StorageService}), walks the
 * dashboards Grafana holds in the mapped folder, and reconciles them into files:
 *
 *  - **sync** mode — the full dashboard spec is read and written as `<title>.grafana`
 *    (volatile `id`/`version` stripped by {@see DashboardBody::encodeSync()}); a later
 *    course pushes edits back. The file doubles as a restorable backup.
 *  - **link** mode — a tiny `grafana.reference/v1` pointer body ({@see DashboardBody::encodeReference()})
 *    that deep-links to the live dashboard; never written back.
 *
 * Reconciling, not merely additive (the master's contract, saga Ch2 Round 3): files are
 * matched by the stable dashboard **uid** (rename-/move-stable), updated in place, and any
 * managed file whose dashboard has left the mapped folder is **pruned** — the local mirror
 * only; Grafana is never touched by a pull. The whole run is wrapped in {@see SyncGuard} so
 * the file writes don't trip a future writeback listener into pushing them straight back
 * (the loop the guard exists to prevent).
 *
 * **A pull writes only what actually changed** (saga Ch2, Course 7): a mirror whose bytes
 * already match Grafana is not rewritten, so a pull over a quiet folder leaves every mtime
 * alone. Without that, every scheduled tick reported every mirrored file as modified — see
 * {@see writeDashboard}.
 *
 * Scope note: this course is the **flat** pull — every dashboard in the mapped Grafana
 * folder lands directly in the one Nextcloud folder. The subfolder mirror rides this same
 * loop in a later course. The seams that were held open for a per-dashboard exclude are
 * gone with the feature: a mirrored dashboard takes its mapping's mode, full stop.
 */
final class SyncService {
	/** Sync directions — the parity vocabulary shared with the n8n master. */
	public const DIR_PULL = 'pull';
	public const DIR_PUSH = 'push';

	public function __construct(
		private MappingService $mappings,
		private GrafanaClient $grafana,
		private DashboardMetadata $metadata,
		private StorageService $storage,
		private SyncGuard $guard,
		private PushService $push,
		private MirrorTimes $times,
		private FolderTreeMirror $tree,
		private FolderMetadata $folders,
		private TagSyncService $tagSync,
		private TrashControl $trash,
		private TrashReconcileService $trashReconcile,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Single parameterized entry point for a manual sync — the same public shape as the
	 * n8n master's {@see \OCA\N8nSync\Service\SyncService::dispatch}, so the controller,
	 * the occ command, and (later) a shared base all call one method regardless of
	 * direction or scope.
	 *
	 * Async note: the master enqueues a background job + tracks status when `$async` is
	 * true. Grafana has no job/status infra yet (it lands with the scheduled-pull
	 * course), so **every dispatch runs inline** — `$async` is accepted for signature
	 * parity and honoured as a no-op. The controller/CLI already call through here, so
	 * wiring the async branch later needs no change at the call sites.
	 *
	 * @param string $direction self::DIR_PULL | self::DIR_PUSH
	 * @param string|null $mappingId a specific mapping id, or null = every mapping
	 * @return array<string,mixed>
	 */
	public function dispatch(string $direction, ?string $mappingId): array {
		if (!self::isDirection($direction)) {
			throw new \InvalidArgumentException('direction must be "pull" or "push"');
		}
		return $this->runInline($direction, $mappingId);
	}

	/** The one place that knows what a valid direction is. */
	public static function isDirection(string $direction): bool {
		return $direction === self::DIR_PULL || $direction === self::DIR_PUSH;
	}

	/**
	 * Synchronous execution of one dispatch — also the seam a future {@see dispatch}
	 * async job would call. Normalises the return to always carry `status`.
	 *
	 * @param string $direction self::DIR_PULL | self::DIR_PUSH
	 * @return array<string,mixed>
	 */
	public function runInline(string $direction, ?string $mappingId): array {
		if ($direction === self::DIR_PUSH) {
			if ($mappingId !== null && $mappingId !== '') {
				$mapping = $this->mappings->getById($mappingId);
				if ($mapping === null) {
					throw new \OutOfBoundsException('Mapping not found');
				}
				$res = $this->pushOne($mapping);
				$res['status'] = ($res['failed'] ?? 0) === 0 ? 'ok' : 'error';
				return $res;
			}
			return $this->pushAll();
		}
		if ($mappingId !== null && $mappingId !== '') {
			$mapping = $this->mappings->getById($mappingId);
			if ($mapping === null) {
				throw new \OutOfBoundsException('Mapping not found');
			}
			$res = $this->pullOne($mapping);
			$res['status'] = ($res['failed'] ?? 0) === 0 ? 'ok' : 'error';
			$res['message'] = null;
			return $res;
		}
		return $this->pullAll();
	}

	/**
	 * Pull every mapping in order — the bulk "Sync from Grafana" action.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, unchanged:int, status:string, message:?string}
	 */
	public function pullAll(): array {
		$total = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0, 'unchanged' => 0];
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			try {
				$res = $this->pullOne($mapping);
				$total['processed'] += $res['processed'];
				$total['succeeded'] += $res['succeeded'];
				$total['failed'] += $res['failed'];
				$total['pruned'] += $res['pruned'];
				$total['unchanged'] += $res['unchanged'];
			} catch (\Throwable $e) {
				// Curate the message the same way the rest of the app does: a Grafana
				// auth/transport failure becomes a friendly line rather than raw upstream
				// text, and our own RuntimeExceptions pass through unchanged.
				$errors[] = $mapping->ncFolder . ': ' . GrafanaClient::describeConnectionError($e);
				$total['failed']++;
				$this->logger->error('pullOne failed for ' . $mapping->ncFolder, [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
		return [
			'processed' => $total['processed'],
			'succeeded' => $total['succeeded'],
			'failed' => $total['failed'],
			'pruned' => $total['pruned'],
			'unchanged' => $total['unchanged'],
			'status' => $errors === [] ? 'ok' : 'error',
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * Push every mapping's `sync` files back to Grafana — the bulk "Sync to Grafana"
	 * action (Nextcloud treated as the source of truth). Delegates per mapping to
	 * {@see pushOne}; `link` mappings never push.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, status:string, message:?string}
	 */
	public function pushAll(): array {
		$processed = 0;
		$succeeded = 0;
		$failed = 0;
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			$res = $this->pushOne($mapping);
			$processed += $res['processed'];
			$succeeded += $res['succeeded'];
			$failed += $res['failed'];
			if (is_string($res['message'] ?? null) && $res['message'] !== '') {
				$errors[] = $res['message'];
			}
		}
		return [
			'processed' => $processed,
			'succeeded' => $succeeded,
			'failed' => $failed,
			'status' => $failed === 0 ? 'ok' : 'error',
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * Push a single mapping's `sync` files up to Grafana. Files outside this mapping's
	 * folder — including every `unmapped` file — are never listed, so never pushed. A
	 * `link` mapping is a no-op (a pointer has nothing to push).
	 *
	 * @return array{processed:int, succeeded:int, failed:int, message:?string}
	 */
	public function pushOne(Mapping $mapping): array {
		if ($mapping->mode !== Mapping::MODE_SYNC) {
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null];
		}
		if (!$this->storage->isAvailable($mapping)) {
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null];
		}
		$folder = $this->storage->findFolder($mapping);
		if ($folder === null) {
			return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'message' => null];
		}
		$processed = 0;
		$succeeded = 0;
		$failed = 0;
		$errors = [];
		// EVERY DASHBOARD FILE THE MAPPING OWNS, AT EVERY DEPTH — and `indexByUid` is
		// not that. It keys by uid, so it answers with MANAGED files only, and a file
		// that has never been pushed has no uid to be keyed by. The push therefore
		// could not see the very files "Sync to Grafana" exists to make real: map a
		// folder that already holds dashboard files, press the button, and nothing
		// happens.
		foreach ($this->pushableFiles($folder, $mapping) as $node) {
			$processed++;
			try {
				if ($this->push->push($node)) {
					$succeeded++;
				}
			} catch (\Throwable $e) {
				// Carry Grafana's own message through to the admin button, curated the
				// same way as everywhere else so a bad dashboard is fixable from the toast.
				$failed++;
				$errors[] = $node->getName() . ': ' . GrafanaClient::describeConnectionError($e);
				$this->logger->warning('grafana_sync push failed', [
					'app' => Application::APP_ID,
					'file' => $node->getName(),
					'ncFolder' => $mapping->ncFolder,
					'exception' => $e,
				]);
			}
		}
		return [
			'processed' => $processed,
			'succeeded' => $succeeded,
			'failed' => $failed,
			'message' => $errors === [] ? null : implode('; ', $errors),
		];
	}

	/**
	 * Every dashboard file under a mapping that the push may send, at any depth.
	 *
	 * A file qualifies when it is a `.grafana` file that is either UNMANAGED — never
	 * pushed, and the whole point of a first sync — or managed by THIS mapping in sync
	 * mode. A `link` file is a read-only pointer and never pushes, even inside a sync
	 * mapping; a legacy file with no recorded mode is treated as sync.
	 *
	 * @return list<File>
	 */
	private function pushableFiles(Folder $folder, Mapping $mapping): array {
		$out = [];
		$this->collectPushable($folder, $mapping, $out);
		return $out;
	}

	/**
	 * The walk itself, accumulating BY REFERENCE.
	 *
	 * `array_merge` on the way back up copies everything gathered so far at every
	 * level, which turns a deep tree quadratic — and a whole-instance mapping is
	 * exactly where that bites. Same shape as {@see collectManaged} for the same
	 * reason.
	 *
	 * @param list<File> $out
	 */
	private function collectPushable(Folder $folder, Mapping $mapping, array &$out): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->collectPushable($node, $mapping, $out);
				continue;
			}
			if (!$node instanceof File || !FilenameCodec::isDashboardFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if ($managed === null || !$managed->isManaged()) {
				$out[] = $node; // never pushed — the first sync is where it becomes one
				continue;
			}
			if ($managed->mode !== '' && !$managed->isSync()) {
				continue;
			}
			// A file explicitly owned by ANOTHER mapping, in an overlapping or nested
			// subtree, is that mapping's to push.
			if ($managed->mappingId !== '' && $managed->mappingId !== $mapping->id) {
				continue;
			}
			$out[] = $node;
		}
	}

	/**
	 * Pull a single mapping into its Nextcloud folder.
	 *
	 * `unchanged` counts the succeeded dashboards whose mirror already matched Grafana
	 * and so was NOT rewritten — a subset of `succeeded`, not a separate outcome. On a
	 * quiet folder it equals `succeeded`, which is what "nothing to do" looks like.
	 *
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, unchanged:int}
	 */
	public function pullOne(Mapping $mapping): array {
		$empty = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0, 'unchanged' => 0];

		// A Team Folder with no groups is invisible to everyone — skip rather than
		// create dead storage. The admin-owned backend is always visible to the actor,
		// so an empty group list there is fine (admin-only), and we do NOT skip it.
		// NO "SKIP A TEAM FOLDER WITH NO GROUPS" GUARD ANY MORE. It read
		// $mapping->ncGroups, which no longer exists — the groups are the folder's
		// (see Mapping's class docblock). It was also the wrong call: an unshared
		// folder is the admin's business, visible to them in the mapping card and in
		// Files, and refusing to sync into it turned a sharing question into a
		// mysteriously empty folder.
		if (!$this->storage->isAvailable($mapping)) {
			$this->logger->warning('skipping mapping: storage backend unavailable (Team Folder selected but groupfolders disabled?)', [
				'app' => Application::APP_ID,
				'ncFolder' => $mapping->ncFolder,
			]);
			return $empty;
		}

		// Guard our own writes: putContent/newFile fire NodeWrittenEvent, and a future
		// writeback listener would otherwise push every pulled file straight back to
		// Grafana (the loop SyncGuard exists to prevent).
		$this->guard->enter();
		try {
			$targetFolder = $this->storage->ensureFolder($mapping);
			// PROVISIONING IS WHERE THE ID BECOMES KNOWABLE, and a re-provisioned folder
			// has a NEW one. Without this the mapping keeps pointing at the folder that
			// used to be there, `resolveForPath` can no longer place it, and every
			// path-based question — the link guards above all — silently stops finding
			// the mapping at all.
			$this->mappings->bankFolderId($mapping->id, $targetFolder->getId());

			// Bring the Nextcloud folder tree into agreement with Grafana's BEFORE
			// placing anything, so every dashboard has a folder to land in. The map it
			// returns is grafana folder uid → the Nextcloud folder mirroring it.
			$placed = $this->tree->sync($targetFolder, $mapping);

			// The folders themselves wear Grafana's tags too, mapped root included —
			// a first pull that dressed the dashboards and left the folders bare would
			// be a half-imported mirror. The root is not in $placed (it is the mapping's
			// own folder, which nothing stamps) so it is done by mapping uid.
			$this->tagSync->applyToFolder($targetFolder, $mapping->grafanaFolderUid);
			foreach ($placed as $folderUid => $folder) {
				$this->tagSync->applyToFolder($folder, $folderUid);
			}

			$processed = 0;
			$succeeded = 0;
			$failed = 0;
			$unchanged = 0;

			$existingByUid = $this->indexByUid($targetFolder, $mapping);
			$nameCounts = [];
			$seenUids = [];

			foreach ($this->dashboardRows($mapping, $placed) as $row) {
				$processed++;
				$uid = $row['uid'];
				$seenUids[$uid] = true;
				try {
					// A dashboard lands in the Nextcloud folder mirroring the Grafana
					// folder that holds it; anything we have no mirror for falls back to
					// the mapping's root, which is where it used to go unconditionally.
					$into = $placed[$row['folderUid'] ?? ''] ?? $targetFolder;
					if ($this->writeDashboard($into, $targetFolder, $mapping, $row, $existingByUid, $nameCounts)) {
						$unchanged++;
					}
					$succeeded++;
				} catch (\Throwable $e) {
					$failed++;
					$this->logger->warning('pull dashboard failed', [
						'app' => Application::APP_ID,
						'uid' => $row['uid'] ?? '?',
						'ncFolder' => $mapping->ncFolder,
						'exception' => $e,
					]);
				}
			}

			$pruned = $this->pruneStale($existingByUid, $seenUids, $mapping);

			// AND THE TRASH, which the prune above cannot see. `pruneStale` walks the
			// mapped FOLDER; a mirror that was trashed is not in it, so a dashboard
			// destroyed in Grafana after its file was trashed left the Nextcloud trash
			// holding an entry whose restore had nothing to reconnect to.
			//
			// AFTER the prune, not before: the prune trashes mirrors of its own, and
			// judging the trash first would mean judging it in a state the pull is about
			// to change. The reconcile refuses to purge anything it cannot prove is gone,
			// so a mirror it sees mid-flight is simply left for the next tick.
			$this->trashReconcile->reap($mapping);

			// AND THE TRASHED FOLDERS, which the pass above cannot see either. Trashing a
			// folder leaves ONE trash entry, named after the folder — so a mirror inside
			// it is not a trash entry at all and `reap()`'s "is this entry a dashboard
			// file?" walked straight past a whole tree of them.
			$this->trashReconcile->reapFolders($mapping);

			// AND THE FOLDERS, last of all. A folder whose Grafana counterpart is gone
			// must stop claiming it, and it can only be judged empty once the prune
			// above has taken the mirrors it held.
			$this->tree->reapOrphans($targetFolder, $mapping);

			return [
				'processed' => $processed,
				'succeeded' => $succeeded,
				'failed' => $failed,
				'pruned' => $pruned,
				'unchanged' => $unchanged,
			];
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * Every dashboard the mapping covers: the ones in its own Grafana folder, plus the
	 * ones in each folder beneath it that we now mirror.
	 *
	 * Grafana's `/api/search` is NOT recursive — a folder scope returns that folder's
	 * DIRECT children only — so a tree costs one request per folder. That is the shape
	 * of the API rather than a choice; the alternative is listing the whole instance and
	 * discarding most of it, which is worse on every instance that is not tiny.
	 *
	 * **This also fixes a prune hazard.** The pull only ever listed the mapping's own
	 * folder, so a dashboard living in a Grafana subfolder was never `seen` — and any
	 * mirror of it looked stale and was pruned. Walking the tree is what makes those
	 * dashboards visible to the run that is deciding what to delete.
	 *
	 * @param array<string, Folder> $placed
	 * @return iterable<array{uid:string, title:string, folderUid:string, url:string, tags:list<string>}>
	 */
	private function dashboardRows(Mapping $mapping, array $placed): iterable {
		yield from $this->grafana->listDashboards($this->grafanaScope($mapping));
		foreach (array_keys($placed) as $folderUid) {
			yield from $this->grafana->listDashboards($folderUid);
		}
	}

	/**
	 * Translate a mapping's stored Grafana folder uid into the `/api/search` scope
	 * {@see GrafanaClient::listDashboards()} takes: the reserved-root `/` selects the
	 * "General" area (no-folder dashboards); any other value is a real folder uid whose
	 * direct children are walked.
	 *
	 * This is the mapping's OWN folder only. The folders beneath it are scoped one at a
	 * time by {@see dashboardRows()}, because Grafana's search does not recurse.
	 */
	private function grafanaScope(Mapping $mapping): string {
		return $mapping->grafanaFolderUid === '/'
			? GrafanaClient::FOLDER_GENERAL
			: $mapping->grafanaFolderUid;
	}

	/**
	 * Delete managed files that belong to $mapping but whose dashboard was not seen in
	 * this pull (it left the mapped Grafana folder). Grafana is left alone — only the
	 * local mirror is removed — and the caller already holds the SyncGuard so the delete
	 * does not mirror back. Returns the number of files pruned.
	 *
	 * @param array<string,Node> $existingByUid managed files for this mapping, keyed by uid
	 * @param array<string,bool> $seenUids uids still present in the mapped folder (written this pull)
	 */
	private function pruneStale(array $existingByUid, array $seenUids, Mapping $mapping): int {
		$pruned = 0;
		foreach ($existingByUid as $uid => $node) {
			if (isset($seenUids[$uid])) {
				continue;
			}
			try {
				$this->removeMirror($node, $mapping);
				$pruned++;
			} catch (\Throwable $e) {
				$this->logger->warning('prune stale file failed', [
					'app' => Application::APP_ID,
					'uid' => $uid,
					'ncFolder' => $mapping->ncFolder,
					'exception' => $e,
				]);
			}
		}
		return $pruned;
	}

	/**
	 * Remove a mirror whose dashboard is no longer in the mirrored Grafana folder — and
	 * decide, from the mapping's MODE, whether the user gets it back.
	 *
	 *   sync → the Nextcloud trash. The file IS the dashboard's content, and the thing
	 *          that happened in Grafana (a move, a re-file) may itself be undone, so the
	 *          local gesture must be reversible too.
	 *   link → gone, with no trash entry. A link is a read-only projection; once the
	 *          dashboard is out of the mirrored folder there is nothing for a restore to
	 *          reconnect to, and a trashed pointer would offer the user exactly that.
	 *
	 * {@see TrashControl} explains why pausing the trash is the only supported way to
	 * make a delete permanent, and why it is the right one for a Team Folder. Ported
	 * from the n8n master's `removeMirror`, where the fork and its reasons first shipped.
	 */
	private function removeMirror(Node $node, Mapping $mapping): void {
		if ($mapping->mode !== Mapping::MODE_LINK) {
			$node->delete();
			return;
		}
		// A STATEMENT BODY, not an arrow function: `Node::delete()` is `void`, and
		// `fn () => $node->delete()` implies a result that does not exist.
		$this->trash->withoutTrash(static function () use ($node): void {
			$node->delete();
		});
	}

	/**
	 * Build {uid => File} for managed files anywhere under $root that belong to $mapping.
	 * Recurses subfolders (folder-scoped) and filters by each file's own `grafana_mapping`:
	 * a file explicitly owned by a *different* mapping (overlapping/nested subtree) is
	 * skipped; a file with no `grafana_mapping` yet is treated as belonging here and
	 * backfilled on write.
	 *
	 * @return array<string,Node>
	 */
	private function indexByUid(Folder $root, Mapping $mapping): array {
		$index = [];
		$this->collectManaged($root, $mapping, $index);
		return $index;
	}

	/**
	 * Index every managed mirror this mapping owns, by dashboard uid.
	 *
	 * @param array<string,Node> $index
	 */
	private function collectManaged(Folder $folder, Mapping $mapping, array &$index): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->collectManaged($node, $mapping, $index);
				continue;
			}
			if (!FilenameCodec::isDashboardFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged()) {
				continue;
			}
			$uid = $managed->uid;
			$owner = $managed->mappingId;
			if ($owner !== '' && $owner !== $mapping->id) {
				continue; // owned by a different mapping sharing/nesting this subtree
			}
			$index[$uid] = $node;
		}
	}

	/**
	 * Reconcile a single dashboard into $folder (update-in-place by uid, else fresh write
	 * with a collision suffix). Metadata + ownership pill follow the body.
	 *
	 * **Change-detected** (saga Ch2, Course 7): an existing mirror is rewritten only when
	 * its bytes differ from what Grafana would write. This used to be an unconditional
	 * `putContent`, which bumped the mtime of every mirrored file on every pull — a
	 * folder-wide "Modified a few seconds ago" on every tick, burying the files a human
	 * had really touched. Inherited from the n8n master, fixed there first.
	 *
	 * That the comparison works at all is owed to {@see DashboardBody::VOLATILE}: Grafana
	 * rewrites `id` and `version` on every save, and stripping them is what makes the body
	 * (and so `grafana_syncedHash`) stable across version bumps. Without it the spec would
	 * differ on every read and there would be nothing to skip.
	 *
	 * Returns **true when the mirror was left untouched** because it already matched
	 * Grafana — the caller's `unchanged` counter.
	 *
	 * The polarity is "unchanged", not "wrote", because of the spec-less branch below:
	 * that dashboard is neither written nor *confirmed* to match, since we never learned
	 * what Grafana holds. A "wrote" flag would have to call it one or the other and lie
	 * either way; "was it confirmed unchanged?" answers `false` honestly. (The n8n master
	 * has no such branch and so reads the other way round; the reported counter is the
	 * same either side.)
	 *
	 * @param array{uid:string, title:string, folderUid:string, url:string, tags:list<string>} $row
	 * @param array<string,Node> $existingByUid
	 * @param array<string,int> $nameCounts
	 */
	private function writeDashboard(
		Folder $folder,
		Folder $root,
		Mapping $mapping,
		array $row,
		array $existingByUid,
		array &$nameCounts,
	): bool {
		$uid = $row['uid'];
		$displayName = $row['title'] !== '' ? $row['title'] : $uid;

		// Both modes read the record now (saga Ch2, Course 8) — but for opposite reasons,
		// and so with opposite error handling. A `sync` file CANNOT be written without
		// the spec, so a failed read is a failed file and must reach {@see pullOne}'s
		// counter. A `link` wants the record ONLY for `meta.updated`/`meta.created`
		// (because `/api/search`, the row a pointer is built from, carries no timestamps
		// at all), and a pointer that cannot be dated is still a perfectly good pointer —
		// so there a failure costs the clocks and nothing else.
		//
		// Reading per branch rather than once up front is what keeps that asymmetry
		// visible. A single shared read has to pick one error policy for both, and
		// picking the lenient one turns a Grafana outage into a run that reports success
		// over stale mirrors.
		if ($mapping->mode === Mapping::MODE_LINK) {
			// Lightweight pointer built from the search row. Version is inert for a link
			// (a pointer never pushes), so it stays empty.
			$read = $this->readClocksForLink($uid);
			$url = $row['url'] !== '' ? $this->grafana->deepLinkFromPath($row['url']) : $this->grafana->deepLink($uid);
			$body = DashboardBody::encodeReference($row, $url, $row['folderUid']);
			$version = '';
		} else {
			// Deliberately UNGUARDED: a transient 500/timeout throws, pullOne catches it,
			// and the file counts as failed. Swallowing it here would report a clean pull
			// over content we never managed to read.
			$read = $this->grafana->readDashboardSpec($uid);
			if ($read === null) {
				$this->logger->warning('grafana_sync pull: dashboard record carried no spec; skipping', [
					'app' => Application::APP_ID,
					'uid' => $uid,
				]);
				// NOT "unchanged": nothing was written, but nothing was verified either —
				// we never learned what Grafana holds, so we cannot claim the mirror
				// matches it. Keeping it out of the count stops a spec-less dashboard
				// reading as a clean no-op.
				return false;
			}
			$version = $read->version();
			$body = DashboardBody::encodeSync($read->spec);
		}

		// A MIRROR IN THE TRASH IS STILL THIS DASHBOARD'S MIRROR. `indexByUid` walks the
		// mapped FOLDER, so a file the user trashed is invisible to it — and without this
		// the pull would reasonably create a second one the moment the dashboard came back
		// out of the recycle-bin folder, leaving a restored dashboard, a fresh file, and a
		// trash entry for the file they actually had.
		$existing = $existingByUid[$uid] ?? $this->trashReconcile->restoreMirror($mapping, $uid);
		if ($existing instanceof File) {
			// THE SUFFIX IS PART OF THE NAME THIS FILE IS ENTITLED TO KEEP. Grafana
			// permits two dashboards in one folder to share a title and Nextcloud does
			// not permit two files to share a name, so the second mirror wears a
			// counter — and asking for index 0 unconditionally told it, every single
			// pull, to go and take a name the first mirror is sitting on.
			//
			// It "worked" by throwing: the move failed, the catch below logged
			// `rename skipped (collision?)`, and the file kept its suffix by accident.
			// Every tick, for every duplicate — and with three dashboards sharing a
			// title, twice a tick. An exception is not a naming policy, and the log
			// line's own question mark says nobody was sure it was one.
			$existing = $this->placeMirror($existing, $folder, $root, $displayName, $uid);
			if ($existing === null) {
				// The file could not be placed AND could not be found again — see
				// placeMirror. Nothing more can safely be done with it this run.
				return false;
			}
			$fileId = $existing->getId();
			// Course 7: the body is the only write here that is not already
			// self-suppressing. Core's metadata layer no-ops an unchanged value
			// (`FilesMetadata::setString` returns early, `saveMetadata` skips when
			// nothing was updated) and the ownership-pill write is diff-based, so
			// stamping and re-tagging an untouched mirror costs nothing and stays
			// unconditional — they also self-heal a mirror whose stamp drifted.
			// `putContent` has no such guard: it rewrote the file, and the mtime, on
			// every single tick.
			$differs = $this->bodyDiffers($existing, $body);
			if ($differs) {
				$existing->putContent($body);
			}
			$this->metadata->stampSynced($fileId, $uid, $mapping->mode, $version, $body, $mapping->id);
			$this->times->apply($existing, $read?->lastChanged(), $read?->created, $differs);
			$this->tagSync->applyToDashboard($existing, self::tagsIn($body));
			return !$differs;
		}

		$basename = $displayName === '' ? $uid : $displayName;
		$collision = $nameCounts[$basename] ?? 0;
		while (true) {
			$candidate = FilenameCodec::format($displayName, $uid, false, $collision);
			if (!$folder->nodeExists($candidate)) {
				break;
			}
			$collision++;
			if ($collision > 1000) {
				throw new \RuntimeException('Could not find a unique filename for ' . $basename);
			}
		}
		$nameCounts[$basename] = $collision + 1;

		$file = $folder->newFile($candidate, $body);
		$this->metadata->stampSynced($file->getId(), $uid, $mapping->mode, $version, $body, $mapping->id);
		$this->times->apply($file, $read?->lastChanged(), $read?->created, true);
		$this->tagSync->applyToDashboard($file, self::tagsIn($body));
		return false; // a brand-new mirror is always a write
	}

	/**
	 * What an EXISTING mirror should be called, given the title its dashboard now
	 * carries — collision counter included when another file has the plain name.
	 *
	 * The plain name is always preferred: a dashboard whose duplicate was deleted in
	 * Grafana should get its unsuffixed name back rather than wear a counter forever.
	 * Failing that, the first free counter is taken, and reaching the file's OWN current
	 * name counts as free — that is how a legitimate duplicate keeps the suffix it has
	 * instead of being renamed on every tick.
	 *
	 * Returns null when no name is available at all, which the caller reads as "leave it
	 * alone". A wrong-but-unique name is strictly better than an exception here: the
	 * dashboard's identity lives in its metadata, so a mirror is never lost by being
	 * misnamed, and a pull that aborts over cosmetics would strand the whole folder.
	 */
	private function desiredMirrorName(File $existing, Folder $target, string $displayName, string $uid): ?string {
		// COLLISIONS ARE ASKED OF THE FOLDER THE FILE IS GOING TO, which is its own
		// only when it is staying put. Asked of the source folder during a relocation,
		// the answer describes a folder the file is about to leave — so it would take
		// a suffix it does not need, or walk into a name the destination already has.
		$current = $existing->getName();
		$staying = $target->getId() === $existing->getParent()->getId();
		for ($collision = 0; $collision <= 1000; $collision++) {
			$candidate = FilenameCodec::format($displayName, $uid, false, $collision);
			if (($staying && $candidate === $current) || !$target->nodeExists($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Put an existing mirror where its dashboard says it belongs, under the name its
	 * dashboard says it should wear. One move, because both are one.
	 *
	 * ## WHERE A MIRROR LIVES IS GRAFANA'S TO SAY
	 *
	 * The pull worked out the right folder already — `$into` mirrors the Grafana
	 * folder holding this dashboard — and then used it only when CREATING a mirror.
	 * An existing one was reconciled in place: contents, stamp, tags and name, never
	 * location. So a mirror that ended up in the wrong folder stayed there for good,
	 * and the plainest way to get one was to move the dashboard between folders IN
	 * GRAFANA: the mirror simply never followed. Measured on a live instance, a
	 * dashboard moved to a subfolder kept its file at the mapping's root through a
	 * pull every seventy seconds, indefinitely.
	 *
	 * ## AND A FOLDER THE USER MADE IS STILL THEIRS
	 *
	 * The rename this replaces was confined to the file's own folder on purpose —
	 * *"never yank a file the user put in a subfolder back to the mapping root"* —
	 * and that instinct is kept rather than discarded. A mirror is relocated only
	 * when it is sitting in a folder THIS APP MANAGES: the mapping's root, or a
	 * folder stamped with the Grafana folder uid it mirrors. A file in a folder the
	 * user made for their own reasons carries no such stamp, so it is left exactly
	 * where they put it — and `folders/create.feature` is what makes that a rule
	 * rather than an accident.
	 */
	private function placeMirror(File $existing, Folder $into, Folder $root, string $displayName, string $uid): ?File {
		$parent = $existing->getParent();
		$relocating = $parent->getId() !== $into->getId() && $this->isManagedFolder($parent, $root);
		$target = $relocating ? $into : $parent;

		$desired = $this->desiredMirrorName($existing, $target, $displayName, $uid);
		if ($desired === null || (!$relocating && $existing->getName() === $desired)) {
			return $existing;
		}

		try {
			$existing->move($target->getPath() . '/' . $desired);
			return $existing;
		} catch (\Throwable $e) {
			$this->logger->info('grafana_sync pull: could not put a mirror where its dashboard says it belongs', [
				'app' => Application::APP_ID,
				'from' => $parent->getPath() . '/' . $existing->getName(),
				'to' => $target->getPath() . '/' . $desired,
				'uid' => $uid,
				'exception' => $e,
			]);

			// THE HANDLE IS NOW SUSPECT, AND WRITING THROUGH IT MAKES A SECOND FILE.
			// The likeliest reason a move fails is that ANOTHER PULL ALREADY MADE IT —
			// the schedule and the admin's button can overlap, and the second run holds
			// a node whose path no longer exists ("Source path not found in cache",
			// measured live). Carrying on regardless calls `putContent` on that stale
			// path, and on object storage that RE-CREATES the file where it used to be:
			// one dashboard, two files, and the new one unstamped because the stamp
			// follows the id to where the file really went.
			//
			// So the file is looked up again by the id it kept. Found → carry on with
			// the real node, wherever the other run put it. Not found → leave this
			// dashboard for the next pull, which is a no-op rather than a duplicate.
			return $this->reResolve($existing, $root);
		}
	}

	/**
	 * Find a file again by the id it kept, after its path stopped being true.
	 *
	 * Searched from the mapping's root because that is the subtree the pull owns and
	 * the only place it may write.
	 */
	private function reResolve(File $existing, Folder $root): ?File {
		try {
			foreach ($root->getById($existing->getId()) as $node) {
				if ($node instanceof File) {
					return $node;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('grafana_sync pull: could not find a mirror again after a failed move', [
				'app' => Application::APP_ID,
				'fileId' => $existing->getId(),
				'exception' => $e,
			]);
		}
		return null;
	}

	/**
	 * Is this folder one the app placed mirrors in — the mapping's root, or a mirror
	 * of a Grafana folder?
	 *
	 * The stamp is the whole test. A folder this app created to mirror a Grafana one
	 * carries its uid ({@see FolderMetadata}); a folder the user made carries
	 * nothing, and that difference is exactly the line between "the pull put this
	 * here and may move it" and "somebody filed this here on purpose".
	 */
	private function isManagedFolder(Folder $folder, Folder $root): bool {
		if ($folder->getId() === $root->getId()) {
			return true;
		}
		try {
			return $this->folders->uidOf($folder->getId()) !== '';
		} catch (\Throwable $e) {
			// CANNOT TELL → DO NOT MOVE. Guessing "managed" here would move a user's
			// file out of their own folder on the strength of a failed lookup.
			$this->logger->debug('grafana_sync pull: could not tell whether a folder is a mirror; leaving the file alone', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * The tags in a mirror body, as the pull just wrote it.
	 *
	 * Taken from the BODY rather than from a second read of Grafana, because the body
	 * is what landed on disk — so the file and its Nextcloud tags cannot disagree even
	 * if the dashboard changed again between the two calls. A `link` pointer carries
	 * the dashboard's tags by name for exactly this reason, so both modes work here.
	 */
	private static function tagsIn(string $body): TagSet {
		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return TagSet::empty();
		}
		$tags = is_array($decoded) ? ($decoded['tags'] ?? null) : null;
		return TagSet::of(is_array($tags) ? $tags : []);
	}

	/**
	 * Read a **link's** clocks, best-effort — the ONLY thing a link wants the dashboard
	 * record for, since its body comes from the search row.
	 *
	 * Swallowing is right here and wrong for `sync`: a pointer that cannot be dated is
	 * still a perfectly good pointer, so a transient Grafana error must cost the link its
	 * timestamps and nothing else. The `sync` path deliberately does NOT come through
	 * here — there the same error has to reach {@see pullOne}'s failure counter, because
	 * a file we could not read is a file we did not sync.
	 */
	private function readClocksForLink(string $uid): ?DashboardSpec {
		try {
			return $this->grafana->readDashboardSpec($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync pull: could not read a link\'s dashboard record; keeping its pointer undated', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Does the mirror on disk differ from the body Grafana would write?
	 *
	 * The size check is a free, EXACT "differs" signal — it reads the filecache, not the
	 * storage, so a genuinely changed dashboard never costs a download. Only when the
	 * sizes agree do we read the bytes; that read is the price of not writing, and it is
	 * strictly cheaper than the unconditional write it replaces (on object storage a GET
	 * beats a PUT, and a skipped write is also a skipped etag/mtime bump and a skipped
	 * `NodeWrittenEvent`).
	 *
	 * Compared against the file's REAL bytes rather than the stamped
	 * `grafana_syncedHash`: the stamp records what the last sync *agreed on*, so a mirror
	 * that drifted since (a failed push, a hand edit, a half-written file) would compare
	 * equal to Grafana's body and be left broken forever. A pull has to stay able to heal.
	 *
	 * A read we cannot perform answers **true** — writing is the old behaviour, so an
	 * unreadable mirror degrades to "always rewrite" rather than to "never repair".
	 */
	private function bodyDiffers(File $file, string $body): bool {
		// Both sides to float, not `(int)$file->getSize()`: getSize() is `int|float` and
		// returns a float once a size exceeds PHP_INT_MAX, where an int cast overflows.
		// Float is exact for every integral value up to 2^53, so this compares two sizes
		// numerically at any scale — and `!==` on two floats stays a strict comparison,
		// which casting only one side would have quietly given up.
		if ((float)$file->getSize() !== (float)strlen($body)) {
			return true;
		}
		try {
			return $file->getContent() !== $body;
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not read mirror for change detection; rewriting it', [
				'app' => Application::APP_ID,
				'file' => $file->getName(),
				'exception' => $e,
			]);
			return true;
		}
	}
}
