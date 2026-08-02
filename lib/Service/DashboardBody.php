<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * Single source of truth for every shape a Grafana dashboard takes on the wire and
 * on disk — the master's {@see \OCA\N8nSync\Service\N8nWorkflowBody}, re-cut for
 * Grafana's schema. Pure logic (no DI), so it is trivially unit-testable.
 *
 * Keeping the schema quirks here means the pull reconciler ({@see SyncService}), the
 * push/writeback, and the create-on-land flow all encode a dashboard the same way,
 * and Grafana's moving request schema changes in exactly one file.
 *
 * The load-bearing Grafana difference from the n8n master — **volatile fields**:
 *
 *  - Grafana bumps `dashboard.version` on **every** save (saga Ch1 risk #6). If the
 *    on-disk body carried `version`, then a push→pull round-trip would see a higher
 *    version, the body's hash would differ, and the reconciler would churn (or, with
 *    the writeback listener, loop). So `version` is **stripped from the stored spec**
 *    and carried in metadata ({@see DashboardMetadata::KEY_VERSION}) instead — this is
 *    the mechanism behind the contract's "version is stored but NEVER hashed".
 *  - `dashboard.id` is Grafana's internal numeric key, distinct from the stable
 *    `uid`. It differs across instances and across a delete→recreate, so it is also
 *    stripped from the stored spec, and set to `null` on upsert so Grafana keys on
 *    `uid` alone (and never confuses two instances after a restore/import).
 *
 * The stable `uid` is the identity thread and always stays in the body.
 *
 * Two on-disk/on-wire bodies:
 *  - `sync` mode — the full dashboard spec (minus the volatile fields), so a later
 *    writeback is a straight upsert of the file contents.
 *  - `link` mode — a tiny `{$schema:"grafana.reference/v1", uid, title, url,
 *    folderUid, tags}` pointer, not a runtime artifact.
 */
final class DashboardBody {
	/**
	 * Fields Grafana rewrites on every save — excluded from the stored spec so the
	 * body (and thus {@see DashboardMetadata::KEY_SYNCED_HASH}) stays stable across
	 * version bumps. `version` lives in metadata; `id` is re-added as null on upsert.
	 */
	private const VOLATILE = ['id', 'version'];

	/** Encode flags for a human-readable file body (pretty, UTF-8 + slashes verbatim). */
	public const JSON_PRETTY = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/** Schema tag stamped on `link`-mode pointer bodies. */
	public const REFERENCE_SCHEMA = 'grafana.reference/v1';

	/** Default commit message sent with an upsert when the caller gives none. */
	private const DEFAULT_MESSAGE = 'Updated by Nextcloud (grafana_sync)';

	/**
	 * Full dashboard spec for `sync` mode, normalised for stable on-disk storage:
	 * the volatile `id` + `version` are stripped (see class docblock) while the
	 * stable `uid` stays as the identity. The result is what gets written to the
	 * file and what {@see DashboardMetadata::stampSynced()} hashes — stable across
	 * Grafana re-saves.
	 *
	 * TAKES A `\stdClass`, NOT AN ARRAY, AND THAT IS THE WHOLE POINT. A dashboard spec
	 * is full of empty JSON objects — `timepicker: {}`, a panel's `options: {}`,
	 * `fieldConfig.defaults: {}`, `annotations`/`templating` wrappers. `json_decode`
	 * with `assoc = true` turns every one of them into an empty PHP array, and
	 * `json_encode` then writes them back out as `[]`. The file on disk stops matching
	 * the dashboard it mirrors, and because the push reads that file, the `[]` is
	 * eventually sent back to Grafana.
	 *
	 * {@see PushService} already decodes the *file* as objects and says so in its own
	 * comment — the pull was the half that silently undid it, writing the flattened
	 * shapes the push then carefully preserved. Same defect the n8n sibling hit from
	 * the other direction. Keeping stdClass end to end is what makes the round-trip
	 * shape-exact.
	 *
	 * @param \stdClass $dashboard the `dashboard` object from GET /api/dashboards/uid/{uid},
	 *                             decoded with `assoc = false`
	 */
	public static function encodeSync(\stdClass $dashboard): string {
		// Clone: the caller's object may still be read for its `version` afterwards,
		// and unsetting properties in place would take that away.
		$spec = clone $dashboard;
		foreach (self::VOLATILE as $k) {
			unset($spec->{$k});
		}
		return json_encode($spec, self::JSON_PRETTY);
	}

	/**
	 * Tiny pointer body for `link` mode — uid, title, deep-link URL, folder uid, and
	 * tags. Grafana organises by **folder** (not tag), so `folderUid` is the placement
	 * breadcrumb; the dashboard's own `tags` are carried through verbatim as secondary
	 * labels. `$url` is the absolute deep-link (from {@see GrafanaClient::deepLink()} /
	 * {@see GrafanaClient::deepLinkFromPath()}); an empty string yields a null `url`.
	 *
	 * @param array<string,mixed> $dashboard search row or dashboard object (uid/title/tags)
	 */
	public static function encodeReference(array $dashboard, string $url, string $folderUid): string {
		$uid = (string)($dashboard['uid'] ?? '');
		$tags = [];
		foreach ($dashboard['tags'] ?? [] as $t) {
			if (is_string($t) && $t !== '') {
				$tags[] = $t;
			}
		}
		$payload = [
			'$schema' => self::REFERENCE_SCHEMA,
			'uid' => $uid,
			'title' => (string)($dashboard['title'] ?? $uid),
			'url' => $url === '' ? null : $url,
			'folderUid' => $folderUid,
			'tags' => $tags,
		];
		return json_encode($payload, self::JSON_PRETTY);
	}

	/**
	 * Build the `POST /api/dashboards/db` body (Grafana's single upsert endpoint —
	 * create and update in one) from a file's decoded dashboard JSON.
	 *
	 * Grafana keys the upsert on `dashboard.uid`, so:
	 *  - `id` is forced to null — Grafana matches by uid and never confuses instances
	 *    across a restore/import;
	 *  - `version` is dropped and `overwrite:true` is set, so our stripped-spec push is
	 *    accepted rather than 409'd on a stale/absent version;
	 *  - `folderUid` is a **top-level** sibling of `dashboard` (not nested inside it),
	 *    and the reserved {@see GrafanaClient::FOLDER_GENERAL} sentinel / empty string
	 *    means "root / General", which Grafana expresses by omitting `folderUid`.
	 *
	 * Title authority: the JSON `title` if non-empty, else the filename stem, else
	 * "Untitled" — so a hand-created file with no title still lands with a sane name.
	 *
	 * @return array<string,mixed>
	 */
	public static function toUpsertBody(\stdClass $dashboard, ?string $folderUid, string $basename = '', string $message = ''): array {
		$title = isset($dashboard->title) && is_string($dashboard->title) ? trim($dashboard->title) : '';
		if ($title === '') {
			$dashboard->title = self::titleFromBasename($basename);
		}
		// uid stays as the identity; id must be null so Grafana keys on uid alone.
		$dashboard->id = null;
		unset($dashboard->version);

		$body = [
			'dashboard' => $dashboard,
			'overwrite' => true,
			'message' => $message !== '' ? $message : self::DEFAULT_MESSAGE,
		];
		// Root / General = no folder → omit folderUid entirely (Grafana's convention).
		if ($folderUid !== null && $folderUid !== '' && $folderUid !== GrafanaClient::FOLDER_GENERAL) {
			$body['folderUid'] = $folderUid;
		}
		return $body;
	}

	/**
	 * Derive a clean dashboard title from the on-disk filename: strip `.grafana.json`
	 * and any trailing " (N)" collision suffix via {@see FilenameCodec::parse()}; fall
	 * back to "Untitled".
	 */
	private static function titleFromBasename(string $basename): string {
		$parsed = FilenameCodec::parse($basename);
		$name = $parsed !== null ? trim($parsed['name']) : '';
		return $name !== '' ? $name : 'Untitled';
	}
}
