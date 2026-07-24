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

		$body = DashboardBody::toUpsertBody($spec, $this->resolveFolderUid($managed), $node->getName());
		$resp = $this->grafana->upsertDashboard($body);

		// Full success: stamp the synced hash so this exact content won't re-trigger a
		// push (loop guard), plus the version Grafana just bumped to.
		$update = [DashboardMetadata::KEY_SYNCED_HASH => sha1($content)];
		$version = isset($resp['version']) ? (string)$resp['version'] : '';
		if ($version !== '') {
			$update[DashboardMetadata::KEY_VERSION] = $version;
		}
		$this->metadata->write($node->getId(), $update);
		return true;
	}

	/**
	 * The Grafana folder uid a push should place the dashboard in, so a writeback never
	 * yanks a dashboard out of its folder. Prefer the file's banked `grafana_folderUid`
	 * (a later course writes it); otherwise resolve from the originating mapping. The
	 * reserved-root mapping (`/`) means "General / no folder" → null, which
	 * {@see DashboardBody::toUpsertBody} expresses by omitting folderUid.
	 */
	private function resolveFolderUid(ManagedFile $managed): ?string {
		if ($managed->folderUid !== '') {
			return $managed->folderUid;
		}
		if ($managed->mappingId !== '') {
			$mapping = $this->mappings->getById($managed->mappingId);
			if ($mapping !== null) {
				return $mapping->grafanaFolderUid === '/' ? null : $mapping->grafanaFolderUid;
			}
		}
		return null;
	}
}
