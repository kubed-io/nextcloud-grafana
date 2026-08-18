<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\DAV;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\GrafanaSync\DAV\LinkWriteGuardPlugin;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
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
	private MappingService $mappings;
	private LinkWriteGuardPlugin $plugin;

	/** @var array<string,string> node path prefix → mapping mode */
	private array $mappedFolders = [];

	protected function setUp(): void {
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->notifier = $this->createMock(SyncNotifier::class);

		// Resolves a node path to a mapping using $mappedFolders, so a test says only
		// which folders are mapped and in what mode.
		$this->mappings = $this->createStub(MappingService::class);
		$this->mappings->method('resolveForPath')->willReturnCallback(
			function (string $path): ?Mapping {
				foreach ($this->mappedFolders as $folder => $mode) {
					if (str_contains($path, '/files/' . $folder . '/')) {
						return Mapping::fromArray([
							'id' => 'm-' . $folder,
							'grafana_folder_uid' => 'gf-' . $folder,
							'nc_folder' => $folder,
							'mode' => $mode,
						]);
					}
				}
				return null;
			},
		);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->plugin = new LinkWriteGuardPlugin($this->metadata, $this->mappings, $this->notifier, $session, new NullLogger());
	}

	private function davFile(string $name, int $id = 7): DavFile {
		$node = $this->createStub(DavFile::class);
		$node->method('getName')->willReturn($name);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	private function managed(string $mode): ManagedFile {
		return new ManagedFile('dash-1', $mode, '1', 'hash', 'm-1', '');
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
		self::assertTrue($this->invoke($this->davFile('Board.grafana')));
	}

	public function testASyncDashboardFileIsAllowed(): void {
		// Sync + unmapped files hold the full JSON and are freely editable.
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC));
		$this->notifier->expects(self::never())->method('linkEditBlocked');
		self::assertTrue($this->invoke($this->davFile('Board.grafana')));
	}

	public function testALinkDashboardFileIsRefusedAndNotifies(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_LINK));
		$this->notifier->expects(self::once())->method('linkEditBlocked')->with('alice', 7, 'Board.grafana');

		$this->expectException(Forbidden::class);
		$this->invoke($this->davFile('Board.grafana'));
	}

	// ── beforeCreateFile: authoring into a link folder ─────────────────────────

	/**
	 * THE GUARD'S REASON TO EXIST. A link folder is Grafana's to write, so a file
	 * created there could never become the dashboard it looks like — it would sit
	 * forever looking managed and being unmanaged.
	 */
	public function testANewDashboardFileInALinkFolderIsRefused(): void {
		$this->mappedFolders = ['Pointers' => Mapping::MODE_LINK];

		$this->expectException(Forbidden::class);
		$this->expectExceptionMessageMatches('/link mode/');
		$this->createIn('Pointers', 'CPU Load.grafana');
	}

	public function testANewDashboardFileInASyncFolderIsAllowed(): void {
		$this->mappedFolders = ['Demo' => Mapping::MODE_SYNC];

		self::assertTrue($this->createIn('Demo', 'CPU Load.grafana'));
	}

	public function testANewDashboardFileOutsideEveryMappingIsAllowed(): void {
		$this->mappedFolders = [];

		self::assertTrue($this->createIn('Scratch', 'CPU Load.grafana'));
	}

	/**
	 * A link mapping's ONE concession: other file types may live alongside the
	 * mirrored dashboards. Blocking the whole folder would take that away.
	 */
	public function testANonDashboardFileInALinkFolderIsAllowed(): void {
		$this->mappedFolders = ['Pointers' => Mapping::MODE_LINK];

		self::assertTrue($this->createIn('Pointers', 'Budget.xlsx'));
	}

	/**
	 * FAIL OPEN. A parent that is not a Nextcloud DAV directory cannot be classified,
	 * and a guard that cannot classify must never block.
	 */
	public function testAnUnrecognisedParentIsAllowed(): void {
		$this->mappedFolders = ['Pointers' => Mapping::MODE_LINK];
		$data = null;
		$modified = false;

		self::assertTrue($this->plugin->beforeCreateFile(
			'files/alice/Pointers/CPU Load.grafana',
			$data,
			$this->createStub(INode::class),
			$modified,
		));
	}

	/**
	 * Drive the hook the way Sabre does: the request path is `files/<uid>/<rel>`, and
	 * the PARENT is what knows the node path. Getting that wrong is what made the
	 * first cut of this guard inert — it reshaped the request path by hand and
	 * produced something that matched no mapping at all.
	 */
	private function createIn(string $folder, string $name): bool {
		$parent = $this->createStub(DavDirectory::class);
		$parent->method('getPath')->willReturn('/alice/files/' . $folder);
		$data = null;
		$modified = false;
		return $this->plugin->beforeCreateFile(
			'files/alice/' . $folder . '/' . $name,
			$data,
			$parent,
			$modified,
		);
	}
}
