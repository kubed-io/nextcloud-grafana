<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\TagSet;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * {@see GrafanaClient::describeConnectionError} — the one formatter behind both the
 * Test connection button and the occ command. Its whole job is to keep the two
 * failure classes distinct: a token that isn't set vs. one that was set and
 * rejected. The 401 case doubly guards a regression: GrafanaApiException is a
 * RuntimeException subclass, so a naive `catch (RuntimeException)` would show
 * Grafana's raw text instead of a clear "rejected".
 */
final class GrafanaClientTest extends TestCase {
	public function testDescribesAMissingTokenAsSetupNotRejection(): void {
		$msg = GrafanaClient::describeConnectionError(
			new \RuntimeException('No Grafana service-account token is set — add one first.'),
		);
		self::assertStringContainsString('add one first', $msg);
		self::assertStringNotContainsStringIgnoringCase('rejected', $msg);
	}

	public function testDescribesA401AsARejectedToken(): void {
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('Invalid API key', 401));
		self::assertStringContainsString('401', $msg);
		self::assertStringContainsStringIgnoringCase('rejected', $msg);
		// The raw upstream text must NOT be what the user sees for an auth failure.
		self::assertStringNotContainsString('Invalid API key', $msg);
	}

	public function testDescribesA403AsARejectedToken(): void {
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('Forbidden', 403));
		self::assertStringContainsStringIgnoringCase('rejected', $msg);
	}

	public function testDescribesA404AsABaseUrlProblem(): void {
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('not found', 404));
		self::assertStringContainsStringIgnoringCase('base url', $msg);
	}

	public function testDescribesATransportErrorAsUnreachable(): void {
		// httpStatus 0 = no response at all — genuinely "could not reach".
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('connection refused', 0));
		self::assertStringContainsStringIgnoringCase('could not reach', $msg);
	}

	public function testDescribesA500AsAReachedHttpErrorNotUnreachable(): void {
		// Grafana WAS reached and returned 500 — must not claim "could not reach".
		$msg = GrafanaClient::describeConnectionError(new GrafanaApiException('internal error', 500));
		self::assertStringContainsString('500', $msg);
		self::assertStringNotContainsStringIgnoringCase('could not reach', $msg);
	}

	// ── the folder write surface ───────────────────────────────────────────────
	//
	// These assert the REQUEST the client builds, because that is the half this app
	// owns; the response half is Grafana's. Each expectation below was measured
	// against a live Grafana 13.0.2 rather than read off the docs — the rename
	// precondition in particular is not something the docs lead with.

	public function testCreateFolderPostsATitleAndOmitsTheParentAtRoot(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{"uid":"abc","title":"Team","version":1}'), $calls);

		$client->createFolder('Team');

		[$method, $url, $opts] = $calls[0];
		self::assertSame('POST', $method);
		self::assertSame('https://grafana.test/api/folders', $url);
		// A root-level create must not send parentUid at all — sending an empty one is
		// a different request, and Grafana is entitled to treat it as such.
		self::assertSame(['title' => 'Team'], json_decode($opts['body'], true));
	}

	public function testCreateFolderNestsUnderAParent(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{"uid":"kid","title":"Drafts","parentUid":"top","version":1}'), $calls);

		$client->createFolder('Drafts', 'top');

		self::assertSame(
			['title' => 'Drafts', 'parentUid' => 'top'],
			json_decode($calls[0][2]['body'], true),
		);
	}

	public function testCreateFolderReturnsOnlyTheBankedShape(): void {
		$calls = new \ArrayObject();
		// Grafana answers with a fat record; the client must hand back the four keys
		// the app persists and drop the rest rather than leaking the raw body.
		$client = $this->client($this->response(
			'{"uid":"kid","title":"Drafts","parentUid":"top","version":3,"canAdmin":true,"parents":[{"uid":"top"}]}',
		), $calls);

		self::assertSame(
			['uid' => 'kid', 'title' => 'Drafts', 'parentUid' => 'top', 'version' => 3],
			$client->createFolder('Drafts', 'top'),
		);
	}

	public function testAFolderWriteWithoutAUidFailsRatherThanBankingAnEmptyOne(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{"title":"Drafts","version":1}'), $calls);

		// An empty uid does not read as "unknown" downstream — it reads as `absent`,
		// the spec's word for "not a Grafana folder" — so a malformed 2xx must never
		// reach the caller as a valid shape.
		$this->expectException(\RuntimeException::class);
		$client->createFolder('Drafts');
	}

	public function testRenameFolderSendsOverwriteSoGrafanaDoesNotRefuseIt(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{"uid":"abc","title":"Team B","version":2}'), $calls);

		$client->renameFolder('abc', 'Team B');

		[$method, $url, $opts] = $calls[0];
		self::assertSame('PUT', $method);
		self::assertSame('https://grafana.test/api/folders/abc', $url);
		// THE REGRESSION GUARD. A bare title PUT comes back 412 "The dashboard has
		// been changed by someone else" — measured on 13.0.2. Dropping `overwrite`
		// breaks every rename, and only against a real Grafana, so pin it here.
		self::assertSame(['title' => 'Team B', 'overwrite' => true], json_decode($opts['body'], true));
	}

	public function testMoveFolderPostsToTheMoveEndpoint(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{"uid":"abc","title":"Team","parentUid":"dest","version":4}'), $calls);

		$client->moveFolder('abc', 'dest');

		[$method, $url, $opts] = $calls[0];
		self::assertSame('POST', $method);
		self::assertSame('https://grafana.test/api/folders/abc/move', $url);
		// No version here: move is a dedicated endpoint with no precondition, unlike
		// the rename above.
		self::assertSame(['parentUid' => 'dest'], json_decode($opts['body'], true));
	}

	public function testMoveFolderToTheRootSendsAnEmptyParent(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{"uid":"abc","title":"Team","version":5}'), $calls);

		$client->moveFolder('abc');

		self::assertSame(['parentUid' => ''], json_decode($calls[0][2]['body'], true));
	}

	public function testFolderUidsAreUrlEncodedIntoThePath(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{}'), $calls);

		$client->deleteFolder('a/b c');

		self::assertSame('https://grafana.test/api/folders/a%2Fb%20c', $calls[0][1]);
	}

	public function testDeleteFolderTreatsAMissingFolderAsSuccess(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->httpError(404), $calls);

		// Already gone IS the end state wanted, so a re-run must not explode.
		$client->deleteFolder('abc');

		self::assertCount(1, $calls);
	}

	public function testDeleteFolderRethrowsAnythingOtherThan404(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->httpError(403, '{"message":"forbidden"}'), $calls);

		// A refusal must NOT read as "already deleted" — the caller has to be able to
		// abort before assuming Grafana is in sync.
		$this->expectException(GrafanaApiException::class);
		$client->deleteFolder('abc');
	}

	// ── folder tags ────────────────────────────────────────────────────────────

	/**
	 * A 404 MUST NOT READ AS "NO TAGS". The caller writes what it gets back into
	 * Nextcloud, and an empty set there means REMOVE EVERY TAG — so a stale uid or a
	 * folder deleted in Grafana would silently wipe tags a user had applied. The
	 * failure has to travel so the caller can log and leave the folder alone.
	 */
	public function testReadFolderTagsThrowsOnAMissingFolderRatherThanAnsweringEmpty(): void {
		$client = $this->client($this->httpError(404), new \ArrayObject());

		$this->expectException(GrafanaApiException::class);
		$client->readFolderTags('gone');
	}

	/** A folder that EXISTS with no annotation is the real empty case, and is a 200. */
	public function testAFolderWithNoAnnotationHasNoTags(): void {
		$client = $this->client($this->response('{"metadata":{"annotations":{}},"spec":{"title":"Team"}}'), new \ArrayObject());

		self::assertTrue($client->readFolderTags('gf-team')->isEmpty());
	}

	public function testReadFolderTagsParsesTheAnnotation(): void {
		$body = '{"metadata":{"annotations":{"nextcloud.kubed.io/tags":"Q3 Review, café"}}}';
		$client = $this->client($this->response($body), new \ArrayObject());

		self::assertTrue(
			$client->readFolderTags('gf-team')->equals(TagSet::of(['café', 'Q3 Review'])),
		);
	}

	/**
	 * A MERGE PATCH, and clearing sends null. The content type is the whole reason
	 * this call works: the app-platform API rejects a plain application/json body,
	 * and a PUT would carry the entire metadata map including `grafana.app/*`.
	 */
	public function testWritingFolderTagsIsAMergePatchThatDeletesWhenEmpty(): void {
		$calls = new \ArrayObject();
		$client = $this->client($this->response('{}'), $calls);

		$client->writeFolderTags('gf-team', TagSet::empty());

		self::assertSame('PATCH', $calls[0][0]);
		self::assertStringContainsString('/apis/folder.grafana.app/v1beta1/', $calls[0][1]);
		self::assertSame('application/merge-patch+json', $calls[0][2]['headers']['Content-Type']);
		// JSON_UNESCAPED_SLASHES, so the key keeps its literal slash.
		self::assertStringContainsString('"nextcloud.kubed.io/tags":null', $calls[0][2]['body']);
	}

	// ── harness ────────────────────────────────────────────────────────────────

	/**
	 * A client whose transport is mocked. Every dispatched request is appended to
	 * `$calls` as `[METHOD, url, options]`.
	 *
	 * @param IResponse|\Throwable $result what the mocked verb returns, or throws
	 */
	private function client(object $result, \ArrayObject $calls): GrafanaClient {
		$config = $this->createStub(IAppConfig::class);
		// Two parameters against an interface that declares five: PHP passes extra
		// positional arguments to a userland function without complaint (only too FEW
		// is an ArgumentCountError), so the callback takes the two it reads and lets
		// `$default`/`$lazy`/`$sensitive` fall on the floor.
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $key === 'grafana_url'
				? 'https://grafana.test'
				: 'encrypted-token',
		);

		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('decrypt')->willReturn('plain-token');

		$http = $this->createStub(IClient::class);
		foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
			$http->method($verb)->willReturnCallback(
				static function (string $uri, array $options = []) use ($verb, $calls, $result): IResponse {
					$calls[] = [strtoupper($verb), $uri, $options];
					if ($result instanceof \Throwable) {
						throw $result;
					}
					/** @var IResponse $result */
					return $result;
				},
			);
		}

		$service = $this->createStub(IClientService::class);
		$service->method('newClient')->willReturn($http);

		return new GrafanaClient($config, $crypto, $service, $this->createStub(LoggerInterface::class));
	}

	private function response(string $json): IResponse {
		$res = $this->createStub(IResponse::class);
		$res->method('getBody')->willReturn($json);
		$res->method('getStatusCode')->willReturn(200);
		return $res;
	}

	/**
	 * A transport failure shaped like the Guzzle exception the client duck-types:
	 * a `getResponse()` returning a PSR-7 response, which is how it recovers the
	 * status code that tells 404 from everything else.
	 */
	private function httpError(int $status, string $body = '{"message":"nope"}'): \Throwable {
		$stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
		$stream->method('__toString')->willReturn($body);

		$psr = $this->createStub(\Psr\Http\Message\ResponseInterface::class);
		$psr->method('getStatusCode')->willReturn($status);
		$psr->method('getBody')->willReturn($stream);

		return new class($psr) extends \RuntimeException {
			public function __construct(
				private \Psr\Http\Message\ResponseInterface $res,
			) {
				parent::__construct('transport failure');
			}

			public function getResponse(): \Psr\Http\Message\ResponseInterface {
				return $this->res;
			}
		};
	}
}
