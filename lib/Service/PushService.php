<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * The writeback (Nextcloud → Grafana) — saga Ch2 Course 3, "the sauce". Pushes a
 * saved **sync** dashboard file up to Grafana as an upsert on its stable uid.
 *
 * Where the n8n master's PushService juggles two channels (REST + webhook), Grafana
 * has **one API, one credential**, so this is the single-channel reduction: decode
 * the file, build the upsert body ({@see DashboardBody::toUpsertBody}), POST it, and
 * on success stamp the loop-guard hash + the freshly-bumped version.
 *
 * The loop guard (Ch1 risk #6): we stamp `grafana_syncedHash = sha1(the file bytes we
 * sent)`. The on-disk body has `version` stripped (pull's encodeSync, push's
 * toUpsertBody), so that hash is stable across Grafana's per-save version bumps — a
 * push→pull round-trip never looks like a change. On **failure we do NOT stamp**, so
 * the next save retries; Grafana's own error message rides the thrown exception.
 *
 * Scope (Course 3): **updates** of dashboards we already track (have a `grafana_uid`).
 * Creating a brand-new dashboard from a hand-made file is Course 4 — such files are
 * skipped here with a log line. A `link` file is a read-only pointer and never pushes.
 */
final class PushService {
	public function __construct(
		private MappingService $mappings,
		private GrafanaClient $grafana,
		private DashboardMetadata $metadata,
		private FolderMirror $folderMirror,
		private MirrorTimes $times,
		private TagSyncService $tagSync,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Push $node's contents to Grafana as an upsert. Returns true when the file was
	 * pushed, false when it isn't a pushable managed sync file. Throws (with Grafana's
	 * own message) if the upsert failed — the caller surfaces it as a notification /
	 * HTTP error, and the unstamped hash means the next save retries.
	 */
	public function push(Node $node): bool {
		if (!$node instanceof File) {
			return false;
		}
		$managed = $this->metadata->read($node->getId());
		if (!$managed?->isManaged()) {
			// No grafana_uid yet → a brand-new hand-made file. Creating it in Grafana is
			// a future step (Course 4); skip for now.
			$this->logger->info('grafana_sync writeback: file has no grafana_uid; new-dashboard create not implemented', [
				'app' => Application::APP_ID,
				'path' => $node->getPath(),
			]);
			return false;
		}
		// Only sync files push. A link file is a read-only pointer; unmapped/ignored never
		// push. A legacy file with no recorded mode is treated as sync (backward compat).
		if ($managed->mode !== '' && !$managed->isSync()) {
			return false;
		}

		$content = $node->getContent();
		try {
			// Decode as an object (not assoc): Grafana's schema is strict about JSON
			// *types* — an empty `{}` round-tripped through an assoc array would re-encode
			// as `[]` and be rejected. stdClass preserves the file's object/array shapes.
			$spec = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException('The dashboard file is not valid JSON: ' . $e->getMessage(), 0, $e);
		}
		if (!$spec instanceof \stdClass) {
			throw new \RuntimeException('The dashboard file is not a JSON object.');
		}
		// The metadata uid is the identity thread — force it onto the spec so a
		// hand-edited `uid` in the file can never retarget a different dashboard.
		$spec->uid = $managed->uid;

		$body = DashboardBody::toUpsertBody($spec, $this->resolveFolderUid($node, $managed), $node->getName());
		$resp = $this->grafana->upsertDashboard($body);

		// Full success: stamp the synced hash so this exact content won't re-trigger a
		// push (loop guard), plus the version Grafana just bumped to.
		$update = [DashboardMetadata::KEY_SYNCED_HASH => sha1($content)];
		$version = isset($resp['version']) ? (string)$resp['version'] : '';
		if ($version !== '') {
			$update[DashboardMetadata::KEY_VERSION] = $version;
		}
		$this->metadata->write($node->getId(), $update);
		$this->stampGrafanaClock($node, $managed->uid);

		// THE THIRD SURFACE. Tags are a top-level key in the spec, so editing the JSON
		// by hand is a way to change them — and Grafana has just accepted that change.
		// Without this the file and Grafana would agree while the Nextcloud tags said
		// something else, until a pull happened to correct it.
		$tags = TagSet::of(is_array($spec->tags ?? null) ? $spec->tags : []);
		$this->tagSync->applyToDashboard($node, $tags);
		return true;
	}

	/**
	 * Give the file the dashboard's clock, by asking Grafana what it just recorded.
	 *
	 * ## WHY A SECOND REQUEST, AND WHY THERE IS NO CHEAPER WAY
	 *
	 * **Grafana owns `meta.updated` and will not accept ours.** Measured against a
	 * live 13.0.2: a dashboard pushed with `updated` set to `2001-01-01` came back
	 * with `meta.updated` at the moment of the write. The body's `updated`/`created`
	 * are stored verbatim and never interpreted — they are payload, not the clock.
	 *
	 * **And the upsert's own answer does not carry it.** `POST /api/dashboards/db`
	 * replies `{folderUid, id, slug, status, uid, url, version}` — no timestamp. So
	 * the only way to learn when Grafana says the dashboard changed is to read it
	 * back, and that costs one GET per pushed dashboard.
	 *
	 * ## WHY IT IS WORTH IT
	 *
	 * Without this the file keeps the mtime Nextcloud set when the user saved, and
	 * Grafana keeps the moment it processed the push. Those agree only when both land
	 * in the same second, so `Modified` was right by luck — which is exactly how it
	 * behaved, failing CI at random across `edit.feature` and `rename.feature`
	 * depending on which side of a second boundary the request fell.
	 *
	 * The old design left this to "the next pull reconciles the two", which is true
	 * and useless: until that pull runs — possibly a scheduled interval away — an
	 * edited dashboard reports a modification time that belongs to nothing.
	 *
	 * ## WHAT IT DOES NOT DO
	 *
	 * It does not force. {@see MirrorTimes::apply()} writes only when the value
	 * actually differs, so a push that happened to land in the same second touches
	 * nothing — and `touch()` propagates a fresh etag to the PARENT FOLDER, which is
	 * what sync clients poll. Stamping unconditionally would churn the folder on
	 * every save forever.
	 *
	 * A failure here is logged and swallowed. The push SUCCEEDED; refusing to report
	 * that because a follow-up read failed would turn a cosmetic clock into a failed
	 * save, and the next pull still corrects it.
	 */
	private function stampGrafanaClock(File $node, string $uid): void {
		try {
			$read = $this->grafana->readDashboardSpec($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: pushed, but could not read the dashboard back to stamp its clock', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return;
		}
		$this->times->apply($node, $read?->updated, $read?->created);
	}

	/**
	 * The Grafana folder uid a push should place the dashboard in, so a writeback never
	 * yanks a dashboard out of its folder.
	 *
	 * **The banked `grafana_folderUid` still wins, and must for now.** It records the
	 * Grafana folder a PULLED dashboard actually lives in — and the pull does not
	 * mirror Grafana's folder tree yet, so a dashboard sitting three folders deep in
	 * Grafana arrives flat in the mapping's root in Nextcloud. Resolving from the
	 * file's Nextcloud location while that is true would push the dashboard out of its
	 * Grafana subfolder and into the mapping root: a silent relocation of somebody
	 * else's dashboard, on a gesture that was only ever an edit.
	 *
	 * So {@see FolderMirror} answers only when the file has no banked folder — which is
	 * exactly the file CREATED in Nextcloud, where its location is the only truth there
	 * is, and where the Grafana folders have to be brought into existence to receive
	 * it.
	 *
	 * When the pull mirrors the tree, the two answers converge and the banked key
	 * becomes the denormalisation it always was; it can be dropped then, not before.
	 *
	 * General placement (null → {@see DashboardBody::toUpsertBody} omits folderUid) is
	 * reached **only** via an explicit reserved-root (`/`) mapping. Any "can't determine
	 * the folder" state — no recorded mapping, or the mapping was deleted — **throws**
	 * rather than defaulting to null, because a silent null would relocate the dashboard
	 * to General on the next push (moving it out of its folder). Failing instead leaves
	 * the file to retry once the mapping is restored/re-created; the notifier shows why.
	 */
	private function resolveFolderUid(Node $node, ManagedFile $managed): ?string {
		if ($managed->folderUid !== '') {
			return $managed->folderUid;
		}
		if ($managed->mappingId === '') {
			// A managed file always records its mapping (stampSynced writes it); missing it
			// means we can't know the placement, so don't guess General.
			throw new \RuntimeException('Cannot push: the file has no recorded Grafana folder mapping.');
		}
		$mapping = $this->mappings->getById($managed->mappingId);
		if ($mapping === null) {
			throw new \RuntimeException(
				'Cannot push: the mapping this dashboard belongs to no longer exists — restore or re-map it before pushing.',
			);
		}
		return $this->folderMirror->folderUidFor($node, $mapping);
	}
}
