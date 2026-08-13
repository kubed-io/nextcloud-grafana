<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\StorageService;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see FolderMirror} — the rule that a folder is in Grafana when a dashboard is in
 * it, and the parents come with it.
 *
 * The fixtures are a fake Nextcloud tree: `$paths` maps a node id to where it lives
 * relative to the user's files root, exactly as StorageService would answer, and
 * `$stamped` is the banked Grafana uid per folder id. A folder with no entry in
 * `$stamped` has never been mirrored.
 */
final class FolderMirrorTest extends TestCase {
	/** @var array<int,string> node id → path relative to the files root */
	private array $paths = [];

	/** @var array<int,string> folder id → banked Grafana folder uid */
	private array $stamped = [];

	/** @var list<array{string,string}> every createFolder call, as [title, parentUid] */
	private array $created = [];

	public function testAFileInTheMappedFolderResolvesWithoutTouchingGrafana(): void {
		$this->paths = [10 => 'Demo', 11 => 'Demo/CPU.grafana.json'];

		$uid = $this->mirror()->folderUidFor($this->node(11), $this->mapping());

		self::assertSame('gf-demo', $uid, 'the mapping\'s own folder');
		self::assertSame([], $this->created, 'nothing to create, so Grafana is not contacted');
	}

	/**
	 * THE RULE, AND THE REASON THE PARENTS COME WITH IT. A dashboard three folders
	 * deep needs all three, created outermost first so each one has a parent to be
	 * created under.
	 */
	public function testADashboardThreeFoldersDeepCreatesAllThree(): void {
		$this->paths = [
			10 => 'Demo',
			20 => 'Demo/Team',
			30 => 'Demo/Team/Drafts',
			31 => 'Demo/Team/Drafts/CPU.grafana.json',
		];

		$uid = $this->mirror()->folderUidFor($this->node(31), $this->mapping());

		self::assertSame([['Team', 'gf-demo'], ['Drafts', 'new-Team']], $this->created);
		self::assertSame('new-Drafts', $uid, 'the deepest folder holds the dashboard');
		self::assertSame('new-Team', $this->stamped[20], 'each folder banks its own uid');
		self::assertSame('new-Drafts', $this->stamped[30]);
	}

	public function testAFolderThatIsAlreadyMirroredIsNotCreatedAgain(): void {
		$this->paths = [10 => 'Demo', 20 => 'Demo/Team', 21 => 'Demo/Team/CPU.grafana.json'];
		$this->stamped = [20 => 'gf-team-existing'];

		$uid = $this->mirror()->folderUidFor($this->node(21), $this->mapping());

		self::assertSame('gf-team-existing', $uid);
		self::assertSame([], $this->created, 'a banked uid is trusted, not re-created');
	}

	/** Half a chain: the mirrored parent is reused and only the missing child is made. */
	public function testOnlyTheMissingLevelsAreCreated(): void {
		$this->paths = [
			10 => 'Demo',
			20 => 'Demo/Team',
			30 => 'Demo/Team/Drafts',
			31 => 'Demo/Team/Drafts/CPU.grafana.json',
		];
		$this->stamped = [20 => 'gf-team-existing'];

		$uid = $this->mirror()->folderUidFor($this->node(31), $this->mapping());

		self::assertSame([['Drafts', 'gf-team-existing']], $this->created);
		self::assertSame('new-Drafts', $uid);
	}

	/**
	 * A reserved-root mapping means the dashboard belongs to no Grafana folder, and a
	 * file sitting directly in it must stay that way — `null`, not the string '/'.
	 */
	public function testAReservedRootMappingResolvesToNoFolder(): void {
		$this->paths = [10 => 'Everything', 11 => 'Everything/CPU.grafana.json'];

		$uid = $this->mirror()->folderUidFor($this->node(11), $this->mapping('/'));

		self::assertNull($uid);
		self::assertSame([], $this->created);
	}

	/** Under a reserved-root mapping a subfolder still mirrors — at the Grafana root. */
	public function testASubfolderUnderAReservedRootMappingIsCreatedAtTheRoot(): void {
		$this->paths = [10 => 'Everything', 20 => 'Everything/Team', 21 => 'Everything/Team/CPU.grafana.json'];

		$uid = $this->mirror()->folderUidFor($this->node(21), $this->mapping('/'));

		self::assertSame([['Team', '']], $this->created, 'an empty parent is the Grafana root');
		self::assertSame('new-Team', $uid);
	}

	/**
	 * THE GUARD THAT KEEPS A BUG FROM BECOMING A DISASTER. If the walk up ever fails
	 * to recognise its own mapped folder it must stop, not keep climbing — a loop that
	 * cannot see its root would walk to the storage root and mirror the user's entire
	 * home into Grafana, one folder at a time.
	 */
	public function testTheWalkStopsRatherThanClimbingOutOfTheMapping(): void {
		// The file claims to live somewhere the mapped folder does not enclose.
		$this->paths = [10 => 'Demo', 40 => 'Elsewhere', 41 => 'Elsewhere/CPU.grafana.json'];

		$uid = $this->mirror()->folderUidFor($this->node(41), $this->mapping());

		self::assertSame([], $this->created, 'nothing outside the mapping is ever mirrored');
		self::assertSame('gf-demo', $uid);
	}

	/**
	 * A mapping whose Nextcloud folder has been deleted resolves to the mapping's own
	 * Grafana folder rather than inventing a chain — the caller's guards refuse the
	 * write, and creating folders for a mapping with nowhere to put them would leave
	 * litter in Grafana that nothing points at.
	 */
	public function testAMissingMappedFolderCreatesNothing(): void {
		$this->paths = [31 => 'Demo/Team/Drafts/CPU.grafana.json']; // id 10 absent

		$uid = $this->mirror()->folderUidFor($this->node(31), $this->mapping());

		self::assertSame([], $this->created);
		self::assertSame('gf-demo', $uid);
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function mapping(string $grafanaFolderUid = 'gf-demo'): Mapping {
		return Mapping::fromArray([
			'id' => 'map-demo',
			'grafana_folder_uid' => $grafanaFolderUid,
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 10,
		]);
	}

	/** A node in the fake tree; its parent is whatever `$paths` says sits above it. */
	private function node(int $id): Node {
		$node = $this->createStub(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn(basename($this->paths[$id] ?? ''));
		$node->method('getParent')->willReturnCallback(fn (): Folder => $this->parentOf($id));
		return $node;
	}

	private function parentOf(int $id): Folder {
		$path = $this->paths[$id] ?? '';
		$parentPath = trim(dirname($path), '/.');
		$parentId = 0;
		foreach ($this->paths as $candidate => $p) {
			if ($p === $parentPath) {
				$parentId = $candidate;
				break;
			}
		}

		$folder = $this->createStub(Folder::class);
		$folder->method('getId')->willReturn($parentId);
		$folder->method('getName')->willReturn(basename($parentPath));
		$folder->method('getParent')->willReturnCallback(
			// Id 0 is the synthetic top of the fake tree — a path the fixture does not
			// define. Recursing there would build root stubs forever and die of a stack
			// overflow, which reads as a hung suite rather than a failed test. The
			// production walk stops before this (an unknown id resolves to no path), so
			// reaching it means a change let the walk climb past the mapped folder —
			// exactly the bug testTheWalkStopsRatherThanClimbingOutOfTheMapping guards,
			// and it should say so out loud.
			fn (): Folder => $parentId === 0
				? throw new \LogicException(
					'The walk climbed past the top of the fake tree — it should have stopped at the mapped folder.',
				)
				: $this->parentOf($parentId),
		);
		return $folder;
	}

	private function mirror(): FolderMirror {
		$grafana = $this->createStub(GrafanaClient::class);
		$grafana->method('createFolder')->willReturnCallback(
			function (string $title, string $parentUid = ''): array {
				$this->created[] = [$title, $parentUid];
				return ['uid' => 'new-' . $title, 'title' => $title, 'parentUid' => $parentUid, 'version' => 1];
			},
		);

		$folders = $this->createStub(FolderMetadata::class);
		$folders->method('uidOf')->willReturnCallback(fn (int $id): string => $this->stamped[$id] ?? '');
		$folders->method('stamp')->willReturnCallback(
			function (int $id, string $uid): void {
				$this->stamped[$id] = $uid;
			},
		);

		$storage = $this->createStub(StorageService::class);
		$storage->method('pathOfFolderId')->willReturnCallback(
			fn (int $id): ?string => $this->paths[$id] ?? null,
		);

		return new FolderMirror($grafana, $folders, $storage, new NullLogger());
	}
}
