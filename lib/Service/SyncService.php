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
use OCP\Files\IMimeTypeLoader;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * The pull reconciler (Grafana → Nextcloud) — Course 2, "the protein plated".
 *
 * For each mapping it provisions the target folder ({@see StorageService}), walks the
 * dashboards Grafana holds in the mapped folder, and reconciles them into files:
 *
 *  - **sync** mode — the full dashboard spec is read and written as `<title>.grafana.json`
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
		private IMimeTypeLoader $mimeLoader,
		private MirrorTimes $times,
		private FolderTreeMirror $tree,
		private TagSyncService $tagSync,
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
	 * @param bool $async reserved for the future background-job path; inline for now
	 * @return array<string,mixed>
	 */
	public function dispatch(string $direction, ?string $mappingId, bool $async): array {
		if ($direction !== self::DIR_PULL && $direction !== self::DIR_PUSH) {
			throw new \InvalidArgumentException('direction must be "pull" or "push"');
		}
		// $async is intentionally ignored until the background-job course; see docblock.
		unset($async);
		return $this->runInline($direction, $mappingId);
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
		foreach ($folder->getDirectoryListing() as $node) {
			if (!FilenameCodec::isDashboardFile($node)) {
				continue;
			}
			$managed = $this->metadata->read($node->getId());
			if (!$managed?->isManaged()) {
				continue;
			}
			// Push only files that are themselves `sync`. A `link`/`ignored` file must not
			// push even in a sync mapping; a legacy file with no recorded mode is treated
			// as sync for backward compatibility.
			if ($managed->mode !== '' && !$managed->isSync()) {
				continue;
			}
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
					if ($this->writeDashboard($into, $mapping, $row, $existingByUid, $nameCounts)) {
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

			$this->fixupFilecacheMimetype();
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
	 * One SQL UPDATE that rewrites every `*.grafana.json` filecache row to the
	 * application/grafana+json mimetype. NC's Detection layer only consults the last
	 * extension segment ('.json' → application/json), so newly-written files carry the
	 * wrong mimetype until this runs. Idempotent (the WHERE clause skips rows already on
	 * the right id) — identical to what {@see \OCA\GrafanaSync\Migration\RegisterMimetype}
	 * runs on install/upgrade.
	 */
	private function fixupFilecacheMimetype(): void {
		try {
			$id = $this->mimeLoader->getId('application/grafana+json');
			$this->mimeLoader->updateFilecache('grafana.json', $id);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: filecache mimetype fixup skipped', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
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
				$node->delete();
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

		$existing = $existingByUid[$uid] ?? null;
		if ($existing instanceof File) {
			$desired = FilenameCodec::format($displayName, $uid, false, 0);
			if ($existing->getName() !== $desired) {
				try {
					// Rename within the file's OWN folder — never yank a file the user
					// put in a subfolder back to the mapping root.
					$existing->move($existing->getParent()->getPath() . '/' . $desired);
				} catch (\Throwable $e) {
					$this->logger->info('rename skipped (collision?)', [
						'app' => Application::APP_ID,
						'from' => $existing->getName(),
						'to' => $desired,
						'exception' => $e,
					]);
				}
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
			$this->times->apply($existing, $read?->updated, $read?->created, $differs);
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
		$this->times->apply($file, $read?->updated, $read?->created, true);
		$this->tagSync->applyToDashboard($file, self::tagsIn($body));
		return false; // a brand-new mirror is always a write
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
