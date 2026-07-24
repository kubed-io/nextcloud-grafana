<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\DAV;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\GrafanaSync\DAV\LinkWriteGuardPlugin;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;

/**
 * Unit tests for {@see LinkWriteGuardPlugin} — refuses a WebDAV write to a link-mode
 * dashboard file (a pointer with no local JSON). The invariant that matters most:
 * it fails OPEN on any doubt, so it only ever blocks a file it positively knows is a link.
 */
#[CoversClass(LinkWriteGuardPlugin::class)]
final class LinkWriteGuardPluginTest extends TestCase {
	private DashboardMetadata $metadata;
	private SyncNotifier $notifier;
	private LinkWriteGuardPlugin $plugin;

	protected function setUp(): void {
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->notifier = $this->createMock(SyncNotifier::class);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->plugin = new LinkWriteGuardPlugin($this->metadata, $this->notifier, $session, new NullLogger());
	}

	private function davFile(string $name, int $id = 7): DavFile {
		$node = $this->createStub(DavFile::class);
		$node->method('getName')->willReturn($name);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	private function managed(string $mode): ManagedFile {
		return new ManagedFile('dash-1', $mode, '1', 'hash', 'm-1', '', '');
	}

	/** Invoke the Sabre hook with throwaway by-ref args. */
	private function invoke(INode $node): bool {
		$data = null;
		$modified = false;
		return $this->plugin->beforeWriteContent('files/alice/x', $node, $data, $modified);
	}

	public function testANonDavFileNodeIsAllowed(): void {
		$this->notifier->expects(self::never())->method('linkEditBlocked');
		self::assertTrue($this->invoke($this->createStub(INode::class)));
	}

	public function testANonDashboardFileIsAllowed(): void {
		$this->notifier->expects(self::never())->method('linkEditBlocked');
		self::assertTrue($this->invoke($this->davFile('notes.txt')));
	}

	public function testAnUnreadableFileFailsOpen(): void {
		// Anything we can't classify must NOT be blocked — a metadata read that throws
		// leaves the write allowed rather than bouncing a file that might not be a link.
		$this->metadata->method('read')->willThrowException(new \RuntimeException('db down'));
		$this->notifier->expects(self::never())->method('linkEditBlocked');
		self::assertTrue($this->invoke($this->davFile('Board.grafana.json')));
	}

	public function testASyncDashboardFileIsAllowed(): void {
		// Sync + unmapped files hold the full JSON and are freely editable.
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->notifier->expects(self::never())->method('linkEditBlocked');
		self::assertTrue($this->invoke($this->davFile('Board.grafana.json')));
	}

	public function testALinkDashboardFileIsRefusedAndNotifies(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_LINK));
		$this->notifier->expects(self::once())->method('linkEditBlocked')->with('alice', 7, 'Board.grafana.json');

		$this->expectException(Forbidden::class);
		$this->invoke($this->davFile('Board.grafana.json'));
	}
}
