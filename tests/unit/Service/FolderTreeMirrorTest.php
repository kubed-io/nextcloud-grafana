<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\FolderTreeMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see FolderTreeMirror} — the pull's half of the mirror, and the place the uid
 * rule earns its keep: a rename, a move, and both at once are one comparison.
 *
 * The fixtures are a fake Nextcloud tree of folder stubs keyed by path, plus a fake
 * Grafana folder list. `$stamped` is the banked uid per Nextcloud folder id, so a
 * folder the app has never mirrored simply has no entry and is invisible.
 */
final class FolderTreeMirrorTest extends TestCase {
	/** @var list<array{uid:string,title:string,parentUid:string}> */
	private array $grafanaFolders = [];

	/** @var array<int,string> nc folder id → banked Grafana uid */
	private array $stamped = [];

	/** @var array<string,Folder> path → folder stub */
	private array $tree = [];

	/** @var list<array{string,string}> [fromPath, toPath] for every move */
	private array $moves = [];

	/** @var list<array{string,string}> [parentPath, name] for every create */
	private array $creates = [];

	private int $nextId = 100;

	/** When set, newFolder() throws for a child of this name — one unmakeable folder. */
	private string $refuseCreateOf = '';

	public function testAGrafanaFolderWithNoMirrorIsCreatedAndStamped(): void {
		$this->grafanaFolders = [
			['uid' => 'gf-team', 'title' => 'Team', 'parentUid' => 'gf-demo'],
		];
		$root = $this->folder('/alice/files/Demo', 10);

		$placed = $this->mirror()->sync($root, $this->mapping());

		self::assertSame([['/alice/files/Demo', 'Team']], $this->creates);
		self::assertArrayHasKey('gf-team', $placed);
		self::assertSame('gf-team', $this->stamped[100], 'the new folder banks the uid');
	}

	/** The parents come first, so a child always has somewhere to be created. */
	public function testANestedTreeIsCreatedOutermostFirst(): void {
		$this->grafanaFolders = [
			['uid' => 'gf-drafts', 'title' => 'Drafts', 'parentUid' => 'gf-team'],
			['uid' => 'gf-team', 'title' => 'Team', 'parentUid' => 'gf-demo'],
		];
		$root = $this->folder('/alice/files/Demo', 10);

		$this->mirror()->sync($root, $this->mapping());

		self::assertSame(
			[['/alice/files/Demo', 'Team'], ['/alice/files/Demo/Team', 'Drafts']],
			$this->creates,
			'Team before Drafts, whatever order Grafana listed them in',
		);
	}

	/**
	 * SAME UID, DIFFERENT TITLE — a rename. Read by name this is one folder vanishing
	 * and another appearing, and a name-keyed mirror would delete every dashboard
	 * underneath and re-create them with new uids.
	 */
	public function testARenameInGrafanaRenamesTheNextcloudFolder(): void {
		$this->grafanaFolders = [['uid' => 'gf-team', 'title' => 'Squad', 'parentUid' => 'gf-demo']];
		$root = $this->folder('/alice/files/Demo', 10);
		$this->folder('/alice/files/Demo/Team', 20, 'gf-team');
		$this->childrenOf('/alice/files/Demo', ['/alice/files/Demo/Team']);

		$this->mirror()->sync($root, $this->mapping());

		self::assertSame([['/alice/files/Demo/Team', '/alice/files/Demo/Squad']], $this->moves);
		self::assertSame([], $this->creates, 'a rename is not a create');
	}

	/** SAME UID, DIFFERENT PARENT — a move. */
	public function testAMoveInGrafanaMovesTheNextcloudFolder(): void {
		$this->grafanaFolders = [
			['uid' => 'gf-archive', 'title' => 'Archive', 'parentUid' => 'gf-demo'],
			['uid' => 'gf-team', 'title' => 'Team', 'parentUid' => 'gf-archive'],
		];
		$root = $this->folder('/alice/files/Demo', 10);
		$this->folder('/alice/files/Demo/Archive', 30, 'gf-archive');
		$this->folder('/alice/files/Demo/Team', 20, 'gf-team');
		$this->childrenOf('/alice/files/Demo', ['/alice/files/Demo/Archive', '/alice/files/Demo/Team']);

		$this->mirror()->sync($root, $this->mapping());

		self::assertSame([['/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team']], $this->moves);
	}

	/**
	 * BOTH AT ONCE. Grafana can re-parent and retitle with no sync in between, so
	 * this app will observe both — and it needs no special case, because the uid
	 * already says which folder it is. One move, to a new place under a new name.
	 */
	public function testAMoveAndRenameAtOnceIsASingleMove(): void {
		$this->grafanaFolders = [
			['uid' => 'gf-archive', 'title' => 'Archive', 'parentUid' => 'gf-demo'],
			['uid' => 'gf-team', 'title' => 'Squad', 'parentUid' => 'gf-archive'],
		];
		$root = $this->folder('/alice/files/Demo', 10);
		$this->folder('/alice/files/Demo/Archive', 30, 'gf-archive');
		$this->folder('/alice/files/Demo/Team', 20, 'gf-team');
		$this->childrenOf('/alice/files/Demo', ['/alice/files/Demo/Archive', '/alice/files/Demo/Team']);

		$this->mirror()->sync($root, $this->mapping());

		self::assertSame(
			[['/alice/files/Demo/Team', '/alice/files/Demo/Archive/Squad']],
			$this->moves,
			'one move, not a move then a rename — the folder is never in a place it was not',
		);
	}

	public function testAFolderThatHasNotChangedIsLeftAlone(): void {
		$this->grafanaFolders = [['uid' => 'gf-team', 'title' => 'Team', 'parentUid' => 'gf-demo']];
		$root = $this->folder('/alice/files/Demo', 10);
		$this->folder('/alice/files/Demo/Team', 20, 'gf-team');
		$this->childrenOf('/alice/files/Demo', ['/alice/files/Demo/Team']);

		$this->mirror()->sync($root, $this->mapping());

		self::assertSame([], $this->moves);
		self::assertSame([], $this->creates);
	}

	/**
	 * A FOLDER THE USER MADE STAYS THEIRS. It carries no uid, so it is invisible to
	 * the reconcile — which is what keeps a mapped folder usable for ordinary things.
	 */
	public function testAnUnstampedFolderIsNotTouched(): void {
		$this->grafanaFolders = [];
		$root = $this->folder('/alice/files/Demo', 10);
		$this->folder('/alice/files/Demo/Holiday Photos', 40); // no uid
		$this->childrenOf('/alice/files/Demo', ['/alice/files/Demo/Holiday Photos']);

		$placed = $this->mirror()->sync($root, $this->mapping());

		self::assertSame([], $placed);
		self::assertSame([], $this->moves);
	}

	/** A reserved-root mapping mirrors every folder in the instance, from the top. */
	public function testAReservedRootMappingMirrorsFromTheGrafanaRoot(): void {
		$this->grafanaFolders = [['uid' => 'gf-top', 'title' => 'Observability', 'parentUid' => '']];
		$root = $this->folder('/alice/files/Everything', 10);

		$placed = $this->mirror()->sync($root, $this->mapping('/'));

		self::assertSame([['/alice/files/Everything', 'Observability']], $this->creates);
		self::assertArrayHasKey('gf-top', $placed);
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

	/** Register a folder in the fake tree; `$uid` stamps it as already mirrored. */
	private function folder(string $path, int $id, string $uid = ''): Folder {
		$f = $this->createStub(Folder::class);
		$f->method('getId')->willReturn($id);
		$f->method('getPath')->willReturn($path);
		$f->method('getName')->willReturn(basename($path));
		$f->method('getDirectoryListing')->willReturnCallback(
			fn (): array => $this->children[$path] ?? [],
		);
		$f->method('newFolder')->willReturnCallback(
			function (string $name) use ($path): Folder {
				$this->creates[] = [$path, $name];
				if ($name === $this->refuseCreateOf) {
					throw new \RuntimeException('cannot create ' . $name);
				}
				return $this->folder($path . '/' . $name, $this->nextId++);
			},
		);
		$f->method('move')->willReturnCallback(
			function (string $to) use ($path): Folder {
				$this->moves[] = [$path, $to];
				return $this->folder($to, 999);
			},
		);
		if ($uid !== '') {
			$this->stamped[$id] = $uid;
		}
		$this->tree[$path] = $f;
		return $f;
	}

	/** @var array<string,list<Folder>> */
	private array $children = [];

	/** @param list<string> $paths */
	private function childrenOf(string $parent, array $paths): void {
		$this->children[$parent] = array_map(fn (string $p): Folder => $this->tree[$p], $paths);
	}

	private function mirror(): FolderTreeMirror {
		$grafana = $this->createStub(GrafanaClient::class);
		$grafana->method('listFolders')->willReturnCallback(fn (): array => $this->grafanaFolders);

		$folders = $this->createStub(FolderMetadata::class);
		$folders->method('uidOf')->willReturnCallback(fn (int $id): string => $this->stamped[$id] ?? '');
		$folders->method('stamp')->willReturnCallback(
			function (int $id, string $uid): void {
				$this->stamped[$id] = $uid;
			},
		);

		return new FolderTreeMirror($grafana, $folders, new NullLogger());
	}

	/**
	 * ONE BAD FOLDER MUST NOT TAKE THE PULL WITH IT. `newFolder()` throws on a name
	 * collision, a permission problem, a name the storage refuses — all local to one
	 * folder. Letting it escape would abort the reconcile and the pull around it, so
	 * one unmakeable folder would stop every dashboard in the mapping from syncing.
	 */
	public function testAFolderThatCannotBeCreatedIsSkippedAndTheRestContinue(): void {
		$this->grafanaFolders = [
			['uid' => 'gf-bad', 'title' => 'Bad', 'parentUid' => 'gf-demo'],
			['uid' => 'gf-good', 'title' => 'Good', 'parentUid' => 'gf-demo'],
		];
		$this->refuseCreateOf = 'Bad';
		$root = $this->folder('/alice/files/Demo', 10);

		$placed = $this->mirror()->sync($root, $this->mapping());

		self::assertArrayNotHasKey('gf-bad', $placed);
		self::assertArrayHasKey('gf-good', $placed, 'the rest of the tree still mirrors');
	}

	/** Its children go too — they find no parent placed, so they are skipped in turn. */
	public function testTheChildrenOfASkippedFolderAreSkippedToo(): void {
		$this->grafanaFolders = [
			['uid' => 'gf-bad', 'title' => 'Bad', 'parentUid' => 'gf-demo'],
			['uid' => 'gf-kid', 'title' => 'Kid', 'parentUid' => 'gf-bad'],
		];
		$this->refuseCreateOf = 'Bad';
		$root = $this->folder('/alice/files/Demo', 10);

		$placed = $this->mirror()->sync($root, $this->mapping());

		self::assertSame([], $placed);
		self::assertSame([['/alice/files/Demo', 'Bad']], $this->creates, 'Kid was never attempted');
	}

	/**
	 * A folder whose parent Grafana never reports is skipped rather than created at
	 * the root — placing it there would invent a structure neither side has.
	 */
	public function testAFolderWithAnUnplaceableParentIsSkipped(): void {
		$this->grafanaFolders = [['uid' => 'gf-orphan', 'title' => 'Orphan', 'parentUid' => 'gf-nowhere']];
		$root = $this->folder('/alice/files/Demo', 10);

		self::assertSame([], $this->mirror()->sync($root, $this->mapping()));
		self::assertSame([], $this->creates);
	}

	// ── harness additions ──────────────────────────────────────────────────────
}
