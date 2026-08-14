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
 * Tags, in both directions, for both kinds of thing (`dashboards/tags.feature`,
 * `folders/tags.feature`).
 *
 * ## THE TWO HALVES ARE NOT THE SAME MECHANISM
 *
 * A **dashboard's** tags are body-native: `tags` is a top-level key in the spec, so
 * a tag change IS a content change. Pushing one is therefore not a special call at
 * all — rewrite the file's `tags` and let the ordinary write path carry it, which
 * also means the hash guard, the version stamp and the Grafana clock all happen for
 * free. This is the easier kitchen than the sibling n8n app's, whose API marks tags
 * read-only on the workflow and forces a separate endpoint plus a hash re-stamp.
 *
 * A **folder's** tags are not in Grafana's model at all. They live in an annotation
 * on the app-platform folder object ({@see TagSet::FOLDER_ANNOTATION}), reached by
 * merge patch, and nothing about a folder's content changes when they do. So that
 * half is a direct call, and — measured — it moves no clock on either side.
 *
 * ## THE LOOP, AND WHY EVERY PATH HERE COMPARES FIRST
 *
 * Both directions raise the other's trigger. Importing a tag fires Nextcloud's tag
 * event; pushing one writes a file, which fires the write event. {@see SyncGuard}
 * covers the writes this app makes on purpose, but the guard is a request-scoped
 * flag and cannot help across a scheduled pull that lands while a user is tagging.
 * So the real defence is that **every path here is a no-op when the sets already
 * agree** — {@see TagSet::equals()} is order-insensitive, so "dns, linux" arriving
 * against "linux, dns" settles instead of ping-ponging.
 *
 * ## MODE
 *
 * Only `sync` pushes. Under a `link` the tree and its state are Grafana's, so a tag
 * applied in Nextcloud does not travel — the next pull puts Grafana's set back, which
 * is the "settles back" the spec describes. Nothing here forces that reversion; it
 * falls out of the pull being the only writer for a link.
 */
final class TagSyncService {
	public function __construct(
		private NextcloudTags $ncTags,
		private GrafanaClient $grafana,
		private DashboardMetadata $metadata,
		private FolderMetadata $folders,
		private MappingService $mappings,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	// ── inbound: Grafana → Nextcloud ───────────────────────────────────────────

	/**
	 * Give a mirrored dashboard file the tags its body carries.
	 *
	 * The body is the source because the pull has just written it, so no second read
	 * of Grafana is needed — the tags are already in the bytes on disk.
	 */
	public function applyToDashboard(File $file, TagSet $fromBody): void {
		$this->guard->run(function () use ($file, $fromBody): void {
			$this->ncTags->set($file->getId(), $fromBody);
		});
	}

	/**
	 * Give a mirrored folder the tags its Grafana folder carries.
	 *
	 * Failures are logged and swallowed: a pull that cannot read one folder's tags
	 * has still mirrored the dashboards, and refusing the whole sync over a cosmetic
	 * field would be a poor trade.
	 */
	public function applyToFolder(Folder $folder, string $grafanaUid): void {
		// A root mapping is the whole instance, not a folder — see TagSet::ROOT_FOLDER.
		// It declines in this direction too, so the two halves cannot disagree.
		if ($grafanaUid === '' || $grafanaUid === TagSet::ROOT_FOLDER) {
			return;
		}
		try {
			$tags = $this->grafana->readFolderTags($grafanaUid);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not read a Grafana folder\'s tags', [
				'app' => Application::APP_ID,
				'uid' => $grafanaUid,
				'exception' => $e,
			]);
			return;
		}
		$this->guard->run(function () use ($folder, $tags): void {
			$this->ncTags->set($folder->getId(), $tags);
		});
	}

	// ── outbound: Nextcloud → Grafana ──────────────────────────────────────────

	/**
	 * A user changed a mirrored dashboard file's Nextcloud tags.
	 *
	 * Writes the set into the file's `tags` and saves. The save is what reaches
	 * Grafana — see the class docblock — so there is deliberately no upsert here.
	 *
	 * Returns true when the file was rewritten, which is what the tests assert
	 * against; the push that follows is the write path's business, not this one's.
	 */
	public function pushDashboard(File $file, TagSet $wanted): bool {
		$managed = $this->metadata->read($file->getId());

		// A LINK IS THE ONLY REFUSAL. Its tags are Grafana's, and the next pull puts
		// Grafana's set back — writing the body here would change a pointer's contents
		// to something the mirror is about to overwrite.
		if ($managed?->isLink() === true) {
			return false;
		}

		// AN UNMAPPED FILE STILL GETS ITS BODY UPDATED, and that is deliberate rather
		// than an oversight. Tag sync is a mapped-file feature in the sense that nothing
		// reaches Grafana — but the file's OWN TWO SURFACES still track each other, which
		// is what makes a tag applied out here survive being moved back into a mapping.
		// Nothing below calls Grafana; the write is only a write, and PushService will
		// not push a file with no uid.
		try {
			$spec = json_decode($file->getContent(), false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('grafana_sync: a tagged dashboard file is not valid JSON', [
				'app' => Application::APP_ID,
				'fileId' => $file->getId(),
				'exception' => $e,
			]);
			return false;
		}
		if (!$spec instanceof \stdClass) {
			return false;
		}

		$inBody = $spec->tags ?? null;
		$current = TagSet::of(is_array($inBody) ? $inBody : []);
		if ($current->equals($wanted)) {
			return false; // already agrees — writing would only restart the loop
		}

		// A LIST, never an object. json_encode turns a gappy array into `{"0":"dns"}`,
		// and Grafana does not read that back as tags.
		$spec->tags = $wanted->toList();

		try {
			$file->putContent(json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not write tags into a dashboard file', [
				'app' => Application::APP_ID,
				'fileId' => $file->getId(),
				'exception' => $e,
			]);
			return false;
		}
		return true;
	}

	/**
	 * A user changed a mirrored folder's Nextcloud tags.
	 *
	 * Unlike the dashboard half this talks to Grafana directly, because a folder's
	 * tags are not part of anything Nextcloud would otherwise send.
	 */
	public function pushFolder(Folder $folder, TagSet $wanted): bool {
		$mapping = $this->mappings->resolveForPath($folder->getPath());
		if ($mapping === null || $mapping->mode !== Mapping::MODE_SYNC) {
			return false; // outside a mapping, or a link — Grafana owns the state
		}

		// THE MAPPED FOLDER ITSELF IS NEVER STAMPED. FolderMirror and FolderTreeMirror
		// stamp SUBfolders only, so asking FolderMetadata for the mapping's own folder
		// answers '' — and reading that as "not ours" would silently refuse to tag the
		// most obvious folder in the mapping. Its Grafana uid is the mapping's, which
		// is the same source the pull uses when it dresses that folder on the way in.
		$uid = $folder->getId() === $mapping->ncFolderId
			? $mapping->grafanaFolderUid
			: $this->folders->uidOf($folder->getId());
		if ($uid === '' || $uid === TagSet::ROOT_FOLDER) {
			// Either a folder the user made for their own reasons, or the reserved root
			// mapping — which is the whole Grafana instance, not a folder that can carry
			// an annotation.
			return false;
		}

		try {
			if ($this->grafana->readFolderTags($uid)->equals($wanted)) {
				return false;
			}
			$this->grafana->writeFolderTags($uid, $wanted);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not write a folder\'s tags to Grafana', [
				'app' => Application::APP_ID,
				'uid' => $uid,
				'exception' => $e,
			]);
			return false;
		}
		return true;
	}
}
