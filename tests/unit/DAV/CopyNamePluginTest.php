<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\DAV;

use OCA\GrafanaSync\DAV\CopyNamePlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Unit tests for {@see CopyNamePlugin} — the header rewrite that decides what a copied
 * dashboard file is CALLED, before it exists.
 *
 * The whole class is one decision made on one string, so these are cheap and they cover
 * the cases that matter: the rewrite itself at more than one counter, the several ways a
 * destination is none of our business, and the two failure modes that must leave the copy
 * exactly as the client asked for it.
 */
#[CoversClass(CopyNamePlugin::class)]
final class CopyNamePluginTest extends TestCase {
	private const BASE = 'http://cloud.example/remote.php/dav/files/kelly';

	/**
	 * @param list<string> $existing paths (as Sabre sees them) that are already taken
	 */
	private function plugin(array $existing = []): CopyNamePlugin {
		$tree = $this->createStub(Tree::class);
		$tree->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => in_array(ltrim($path, '/'), $existing, true),
		);

		$server = $this->createStub(Server::class);
		$server->tree = $tree;
		// Sabre's own behaviour: an absolute destination is reduced to its path, and the
		// DAV base is stripped. Reproduced rather than mocked away, because the plugin
		// hands it a raw header and the shape it gets back is the whole input.
		$server->method('calculateUri')->willReturnCallback(
			static fn (string $uri): string => trim(rawurldecode((string)parse_url($uri, PHP_URL_PATH)), '/'),
		);

		$plugin = new CopyNamePlugin(new NullLogger());
		$plugin->initialize($server);
		return $plugin;
	}

	/**
	 * A request carrying one header and remembering what was written back to it — which
	 * is the entire interface between this plugin and Sabre.
	 */
	private function request(?string $destination): RequestInterface {
		return new class($destination) implements RequestInterface {
			/** @var array<string,string> */
			public array $headers = [];

			public function __construct(?string $destination) {
				if ($destination !== null) {
					$this->headers['Destination'] = $destination;
				}
			}

			public function getHeader(string $name): ?string {
				return $this->headers[$name] ?? null;
			}

			public function setHeader(string $name, string $value): void {
				$this->headers[$name] = $value;
			}
		};
	}

	private function response(): ResponseInterface {
		return new class implements ResponseInterface {
		};
	}

	/** Run the hook and report the `Destination` the request is left holding. */
	private function copyTo(CopyNamePlugin $plugin, string $destination): string {
		$request = $this->request($destination);
		$plugin->beforeCopy($request, $this->response());
		return (string)$request->getHeader('Destination');
	}

	/**
	 * THE CASE THE WHOLE CLASS EXISTS FOR, at three different counters. One counter
	 * proves nothing: a rewrite that only ever handles `(1)` is indistinguishable from
	 * one that appends a fixed string, and the interesting failures live at `(2)` and
	 * beyond — which is exactly where a user lands on their third copy.
	 */
	#[DataProvider('nextcloudsSpellings')]
	public function testTheDestinationIsRewrittenIntoOurSpelling(string $theirs, string $ours): void {
		$rewritten = $this->copyTo($this->plugin(), self::BASE . '/Demo/' . rawurlencode($theirs));

		self::assertSame(self::BASE . '/Demo/' . rawurlencode($ours), $rewritten);
	}

	/** @return iterable<string, array{string, string}> */
	public static function nextcloudsSpellings(): iterable {
		yield 'first copy' => ['Fleet Health.grafana (1).json', 'Fleet Health (1).grafana.json'];
		yield 'second copy' => ['Fleet Health.grafana (2).json', 'Fleet Health (2).grafana.json'];
		yield 'double digits' => ['Fleet Health.grafana (17).json', 'Fleet Health (17).grafana.json'];
		yield 'uid-suffixed shape' => [
			'Board.af397c9y8enswf.grafana (1).json',
			'Board (1).af397c9y8enswf.grafana.json',
		];
	}

	/**
	 * A copy that did not collide is already called the right thing. The plugin must be
	 * completely absent from that path — it is the overwhelmingly common one.
	 */
	public function testAnUncollidedCopyIsLeftAlone(): void {
		$destination = self::BASE . '/Demo/' . rawurlencode('Fleet Health.grafana.json');

		self::assertSame($destination, $this->copyTo($this->plugin(), $destination));
	}

	/** Not one of ours. Nextcloud's spelling of somebody else's file is their business. */
	public function testAnotherAppsCollidingCopyIsLeftAlone(): void {
		$destination = self::BASE . '/Demo/' . rawurlencode('Budget.xlsx (1).json');

		self::assertSame($destination, $this->copyTo($this->plugin(), $destination));
	}

	/**
	 * THE NAME WE WANT CAN ITSELF BE TAKEN — by an earlier copy that already claimed it.
	 * The client proved ITS name was free; ours is a different name and gets no such
	 * guarantee. Rewriting onto an occupied path would turn a copy that was going to
	 * work into a 412 the user never asked for, so the client's name wins.
	 */
	public function testAnOccupiedTargetLeavesTheClientsNameAlone(): void {
		$plugin = $this->plugin(['Demo/Fleet Health (1).grafana.json']);
		$destination = self::BASE . '/Demo/' . rawurlencode('Fleet Health.grafana (1).json');

		self::assertSame($destination, $this->copyTo($plugin, $destination));
	}

	/**
	 * A COPY IS NEVER WORTH BREAKING OVER A NAME. Whatever goes wrong in here — an
	 * unparseable destination, a tree that throws — the request has to continue exactly
	 * as the client sent it, and the app already knows how to read and repair the
	 * resulting file.
	 */
	public function testAThrowingTreeLeavesTheRequestUntouched(): void {
		$tree = $this->createStub(Tree::class);
		$tree->method('nodeExists')->willThrowException(new \RuntimeException('storage is down'));
		$server = $this->createStub(Server::class);
		$server->tree = $tree;
		$server->method('calculateUri')->willReturnCallback(
			static fn (string $uri): string => trim(rawurldecode((string)parse_url($uri, PHP_URL_PATH)), '/'),
		);
		$plugin = new CopyNamePlugin(new NullLogger());
		$plugin->initialize($server);

		$destination = self::BASE . '/Demo/' . rawurlencode('Fleet Health.grafana (1).json');
		self::assertSame($destination, $this->copyTo($plugin, $destination));
	}

	/** A COPY with no destination at all is Sabre's problem to reject, not ours. */
	public function testAMissingDestinationIsNotOurProblem(): void {
		$request = $this->request(null);

		self::assertTrue($this->plugin()->beforeCopy($request, $this->response()));
		self::assertNull($request->getHeader('Destination'));
	}
}
