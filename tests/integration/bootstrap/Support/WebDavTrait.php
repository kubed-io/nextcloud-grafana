<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Support;

use GuzzleHttp\Client;
use PHPUnit\Framework\Assert;

/**
 * WebDAV transport (Guzzle, basic-auth as the admin user): write/read/PROPFIND
 * files the way the desktop client or web UI would — this is what fires the
 * server-side events the create/delete/rename/move listeners hang off. Also
 * covers the trashbin DAV surface and the nc:metadata-* property reads.
 *
 * Composed into {@see \OCA\GrafanaSync\Tests\Integration\FeatureContext}; reads the
 * shared `$dav` client + `$ncBaseUrl` / `$ncUser` / `$ncPass` / `$createdFolders`.
 */
trait WebDavTrait {
	private function davClient(): Client {
		if ($this->dav === null) {
			$this->dav = new Client([
				'base_uri' => $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/',
				'auth' => [$this->ncUser, $this->ncPass],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->dav;
	}

	/**
	 * Assert an HTTP response status is in $allowed, throwing a plain, legible
	 * exception otherwise. Deliberately NOT a PHPUnit assertion: PHPUnit 12's
	 * failure exporter reaches into PHPUnit\TextUI\Configuration\Registry, which
	 * is null under Behat (no TextUI bootstrap), so a failing PHPUnit assertion
	 * here throws an opaque "Registry::get(): ... null returned" TypeError that
	 * masks the real status. A RuntimeException shows the actual code + body.
	 *
	 * @param list<int> $allowed
	 */
	private function assertStatus(\Psr\Http\Message\ResponseInterface $res, array $allowed, string $what): void {
		$code = $res->getStatusCode();
		if (!in_array($code, $allowed, true)) {
			throw new \RuntimeException("$what failed: HTTP $code (expected " . implode('/', $allowed) . ")\n" . (string)$res->getBody());
		}
	}

	/** Create a top-level folder in the admin's files root (idempotent). */
	/**
	 * Make a folder, and every ancestor it needs.
	 *
	 * ## TWO BUGS THAT WERE LATENT UNTIL A NESTED PATH ARRIVED
	 *
	 * It used to `rawurlencode()` the WHOLE path, so `Demo/Team` became `Demo%2FTeam`
	 * — one top-level folder whose name contains a slash, not a subfolder. Every
	 * caller happened to pass a single segment, so nothing noticed until a scenario
	 * needed a subfolder and its `davPut` (which encodes per segment, correctly) then
	 * targeted a parent that did not exist.
	 *
	 * And MKCOL does not create intermediate collections — that is WebDAV, not a
	 * limitation here — so a nested path needs each level made in turn.
	 *
	 * ## TEARDOWN TRACKS THE TOP LEVEL ONLY
	 *
	 * Deleting `Demo` takes `Demo/Team` with it, and the teardown's own DELETE has the
	 * same whole-path encoding, so handing it a nested path would leave the tree
	 * behind for the next scenario to trip over.
	 */
	private function davMkdir(string $folder): void {
		$segments = array_values(array_filter(explode('/', trim($folder, '/')), static fn (string $s): bool => $s !== ''));
		$sofar = '';
		foreach ($segments as $segment) {
			$sofar = $sofar === '' ? $segment : $sofar . '/' . $segment;
			// 201 created, 405 already exists — both are fine for our purposes.
			$this->assertStatus(
				$this->davClient()->request('MKCOL', $this->davEncode($sofar)),
				[201, 405],
				"MKCOL $sofar",
			);
		}

		$root = $segments[0] ?? '';
		if ($root !== '' && !in_array($root, $this->createdFolders, true)) {
			$this->createdFolders[] = $root;
		}
	}

	/** PUT file content at a path under the user's files root. */
	private function davPut(string $path, string $body): void {
		$this->assertStatus($this->davClient()->request('PUT', $this->davEncode($path), ['body' => $body]), [201, 204], "PUT $path");
	}

	/** PUT a file, returning the raw status (so create-refused scenarios can inspect it). */
	private function davPutStatus(string $path, string $body): int {
		return $this->davClient()->request('PUT', $this->davEncode($path), ['body' => $body])->getStatusCode();
	}

	/** GET a file's content. */
	private function davGet(string $path): string {
		$res = $this->davClient()->request('GET', $this->davEncode($path));
		$this->assertStatus($res, [200], "GET $path");
		return (string)$res->getBody();
	}

	/** True if a file exists (HEAD 200). */
	private function davExists(string $path): bool {
		return $this->davClient()->request('HEAD', $this->davEncode($path))->getStatusCode() === 200;
	}

	/** MOVE (rename) a file within the user's files root. */
	private function davMove(string $from, string $to): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		$res = $this->davClient()->request('MOVE', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->assertStatus($res, [201, 204], "MOVE $from → $to");
	}

	/** MOVE a file, returning the raw status (so move-refused scenarios can inspect it). */
	private function davMoveStatus(string $from, string $to): int {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		return $this->davClient()->request('MOVE', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		])->getStatusCode();
	}

	/** COPY a file within the user's files root (fires NodeCopiedEvent in NC). */
	private function davCopy(string $from, string $to): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		$res = $this->davClient()->request('COPY', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->assertStatus($res, [201, 204], "COPY $from → $to");
	}

	/** COPY a file, returning the raw status (so copy-refused scenarios can inspect it). */
	private function davCopyStatus(string $from, string $to): int {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		return $this->davClient()->request('COPY', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		])->getStatusCode();
	}

	/** DELETE a file (asserting success → trash). */
	private function davDelete(string $path): void {
		$this->assertStatus($this->davClient()->request('DELETE', $this->davEncode($path)), [204, 200], "DELETE $path");
	}

	/** DELETE a file, returning the raw status (so abort scenarios can inspect it). */
	private function davDeleteStatus(string $path): int {
		return $this->davClient()->request('DELETE', $this->davEncode($path))->getStatusCode();
	}

	/**
	 * Find the trashbin entry for a file we deleted, by basename. NC trashbin DAV
	 * lives at /remote.php/dav/trashbin/<user>/trash and renames entries with a
	 * `.dNNNN` deletion-time suffix, so we match on the original basename prefix.
	 * Returns the trashbin entry filename (e.g. "Old Name.grafana.d171...") or null.
	 */
	private function trashbinPathFor(string $originalPath, int $notBefore = 0): ?string {
		$base = basename($originalPath);
		$href = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash';
		$res = $this->davClient()->request('PROPFIND', $href, [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
				. '<d:prop><nc:trashbin-filename/></d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), 'trashbin PROPFIND failed: ' . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
		// THE NEWEST MATCH, NOT THE FIRST. Every scenario names its dashboard the same
		// thing and nothing empties the trash between them — emptying it is itself a
		// gesture that finishes deletes in Grafana, so teardown must not — which left
		// this returning a STALE entry from an earlier scenario. The purge steps then
		// destroyed that one and reported the current file still in the trash.
		//
		// The trash spells its entries `<name>.d<unix timestamp>`, so the largest
		// suffix is the most recent deletion, which is always this scenario's.
		$best = null;
		$bestStamp = -1;
		foreach ($doc->xpath('//d:response') ?: [] as $resp) {
			$resp->registerXPathNamespace('d', 'DAV:');
			$resp->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
			$origName = trim((string)($resp->xpath('.//nc:trashbin-filename')[0] ?? ''));
			$rawHref = rawurldecode(trim((string)($resp->xpath('d:href')[0] ?? '')));
			if ($origName !== $base || $rawHref === '') {
				continue;
			}
			$entry = basename(rtrim($rawHref, '/'));
			$stamp = preg_match('/\.d(\d+)$/', $entry, $m) === 1 ? (int)$m[1] : 0;
			// $notBefore lets a caller ask "was this trashed DURING my scenario?" — the
			// only way to tell one scenario's entry from an earlier one's when the names
			// are identical, which since the naming sweep they always are.
			if ($stamp < $notBefore) {
				continue;
			}
			if ($stamp >= $bestStamp) {
				$bestStamp = $stamp;
				$best = $entry;
			}
		}
		return $best;
	}

	/** Is this exact trash entry still there? Named, not searched — see its callers. */
	private function trashEntryExists(string $entry): bool {
		$href = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash';
		$res = $this->davClient()->request('PROPFIND', $href, [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
		]);
		if ($res->getStatusCode() !== 207) {
			return false; // no trash to be in
		}
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		foreach ($doc->xpath('//d:href') ?: [] as $href) {
			if (basename(rtrim(rawurldecode(trim((string)$href)), '/')) === $entry) {
				return true;
			}
		}
		return false;
	}

	/** Full trashbin href for a trash entry filename. */
	private function trashHref(string $entry): string {
		return $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash/' . rawurlencode($entry);
	}

	/**
	 * PROPFIND a single nc:metadata-<key> on a file. Returns the property value,
	 * or null if the property is absent (404 inside the multistatus). This is the
	 * exact DAV surface view-dashboard.feature specifies.
	 */
	private function davReadMetadata(string $path, string $key): ?string {
		$ns = 'http://nextcloud.org/ns';
		$reqBody = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:nc="' . $ns . '">'
			. '<d:prop><nc:metadata-' . $key . '/></d:prop></d:propfind>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => $reqBody,
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $path failed: " . (string)$res->getBody());
		$xml = (string)$res->getBody();
		$doc = new \SimpleXMLElement($xml);
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $ns);
		// Only consider the 200-OK propstat block; a missing prop lands in a 404 block.
		foreach ($doc->xpath('//d:propstat') ?: [] as $propstat) {
			$propstat->registerXPathNamespace('d', 'DAV:');
			$propstat->registerXPathNamespace('nc', $ns);
			$status = (string)($propstat->xpath('d:status')[0] ?? '');
			if (!str_contains($status, '200')) {
				continue;
			}
			$node = $propstat->xpath('d:prop/nc:metadata-' . $key);
			if ($node) {
				return trim((string)$node[0]);
			}
		}
		return null;
	}

	/**
	 * The mimetype a Files client is told, read off the same PROPFIND the Files app
	 * uses.
	 *
	 * NOT whatever core would fall back to. `.grafana` means nothing to Nextcloud on its
	 * own: `detectPath()` finds no mapping, returns `application/octet-stream`, and
	 * `detect()` then sniffs the bytes with finfo — so an unregistered instance answers
	 * some generic text/JSON type and the Files row gets a generic icon. Registering the
	 * extension is the only thing that produces `application/grafana+json`, which is why
	 * this is asserted at all.
	 *
	 * Asserted over DAV because that is where the Files app reads it from; the mapping
	 * file on disk being right proves nothing about what a client is told.
	 *
	 * And the registration is now the ONLY thing standing between those two answers,
	 * which is what makes this worth more than it used to be. Under the retired
	 * `.grafana.json` the path answered `application/json` — a real mapping, just the
	 * wrong one — so this could pass on a half-registered instance where a listener had
	 * merely re-stamped the filecache after the write.
	 */
	private function davContentType(string $path): string {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?>'
				. '<d:propfind xmlns:d="DAV:"><d:prop><d:getcontenttype/></d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], "PROPFIND content type $path");

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		// Only the 200-OK propstat block, as davReadMetadata() does — a collection
		// has no getcontenttype and answers in a 404 block, and reading across both
		// would report an empty string as though the property had been served.
		foreach ($doc->xpath('//d:propstat') ?: [] as $propstat) {
			$propstat->registerXPathNamespace('d', 'DAV:');
			if (!str_contains((string)($propstat->xpath('d:status')[0] ?? ''), '200')) {
				continue;
			}
			$node = $propstat->xpath('d:prop/d:getcontenttype');
			if ($node) {
				return trim((string)$node[0]);
			}
		}

		throw new \RuntimeException("PROPFIND $path returned no getcontenttype:\n" . (string)$res->getBody());
	}

	/**
	 * Convenience: read just the dashboard uid (used right after a create to capture it).
	 *
	 * This referenced a `self::META_ID` that FeatureContext never defined — a fatal
	 * the moment anything called it, latent only because nothing did. The constant is
	 * `META_UID`.
	 */
	private function davReadMetadataId(string $path): ?string {
		return $this->davReadMetadata($path, self::META_UID);
	}

	/**
	 * The file's DAV etag — the sharpest "was this written?" observable a client has.
	 * Nextcloud mints a fresh etag on **every** write, even one that stores identical
	 * bytes, so an unchanged etag proves no write happened. Preferred over
	 * `getlastmodified` for exactly that reason: mtime has one-second resolution, so
	 * two writes inside the same second are invisible to it.
	 */
	private function davReadEtag(string $path): string {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND etag $path failed: " . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$node = $doc->xpath('//d:prop/d:getetag');
		Assert::assertNotEmpty($node, "no etag returned for $path");
		return trim((string)$node[0], " \t\n\r\0\x0B\"");
	}

	/**
	 * A DAV timestamp property on a file, as a Unix second. `getlastmodified` is
	 * RFC-1123; `{nc:}creation_time` is a Unix second already. Returns null when the
	 * property is absent or unset (an unset creation time reads back as 0).
	 *
	 * Deliberately read over DAV rather than from the database: these two clocks only
	 * matter because a person sorting a folder in Files, or a desktop client deciding
	 * what to re-download, reads exactly these properties.
	 */
	private function davReadTime(string $path, string $property): ?int {
		$nc = 'http://nextcloud.org/ns';
		$prop = $property === 'creation_time' ? '<nc:creation_time/>' : '<d:' . $property . '/>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="' . $nc . '">'
				. '<d:prop>' . $prop . '</d:prop></d:propfind>',
		]);
		Assert::assertSame(207, $res->getStatusCode(), "PROPFIND $property $path failed: " . (string)$res->getBody());
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $nc);
		$node = $doc->xpath($property === 'creation_time' ? '//nc:creation_time' : '//d:' . $property);
		if (!$node) {
			return null;
		}
		$raw = trim((string)$node[0]);
		if ($raw === '' || $raw === '0') {
			return null;
		}
		$ts = ctype_digit($raw) ? (int)$raw : strtotime($raw);
		return $ts === false ? null : $ts;
	}

	/** Percent-encode each path segment but keep the slashes. */
	private function davEncode(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
	}
}
