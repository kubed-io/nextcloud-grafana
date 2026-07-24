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
 * Scope note: this course is the **flat** pull — every dashboard in the mapped Grafana
 * folder lands directly in the one Nextcloud folder. The subfolder mirror + whole-instance
 * cascade (and the `grafana:ignore` reserved-tag override) ride this same loop in later
 * courses; the seams (`effectiveMode`, the ignored-index split) are left in place for them.
 */
final class SyncService {
	/** Sync directions — the parity vocabulary shared with the n8n master. */
	public const DIR_PULL = 'pull';
	public const DIR_PUSH = 'push';

	public function __construct(
		private MappingService $mappings,
		private GrafanaClient $grafana,
		private DashboardMetadata $metadata,
		private OwnershipTags $tags,
		private StorageService $storage,
		private SyncGuard $guard,
		private PushService $push,
		private IMimeTypeLoader $mimeLoader,
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
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int, status:string, message:?string}
	 */
	public function pullAll(): array {
		$total = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0];
		$errors = [];
		foreach ($this->mappings->list() as $mapping) {
			try {
				$res = $this->pullOne($mapping);
				$total['processed'] += $res['processed'];
				$total['succeeded'] += $res['succeeded'];
				$total['failed'] += $res['failed'];
				$total['pruned'] += $res['pruned'];
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
	 * @return array{processed:int, succeeded:int, failed:int, pruned:int}
	 */
	public function pullOne(Mapping $mapping): array {
		$empty = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pruned' => 0];

		// A Team Folder with no groups is invisible to everyone — skip rather than
		// create dead storage. The admin-owned backend is always visible to the actor,
		// so an empty group list there is fine (admin-only), and we do NOT skip it.
		if ($mapping->useTeamFolder && $mapping->ncGroups === []) {
			$this->logger->warning('skipping Team Folder mapping with no groups; it would be invisible', [
				'app' => Application::APP_ID,
				'ncFolder' => $mapping->ncFolder,
			]);
			return $empty;
		}
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

			$processed = 0;
			$succeeded = 0;
			$failed = 0;

			$ignoredUids = [];
			$existingByUid = $this->indexByUid($targetFolder, $mapping, $ignoredUids);
			$nameCounts = [];
			$seenUids = [];

			foreach ($this->grafana->listDashboards($this->grafanaScope($mapping)) as $row) {
				$processed++;
				$uid = $row['uid'];
				// A file locally marked `ignored` is left strictly alone — its dashboard
				// still lives in the mapped folder, but the user opted it out, so skip
				// re-pulling it (would otherwise write a NEW collision-suffixed file). No
				// origin sets `ignored` in this course yet; the seam is here for the
				// reserved-tag course.
				if (isset($ignoredUids[$uid])) {
					continue;
				}
				// The dashboard takes the mapping's mode. The per-dashboard override
				// (grafana:ignore → skip) lands with the reserved-tag course; until then
				// the effective mode is simply the mapping's.
				$effectiveMode = $mapping->mode;
				$seenUids[$uid] = true;
				try {
					$this->writeDashboard($targetFolder, $mapping, $row, $effectiveMode, $existingByUid, $nameCounts);
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
			return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed, 'pruned' => $pruned];
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * Translate a mapping's stored Grafana folder uid into the `/api/search` scope
	 * {@see GrafanaClient::listDashboards()} takes: the reserved-root `/` selects the
	 * "General" area (no-folder dashboards); any other value is a real folder uid whose
	 * direct children are walked. (Whole-instance cascade — scope `null` — is a subfolder
	 * course concern; this flat course always scopes to exactly one folder.)
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
	private function indexByUid(Folder $root, Mapping $mapping, array &$ignoredUids): array {
		$index = [];
		$this->collectManaged($root, $mapping, $index, $ignoredUids);
		return $index;
	}

	/**
	 * Ignored files are kept OUT of $index (so prune leaves them) but their uids are
	 * collected into $ignoredUids, so the pull can skip re-pulling them.
	 *
	 * @param array<string,Node> $index
	 * @param array<string,true> $ignoredUids
	 */
	private function collectManaged(Folder $folder, Mapping $mapping, array &$index, array &$ignoredUids): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->collectManaged($node, $mapping, $index, $ignoredUids);
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
			// An `ignored` file stays put — excluded from sync on purpose. Never index it
			// (so prune can't delete it), but surface its uid so the pull skips it.
			if ($managed->isIgnored()) {
				$ignoredUids[$uid] = true;
				continue;
			}
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
	 * @param array{uid:string, title:string, folderUid:string, url:string, tags:list<string>} $row
	 * @param string $effectiveMode Mapping::MODE_SYNC|MODE_LINK for this dashboard
	 * @param array<string,Node> $existingByUid
	 * @param array<string,int> $nameCounts
	 */
	private function writeDashboard(
		Folder $folder,
		Mapping $mapping,
		array $row,
		string $effectiveMode,
		array $existingByUid,
		array &$nameCounts,
	): void {
		$uid = $row['uid'];
		$displayName = $row['title'] !== '' ? $row['title'] : $uid;

		if ($effectiveMode === Mapping::MODE_LINK) {
			// Lightweight pointer — no full spec read. Version is inert for a link (a
			// pointer never pushes), so it stays empty.
			$url = $row['url'] !== '' ? $this->grafana->deepLinkFromPath($row['url']) : $this->grafana->deepLink($uid);
			$body = DashboardBody::encodeReference($row, $url, $row['folderUid']);
			$version = '';
		} else {
			// Sync — read the full record for the spec we serialize + the version we bank.
			$record = $this->grafana->readDashboard($uid);
			$dashboard = $record['dashboard'] ?? [];
			$version = (string)($dashboard['version'] ?? '');
			$body = DashboardBody::encodeSync($dashboard);
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
			$existing->putContent($body);
			$this->metadata->stampSynced($existing->getId(), $uid, $effectiveMode, $version, $body, $mapping->id);
			$this->tags->apply($existing->getId(), $effectiveMode);
			return;
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
		$this->metadata->stampSynced($file->getId(), $uid, $effectiveMode, $version, $body, $mapping->id);
		$this->tags->apply($file->getId(), $effectiveMode);
	}
}
