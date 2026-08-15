<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use Psr\Log\LoggerInterface;

/**
 * Create-on-land (Course 4 · Slice 1): turn a `*.grafana.json` file in a mapped sync
 * folder that carries no `grafana_uid` yet into a real Grafana dashboard.
 *
 * Triggered by {@see \OCA\GrafanaSync\Listener\CreateInGrafanaListener} when:
 *  - a new file is made via the Files "New" menu / Text editor into a mapped folder,
 *  - a hand-made `.grafana.json` is moved/dropped into a mapped folder, or
 *  - an external WebDAV PUT lands content in a mapped folder.
 *
 * **Simpler than the n8n master's `CreateService` in two ways** (the ingredient bends
 * it): Grafana's `POST /api/dashboards/db` is an **upsert**, so create and update are
 * the *same* call — there is no separate create endpoint; and we place a dashboard by
 * **folder** ({@see DashboardBody::toUpsertBody}'s `folderUid`), so there is **no
 * mapping tag** to assign additively the way n8n must. The body is shaped by the same
 * `toUpsertBody` the push uses, so a file that round-trips "create then later push" is
 * byte-stable.
 *
 * Re-adopt for free: because the write keys on `dashboard.uid`, a file that *carries* a
 * uid upserts on it (re-attaching to that dashboard), while a fresh file with no uid
 * lets Grafana mint one and returns it. Either way the returned uid becomes the file's
 * stamped identity.
 *
 * The post-create stamp (metadata + ownership pill + mimetype re-stamp) is wrapped in
 * {@see SyncGuard} so the implicit re-write doesn't echo into
 * {@see \OCA\GrafanaSync\Listener\NodeWrittenListener} as a writeback.
 */
final class CreateService {
	public function __construct(
		private GrafanaClient $grafana,
		private DashboardMetadata $metadata,
		private FolderMirror $folderMirror,
		private MirrorTimes $times,
		private SyncGuard $guard,
		private IMimeTypeLoader $mimeLoader,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create $node's contents as a dashboard in Grafana, placed in $mapping's folder,
	 * and stamp the file with full sync metadata. Returns the new dashboard uid.
	 *
	 * Throws on any unrecoverable failure; the listener turns that into a notification.
	 *
	 * @param bool $asNewDashboard the caller KNOWS this must be a brand-new dashboard,
	 *                             so any uid in the body is the wrong dashboard's and is
	 *                             dropped. Re-adoption (the default) is the create-on-land
	 *                             behaviour described above; a copy is its exact opposite.
	 */
	public function createForFile(File $node, Mapping $mapping, bool $asNewDashboard = false): string {
		$content = $node->getContent();
		$spec = $this->parseFileBody($content);

		// A COPY IS ALWAYS A BIRTH, and the body it was copied from names its own
		// dashboard — every mirror a sync writes carries `uid` in the JSON. Left in, the
		// upsert below keys on it and writes the copy OVER the dashboard being copied
		// (measured live: the source gained a v2 and no second dashboard existed).
		// Dropped from the spec rather than from the file, because the copy's hook holds
		// locks and cannot rewrite it — see CopyService for the whole story.
		//
		// AND IT IS ALSO A NAMING, by Nextcloud, which the body cannot know about. The
		// bytes are the original's, so `title` still says the ORIGINAL's name; the file
		// they landed in is called whatever Nextcloud picked to avoid a collision. Left
		// alone, a copy made beside its source reached Grafana as a second dashboard
		// titled exactly like the first while its file said `(1)` — three places, two
		// answers. The filename is the authority here for the same reason a rename's is:
		// it is the thing the user just changed.
		if ($asNewDashboard) {
			unset($spec->uid);
			$display = FilenameCodec::displayName($node->getName());
			if ($display !== '') {
				$spec->title = $display;
			}
		}

		// WHERE the dashboard lands: the Grafana folder mirroring the Nextcloud folder
		// the file is actually in, not the mapping's root. Creating a dashboard in
		// "Demo/Team/Drafts" is what brings "Team" and "Drafts" into existence over
		// there — a folder is in Grafana when a dashboard is in it. A file sitting
		// directly in the mapped folder resolves to the mapping without a round-trip,
		// and a reserved-root ("/") mapping still yields null, which toUpsertBody omits.
		$folderUid = $this->folderMirror->folderUidFor($node, $mapping);
		$body = DashboardBody::toUpsertBody($spec, $folderUid, $node->getName());
		$created = $this->grafana->upsertDashboard($body);

		$uid = (string)($created['uid'] ?? '');
		if ($uid === '') {
			throw new \RuntimeException('Grafana create did not return a dashboard uid');
		}
		$version = (string)($created['version'] ?? '');

		// Use the ORIGINAL file bytes for the loop-guard hash: NodeWrittenListener
		// computes sha1($node->getContent()) on the next save, so they must match.
		$this->stampFile($node, $mapping, $uid, $version, $content);

		// And give the new file the dashboard's clock. Same reasoning as the push
		// ({@see PushService::stampGrafanaClock()}): Grafana sets meta.created and
		// meta.updated itself and will not take ours, and the create ack does not
		// return them — so the only way to agree with Grafana about when this
		// dashboard came into being is to ask. Swallowed on failure: the dashboard
		// exists and the file is stamped, so a cosmetic clock must not undo that.
		try {
			$read = $this->grafana->readDashboardSpec($uid);
			$this->times->apply($node, $read?->lastChanged(), $read?->created);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: created, but could not read the dashboard back to stamp its clock', [
				'uid' => $uid,
				'exception' => $e,
			]);
		}

		return $uid;
	}

	/**
	 * Decode the file as a stdClass (objects, not assoc) so an empty `{}` round-trips
	 * correctly. An **empty** file is tolerated (a fresh "New"-menu file) — Grafana mints
	 * a minimal dashboard with the filename as its title (via {@see DashboardBody::toUpsertBody}).
	 * A **non-empty, non-object** body (a JSON array or scalar) is a malformed dashboard and
	 * fails loudly, consistent with {@see PushService::push} — we never silently coerce it
	 * into an empty dashboard.
	 */
	private function parseFileBody(string $content): \stdClass {
		if (trim($content) === '') {
			return new \stdClass();
		}
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

	/**
	 * Stamp Files-Metadata (uid + mode + version + syncedHash + mapping), apply the
	 * ownership pill, and re-stamp the custom mimetype so the icon shows immediately —
	 * all wrapped in the SyncGuard so the implicit re-writes don't echo into the
	 * writeback listener.
	 */
	private function stampFile(File $node, Mapping $mapping, string $uid, string $version, string $content): void {
		$this->guard->run(function () use ($node, $mapping, $uid, $version, $content): void {
			$this->metadata->stampSynced($node->getId(), $uid, $mapping->mode, $version, $content, $mapping->id);
			try {
				$this->mimeLoader->updateFilecache('grafana.json', $this->mimeLoader->getId('application/grafana+json'));
			} catch (\Throwable $e) {
				$this->logger->warning('grafana_sync: post-create mimetype re-stamp failed', [
					'app' => Application::APP_ID,
					'fileId' => $node->getId(),
					'exception' => $e,
				]);
			}
		});
	}
}
