<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the Grafana HTTP API.
 *
 * One source of truth for:
 *  - reading + decrypting the stored service-account token,
 *  - resolving the configured base URL (trim trailing slash so callers don't have to),
 *  - issuing requests through Nextcloud's IClientService (so HTTP proxying,
 *    `allow_local_address`, and TLS settings stay consistent with the rest of the
 *    platform), and
 *  - normalising error shapes so callers get a small set of typed exceptions.
 *
 * POC surface — only what the admin "Test connection" appetizer needs:
 *
 *   ping()   — cheapest call to verify URL + token (used by the Test connection
 *              button and the occ smoke command).
 *
 * Read surface (the pull — Grafana → Nextcloud):
 *
 *   listFolders()          — the folders the mapping picker discovers uids from.
 *   listDashboards(folder) — the dashboards to reconcile, scoped by folder (or all).
 *   readDashboard(uid)     — one dashboard's full `{meta, dashboard}` record.
 *   deepLink() / deepLinkFromPath() — browser URL to a live dashboard.
 *
 * The upsert/delete write surface (upsertDashboard / deleteDashboard) lands with
 * the push + delete Courses — it rides this same request() chokepoint, so the auth
 * + egress trade-off is decided once, here.
 *
 * Auth: Grafana takes a **service-account token** as `Authorization: Bearer
 * <token>` (unlike n8n's `X-N8N-API-KEY` header). One credential, one envelope.
 */
final class GrafanaClient {
	/**
	 * Reserved `folderUIDs` value for Grafana's root / "General" area — the
	 * dashboards that live in no folder. Grafana accepts this literal in
	 * `/api/search`, so the reserved-root mapping (`/` in the picker) translates to
	 * exactly `listDashboards(self::FOLDER_GENERAL)`.
	 */
	public const FOLDER_GENERAL = 'general';

	/** `/api/search` row type for a dashboard (vs `dash-folder`). */
	private const TYPE_DASHBOARD = 'dash-db';

	/** Page size for `/api/search` paging. Generous — a homelab rarely spans pages. */
	private const SEARCH_PAGE_SIZE = 1000;

	/** Hard stop against a server that ignores paging and never returns a short page. */
	private const MAX_PAGES = 50;

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Cheapest **authenticated** call — lists the folders the token can see.
	 *
	 * Deliberately NOT `/api/health`: that endpoint is unauthenticated in Grafana
	 * (returns 200 with no token), so it would prove reachability but not that the
	 * token is valid. `/api/folders` requires a working Bearer token — a bad or
	 * missing token returns 401 — so a green result proves the token decrypts, is
	 * accepted, and carries at least folder-read scope (the unit the sync maps on).
	 *
	 * Throws on auth/network failure so the caller decides how to present it.
	 *
	 * @return array{httpStatus:int, message:string}
	 */
	public function ping(): array {
		$res = $this->request('GET', '/api/folders', ['limit' => 1000]);
		$code = $res->getStatusCode();
		$body = $this->decode($res);
		$count = count($body);
		$plural = $count === 1 ? 'folder' : 'folders';
		return [
			'httpStatus' => $code,
			'message' => "Authenticated to Grafana (HTTP $code) — token valid, $count $plural visible.",
		];
	}

	/**
	 * List the Grafana folders the token can see, normalised to the small shape the
	 * folder-mapping picker needs: `{uid, title, parentUid}`. `parentUid` is present
	 * on nested folders (Grafana ≥ 11) and empty for top-level ones, so the admin
	 * panel can render the tree later without a schema change here.
	 *
	 * This is the read half of the mapping model — the mapping stores a folder uid,
	 * and this is where the panel discovers which uids exist. Writes
	 * (create/upsert/delete a dashboard) land in the sync chapter on this same
	 * request() chokepoint.
	 *
	 * @return list<array{uid:string, title:string, parentUid:string}>
	 */
	public function listFolders(): array {
		$res = $this->request('GET', '/api/folders', ['limit' => 1000]);
		$body = $this->decode($res);
		$out = [];
		foreach ($body as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$uid = (string)($entry['uid'] ?? '');
			if ($uid === '') {
				continue;
			}
			$out[] = [
				'uid' => $uid,
				'title' => (string)($entry['title'] ?? $uid),
				'parentUid' => (string)($entry['parentUid'] ?? ''),
			];
		}
		return $out;
	}

	/**
	 * Resolve a Grafana folder's uid from its title. The admin names the recycle-bin
	 * folder by its human title (e.g. "nextcloud-trash"), so the delete engine maps it
	 * to a uid at use-time. Case-sensitive exact match; returns null when no folder
	 * carries that title. Grafana permits duplicate titles, so the bin folder should be
	 * given a unique name — the first match wins.
	 */
	public function resolveFolderUidByTitle(string $title): ?string {
		$title = trim($title);
		if ($title === '') {
			return null;
		}
		foreach ($this->listFolders() as $folder) {
			if ($folder['title'] === $title) {
				return $folder['uid'];
			}
		}
		return null;
	}

	/**
	 * List the dashboards Grafana holds, scoped by folder, normalised to the small
	 * shape the pull reconciler indexes on: `{uid, title, folderUid, url, tags}`.
	 * This is the "which dashboards exist" half of the pull — the reconciler then
	 * reads each one's full spec with {@see readDashboard()}.
	 *
	 * Scope (`$folderUid`) maps straight onto Grafana's `/api/search` semantics:
	 *  - **null** — no folder filter → **every** dashboard in the instance. This is
	 *    what a whole-instance mirror (root mapping + cascade) walks.
	 *  - **{@see FOLDER_GENERAL}** (`'general'`) — the root / "General" area:
	 *    dashboards with **no** folder. Grafana accepts the literal `general` in
	 *    `folderUIDs`, so the reserved-root mapping translates to exactly this.
	 *  - **a real folder uid** — the **direct** children of that one folder only.
	 *    Grafana's search is NOT recursive, so cascading a subtree is a walk of
	 *    child folders (done in the reconciler, not here).
	 *
	 * Grafana's `/api/search` returns a flat JSON array and pages with `page` +
	 * `limit` (1-indexed); we walk pages until a short/empty one, bounded by
	 * {@see self::MAX_PAGES} against a server that ignores paging.
	 *
	 * @return list<array{uid:string, title:string, folderUid:string, url:string, tags:list<string>}>
	 */
	public function listDashboards(?string $folderUid = null): array {
		$query = ['type' => self::TYPE_DASHBOARD, 'limit' => self::SEARCH_PAGE_SIZE];
		if ($folderUid !== null) {
			// 'general' selects no-folder dashboards; any other value is a folder uid.
			$query['folderUIDs'] = $folderUid;
		}
		$out = [];
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$batch = $this->decode($this->request('GET', '/api/search', $query + ['page' => $page]));
			$seen = 0;
			foreach ($batch as $entry) {
				$seen++;
				if (!is_array($entry)) {
					continue;
				}
				$uid = (string)($entry['uid'] ?? '');
				if ($uid === '') {
					// Folder rows (type=dash-folder) and malformed rows carry no dash uid.
					continue;
				}
				$tags = [];
				foreach (($entry['tags'] ?? []) as $tag) {
					if (is_string($tag) && $tag !== '') {
						$tags[] = $tag;
					}
				}
				$out[] = [
					'uid' => $uid,
					'title' => (string)($entry['title'] ?? $uid),
					'folderUid' => (string)($entry['folderUid'] ?? ''),
					'url' => (string)($entry['url'] ?? ''),
					'tags' => $tags,
				];
			}
			// A short page (fewer than the page size) is the last page.
			if ($seen < self::SEARCH_PAGE_SIZE) {
				return $out;
			}
		}
		$this->logger->warning('Grafana dashboard search hit MAX_PAGES guard', [
			'app' => Application::APP_ID,
			'folderUid' => $folderUid ?? '(all)',
		]);
		return $out;
	}

	/**
	 * Read one dashboard's full record by uid: `GET /api/dashboards/uid/{uid}`.
	 * Grafana returns `{meta:{…}, dashboard:{…}}` — `dashboard` is the spec we
	 * serialize to the file, and `meta` carries the placement/version context
	 * (`meta.folderUid`, `meta.url`, and the spec's own `version`) the reconciler
	 * stamps into metadata. Returned verbatim so callers pick what they need.
	 *
	 * @return array{meta?:array<string,mixed>, dashboard?:array<string,mixed>}
	 */
	public function readDashboard(string $uid): array {
		$res = $this->request('GET', '/api/dashboards/uid/' . rawurlencode($uid));
		// decode() is typed array<string,mixed> because it serves every endpoint; this
		// one endpoint's shape is known, and asserting it here is what lets callers rely
		// on `meta.folderUid` without re-checking. Both keys stay optional: Grafana has
		// answered 200 with a partial body before now.
		/** @var array{meta?:array<string,mixed>, dashboard?:array<string,mixed>} $record */
		$record = $this->decode($res);
		return $record;
	}

	/**
	 * The same read as {@see readDashboard()}, but returning the `dashboard` spec as a
	 * **`\stdClass`** — object shapes intact.
	 *
	 * This exists because {@see decode()} is `assoc = true` everywhere, which is right
	 * for the places that just want `$row['uid']`, and wrong for the one place that
	 * re-serializes a whole dashboard to disk. A dashboard is full of empty JSON
	 * objects (`timepicker: {}`, a panel's `options: {}`, `fieldConfig.defaults: {}`),
	 * and an assoc round-trip rewrites every one of them as `[]`. See
	 * {@see DashboardBody::encodeSync()} for what that costs.
	 *
	 * It also carries back `meta.updated` / `meta.created`, which arrive in the **same
	 * response** as the spec. Returning them together is deliberate: they were being
	 * discarded one line below, which made "when did this dashboard actually change?"
	 * look like it needed a second request when it never did (saga Ch2, Course 8). A
	 * caller cannot forget them now, because they come attached to the thing it wanted.
	 *
	 * Returns null when Grafana's response carries no usable `dashboard` object, so the
	 * caller can skip the file rather than write a body that means nothing.
	 */
	public function readDashboardSpec(string $uid): ?DashboardSpec {
		$res = $this->request('GET', '/api/dashboards/uid/' . rawurlencode($uid));
		$body = (string)$res->getBody();
		if ($body === '') {
			return null;
		}
		try {
			$record = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('Grafana response was not valid JSON', ['exception' => $e]);
			throw new \RuntimeException('Grafana response was not valid JSON.', 0, $e);
		}
		if (!$record instanceof \stdClass) {
			return null;
		}
		$spec = $record->dashboard ?? null;
		if (!$spec instanceof \stdClass) {
			return null;
		}
		$meta = $record->meta ?? null;
		return new DashboardSpec(
			$spec,
			DashboardSpec::parseTime($meta->updated ?? null),
			DashboardSpec::parseTime($meta->created ?? null),
		);
	}

	/**
	 * Create-or-update a dashboard: `POST /api/dashboards/db` — Grafana's single upsert
	 * endpoint (the write half of the read surface {@see readDashboard()}). The body is
	 * built by {@see DashboardBody::toUpsertBody()}: it carries the spec with `id:null`
	 * and `overwrite:true` so Grafana keys on the stable **uid** and updates the existing
	 * dashboard in place (never mints a new one), plus the top-level `folderUid` that
	 * places it. Returns Grafana's decoded ack `{uid, id, version, status, slug, url}` —
	 * the `version` is the freshly-bumped one the push stamps into metadata.
	 *
	 * A bad spec (e.g. a uid that collides with a different title, a malformed panel)
	 * comes back as a {@see GrafanaApiException} carrying Grafana's own `message`, so the
	 * push surfaces exactly what to fix.
	 *
	 * @param array<string,mixed> $body the `/api/dashboards/db` envelope from DashboardBody::toUpsertBody
	 * @return array<string,mixed>
	 */
	public function upsertDashboard(array $body): array {
		$res = $this->request('POST', '/api/dashboards/db', [], $body);
		return $this->decode($res);
	}

	/**
	 * Permanently delete a dashboard by uid: `DELETE /api/dashboards/uid/{uid}`.
	 *
	 * Grafana has NO soft-delete/trash reachable to a service account — this is
	 * **irreversible** (reversibility lives on the Nextcloud side: the file's JSON + the
	 * NC trashbin). A 404 is treated as success: the dashboard is already gone, which is
	 * the desired end state, so a re-run or a delete-of-an-already-deleted uid is a no-op
	 * rather than an error. Any other failure bubbles up as a {@see GrafanaApiException}
	 * so the caller can abort before assuming Grafana is in sync.
	 */
	public function deleteDashboard(string $uid): void {
		try {
			$this->request('DELETE', '/api/dashboards/uid/' . rawurlencode($uid));
		} catch (GrafanaApiException $e) {
			if ($e->httpStatus === 404) {
				return; // already gone — the end state we wanted
			}
			throw $e;
		}
	}

	/**
	 * Build the browser deep-link to a live dashboard: `<base>/d/<uid>/<slug>`.
	 * The uid is the stable thread, so the slug is cosmetic — Grafana redirects to
	 * the canonical slug regardless. When the search/meta `url` is already known
	 * (it starts with `/d/…`), prefer {@see deepLinkFromPath()} to avoid guessing.
	 */
	public function deepLink(string $uid, string $slug = ''): string {
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'grafana_url', ''), '/');
		$path = '/d/' . rawurlencode($uid);
		if ($slug !== '') {
			$path .= '/' . rawurlencode($slug);
		}
		return $base . $path;
	}

	/**
	 * Absolute deep-link from a Grafana-supplied relative path (the `url` field on
	 * a search row or `meta.url`), e.g. `/d/abc123/my-dash` → `<base>/d/abc123/my-dash`.
	 * A path that isn't relative is returned unchanged (already absolute / empty).
	 */
	public function deepLinkFromPath(string $path): string {
		if ($path === '' || !str_starts_with($path, '/')) {
			return $path;
		}
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'grafana_url', ''), '/');
		return $base . $path;
	}

	/**
	 * Turn any failure from {@see ping()} into one friendly, user-facing line —
	 * shared by the Test connection button ({@see \OCA\GrafanaSync\Controller\ConfigController})
	 * and the `grafana_sync:test-connection` occ command, so both surfaces say the
	 * exact same thing. Crucially it tells the two failure classes apart:
	 *
	 *  - **Not set / misconfigured** — our own pre-formatted guards (missing URL or
	 *    token, decrypt failure, local-address refused) are plain `\RuntimeException`s;
	 *    their message is already user-ready, so pass it through. This is the
	 *    "you haven't finished setup" case.
	 *  - **Rejected / unreachable** — a real HTTP failure arrives as a
	 *    {@see GrafanaApiException} (a `\RuntimeException` *subclass*) carrying the
	 *    status code. 401/403 means the token was *set but rejected* — a different
	 *    problem from "not set", and the whole reason this method exists.
	 *
	 * The subclass check is load-bearing: catching `\RuntimeException` first (as the
	 * older code did) swallowed the 401 into the generic branch and showed Grafana's
	 * raw text instead of a clear "token rejected" — so an unset vs. a bad token
	 * looked identical.
	 */
	public static function describeConnectionError(\Throwable $e): string {
		if (!($e instanceof GrafanaApiException)) {
			// Our own pre-formatted guards (missing URL/token, decrypt failure,
			// local-address refused) are plain RuntimeExceptions — their message is
			// already user-ready. Anything else with no HTTP context is unreachable.
			return $e instanceof \RuntimeException
				? $e->getMessage()
				: 'Could not reach Grafana: ' . $e->getMessage();
		}
		// NB: GrafanaApiException stashes the status in `httpStatus`, not the
		// Exception code (which is always 0) — so read the property, not getCode().
		$code = $e->httpStatus;
		if ($code === 401 || $code === 403) {
			return "Authentication failed (HTTP $code) — Grafana rejected the token. Check it is valid, not expired, and has access.";
		}
		if ($code === 404) {
			return 'Reached the host but the Grafana API was not found — check the base URL.';
		}
		// httpStatus 0 is a genuine transport failure (no response). Any other code
		// means we DID reach Grafana and it returned an error (e.g. 500) — say so
		// with the code rather than the misleading "could not reach".
		if ($code === 0) {
			return 'Could not reach Grafana: ' . $e->getMessage();
		}
		return "Grafana returned HTTP $code: " . $e->getMessage();
	}

	/**
	 * Single chokepoint for every HTTP call. Reads + decrypts the token, applies
	 * the standard headers, and sets `allow_local_address` so the homelab's
	 * in-cluster URLs (e.g. grafana-service.observe.svc:3000) work the same way as
	 * public ones. This opts out of NC's default SSRF guard on purpose — see
	 * SECURITY.md "Network egress and local addresses" for the trade-off
	 * (admin-trust boundary, single Grafana target).
	 *
	 * Throws \RuntimeException with a friendly message for the cases we know how
	 * to label (no URL, no token, decrypt fail, local-address blocked) and lets
	 * transport errors bubble up otherwise so the caller can format them.
	 *
	 * @param array<string,mixed> $query
	 * @param array<string,mixed>|list<array<string,mixed>>|null $jsonBody
	 */
	private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): IResponse {
		$base = rtrim($this->config->getValueString(Application::APP_ID, 'grafana_url', ''), '/');
		$enc = $this->config->getValueString(Application::APP_ID, 'grafana_token', '');
		if ($base === '') {
			throw new \RuntimeException('Set the Grafana base URL first.');
		}
		if ($enc === '') {
			throw new \RuntimeException('No Grafana service-account token is set — add one first.');
		}
		try {
			$token = $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			throw new \RuntimeException('Stored token could not be decrypted — re-enter it.');
		}

		$url = $base . $path;
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		$opts = [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			],
			'timeout' => 10,
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($jsonBody !== null) {
			$opts['headers']['Content-Type'] = 'application/json';
			$opts['body'] = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}

		$client = $this->clientService->newClient();
		try {
			return $this->dispatch($client, $method, $url, $opts);
		} catch (LocalServerException $e) {
			throw new \RuntimeException('Refused to connect to a local address.', 0, $e);
		} catch (\Throwable $e) {
			throw $this->toApiException($e);
		}
	}

	/**
	 * Turn a transport/HTTP exception into a {@see GrafanaApiException} carrying
	 * Grafana's own error text. Grafana responds to bad requests with
	 * `{"message": "…"}` (sometimes with `messageId`/`traceID`); we surface that
	 * verbatim so it can be shown to the user. We duck-type the Guzzle exception
	 * (`getResponse()` → PSR-7 response) rather than import it, so we don't couple
	 * to a specific HTTP-client bundle.
	 */
	private function toApiException(\Throwable $e): GrafanaApiException {
		if (is_callable([$e, 'getResponse'])) {
			$res = $e->getResponse();
			if ($res instanceof \Psr\Http\Message\ResponseInterface) {
				$status = $res->getStatusCode();
				$raw = (string)$res->getBody();
				$msg = '';
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
				}
				if ($msg === '') {
					$msg = $raw !== '' ? mb_substr($raw, 0, 500) : ('HTTP ' . $status);
				}
				return new GrafanaApiException($msg, $status, $e);
			}
		}
		return new GrafanaApiException($e->getMessage(), 0, $e);
	}

	private function dispatch(IClient $client, string $method, string $url, array $opts): IResponse {
		switch (strtoupper($method)) {
			case 'GET':    return $client->get($url, $opts);
			case 'PUT':    return $client->put($url, $opts);
			case 'POST':   return $client->post($url, $opts);
			case 'DELETE': return $client->delete($url, $opts);
			default:
				throw new \LogicException('Unsupported HTTP method: ' . $method);
		}
	}

	/** @return array<string,mixed> */
	private function decode(IResponse $res): array {
		$body = (string)$res->getBody();
		if ($body === '') {
			return [];
		}
		try {
			$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('Grafana response was not valid JSON', ['exception' => $e]);
			throw new \RuntimeException('Grafana response was not valid JSON.', 0, $e);
		}
		if (!is_array($data)) {
			throw new \RuntimeException('Grafana response was not a JSON object/array.');
		}
		return $data;
	}
}
