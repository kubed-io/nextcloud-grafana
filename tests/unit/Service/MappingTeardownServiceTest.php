<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MappingTeardownService;
use OCA\GrafanaSync\Service\StorageService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TrashControl;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see MappingTeardownService} — removing a mapping answers each connected
 * file by its MODE (a link goes, a sync file stays and becomes unmapped), leaves everything
 * else strictly alone, and never fails the removal because one file would not move.
 */
#[CoversClass(MappingTeardownService::class)]
final class MappingTeardownServiceTest extends TestCase {
	private const ID = 'm-1';

	private MappingService $mappings;
	private StorageService $storage;
	private DashboardMetadata $metadata;
	private TrashControl $trash;
	private SyncGuard $guard;
	private MappingTeardownService $service;

	protected function setUp(): void {
		$this->mappings = $this->createMock(MappingService::class);
		$this->storage = $this->createMock(StorageService::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->trash = $this->createMock(TrashControl::class);
		// A REAL GUARD, NOT A STUB. Whether the walk actually runs inside it is the whole
		// safety property here — a mock would answer `active()` however it was told to.
		$this->guard = new SyncGuard();
		// `withoutTrash` runs its callable; the class under test is what decides to use it.
		$this->trash->method('withoutTrash')->willReturnCallback(static fn (callable $fn) => $fn());
		$this->service = new MappingTeardownService(
			$this->mappings,
			$this->storage,
			$this->metadata,
			$this->trash,
			$this->guard,
			new NullLogger(),
		);
	}

	private function mapping(): Mapping {
		return Mapping::fromArray(['id' => self::ID, 'grafana_folder_uid' => 'gf', 'nc_folder' => 'alpha', 'mode' => 'sync']);
	}

	private function dashFile(int $id): File {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn('D' . $id . '.grafana');
		$f->method('getId')->willReturn($id);
		$f->method('getPath')->willReturn('/alice/files/alpha/D' . $id . '.grafana');
		return $f;
	}

	private function managed(string $mappingId, string $mode = 'sync', string $uid = 'dash'): ManagedFile {
		return new ManagedFile($uid, $mode, '1', 'h', $mappingId, '');
	}

	/** Wire a mapping whose folder lists exactly $children. */
	private function tree(array $children): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);
		$this->mappings->method('getById')->with(self::ID)->willReturn($this->mapping());
		$this->storage->method('findFolder')->willReturn($folder);
	}

	/**
	 * THE SYNC HALF OF THE RULE. The file holds the dashboard JSON and may be the last copy
	 * of it, so removing the mapping must not remove the file — it drops the connection and
	 * KEEPS the uid, because the dashboard it names is still in Grafana.
	 */
	public function testASyncFileStaysAndBecomesUnmapped(): void {
		$connected = $this->dashFile(1);
		$this->tree([$connected]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'sync'));

		$connected->expects(self::never())->method('delete');
		$this->metadata->expects(self::once())->method('write')->with(1, [
			DashboardMetadata::KEY_MAPPING => '',
			DashboardMetadata::KEY_MODE => DashboardMetadata::MODE_UNMAPPED,
		]);

		$this->service->remove(self::ID);
	}

	/**
	 * THE LINK HALF. A pointer whose mapping is gone has nothing left to be, and it goes
	 * WITHOUT a trash entry — restoring one would reconnect to nothing.
	 */
	public function testALinkFileIsRemovedWithNoTrashEntry(): void {
		$connected = $this->dashFile(1);
		$this->tree([$connected]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$this->trash->expects(self::once())->method('withoutTrash');
		$connected->expects(self::once())->method('delete');
		$this->metadata->expects(self::never())->method('write');

		$this->service->remove(self::ID);
	}

	/**
	 * A MIXED TREE, WHICH IS WHY THE MODE IS ASKED OF THE FILE. Reading the mapping's own
	 * mode would give one answer for a folder holding both, and one of the two would be
	 * wrong — either an archive destroyed or a dead pointer left behind.
	 */
	public function testTheModeIsAskedOfEachFileNotOfTheMapping(): void {
		$link = $this->dashFile(1);
		$sync = $this->dashFile(2);
		$this->tree([$link, $sync]);
		$this->metadata->method('read')->willReturnMap([
			[1, $this->managed(self::ID, 'link')],
			[2, $this->managed(self::ID, 'sync')],
		]);

		$link->expects(self::once())->method('delete');
		$sync->expects(self::never())->method('delete');
		$this->metadata->expects(self::once())->method('write')->with(2, self::anything());

		$this->service->remove(self::ID);
	}

	/** The walk reaches a nested subfolder — a mirror is not always at the top level. */
	public function testItReachesFilesInSubfolders(): void {
		$deep = $this->dashFile(1);
		$sub = $this->createMock(Folder::class);
		$sub->method('getDirectoryListing')->willReturn([$deep]);
		$this->tree([$sub]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$deep->expects(self::once())->method('delete');

		$this->service->remove(self::ID);
	}

	/**
	 * Only THIS mapping's files. An unmanaged `.grafana` somebody dropped in, one belonging
	 * to a mapping nested inside this tree, and a file that is not a dashboard at all are
	 * none of the tear-down's business — the folder stays usable as a folder.
	 */
	public function testEverythingElseIsLeftStrictlyAlone(): void {
		$connected = $this->dashFile(1);
		$other = $this->dashFile(2);   // a DIFFERENT mapping
		$loose = $this->dashFile(3);   // unmanaged standalone
		$plain = $this->createMock(File::class);
		$plain->method('getName')->willReturn('notes.txt');
		$this->tree([$connected, $other, $loose, $plain]);
		$this->metadata->method('read')->willReturnMap([
			[1, $this->managed(self::ID, 'link')],
			[2, $this->managed('m-other', 'link')],
			[3, null],
		]);

		$connected->expects(self::once())->method('delete');
		$other->expects(self::never())->method('delete');
		$loose->expects(self::never())->method('delete');
		$plain->expects(self::never())->method('delete');

		$this->service->remove(self::ID);
	}

	/**
	 * THE GUARD IS THE SAFETY PROPERTY, so it is asserted rather than assumed. Every
	 * `Node::delete()` here fires the same event a person's delete does, and the delete
	 * listener answers that by reaching into Grafana — it REFUSES to delete a link, which is
	 * why removing a link mapping used to fail outright. Inside the guard the listener bails.
	 */
	public function testTheWalkRunsWithTheSyncGuardActive(): void {
		$connected = $this->dashFile(1);
		$this->tree([$connected]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$seen = false;
		$connected->method('delete')->willReturnCallback(function () use (&$seen): void {
			$seen = $this->guard->active();
		});

		$this->service->remove(self::ID);

		self::assertTrue($seen, 'the file walk ran outside the SyncGuard, so a delete would reach Grafana');
		self::assertFalse($this->guard->active(), 'the guard was left active after the tear-down');
	}

	/**
	 * REMOVING THE MAPPING IS THE ACT THE ADMIN ASKED FOR, and it must not fail because one
	 * file would not move. This replaced a branch that kept the binding and reported "retry":
	 * it existed because a connected file's delete reached Grafana and could fail there, and
	 * it cannot now.
	 */
	public function testAStubbornFileNeitherStopsTheWalkNorKeepsTheMapping(): void {
		$bad = $this->dashFile(1);
		$good = $this->dashFile(2);
		$this->tree([$bad, $good]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$bad->method('delete')->willThrowException(new \RuntimeException('file is locked'));
		$good->expects(self::once())->method('delete'); // the walk continues past the failure
		$this->mappings->expects(self::once())->method('delete')->with(self::ID);

		$this->service->remove(self::ID);
	}

	/**
	 * A LINK THAT WILL NOT GO MUST STOP BEING A LINK, or it can never go at all.
	 *
	 * `DeleteToGrafanaListener` refuses to delete any file stamped `link` — it holds no
	 * reference to MappingService and decides on the stored mode alone — so a failed
	 * removal would leave a dead pointer nobody can shift, at a mapping that no longer
	 * exists. Clearing the record makes it an ordinary file the app has no opinion about.
	 */
	public function testALinkThatCannotBeRemovedIsDisownedSoItIsDeletableLater(): void {
		$stuck = $this->dashFile(1);
		$this->tree([$stuck]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$stuck->method('delete')->willThrowException(new \RuntimeException('file is locked'));
		$this->metadata->expects(self::once())->method('clear')->with(1);
		// NOT `unmapped`: that leaves the file managed and still carrying its uid, so the
		// user's next delete would take the bin-off branch and destroy a dashboard in
		// Grafana that nothing in Nextcloud claims any more.
		$this->metadata->expects(self::never())->method('write');

		$this->service->remove(self::ID);
	}

	/** A link that removes cleanly is gone, so there is no record left to clear. */
	public function testASuccessfulLinkRemovalDoesNotTouchMetadata(): void {
		$connected = $this->dashFile(1);
		$this->tree([$connected]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$connected->expects(self::once())->method('delete');
		$this->metadata->expects(self::never())->method('clear');

		$this->service->remove(self::ID);
	}

	/** The disown is best-effort: its own failure must not abandon the rest of the walk. */
	public function testAFailedDisownStillLetsTheWalkFinish(): void {
		$stuck = $this->dashFile(1);
		$later = $this->dashFile(2);
		$this->tree([$stuck, $later]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$stuck->method('delete')->willThrowException(new \RuntimeException('file is locked'));
		$this->metadata->method('clear')->willThrowException(new \RuntimeException('metadata backend is down'));
		$later->expects(self::once())->method('delete');

		$this->service->remove(self::ID);
	}

	/** The binding goes first, so a throw can never leave it over a dismantled tree. */
	public function testTheBindingIsDroppedBeforeTheFilesAreTouched(): void {
		$connected = $this->dashFile(1);
		$this->tree([$connected]);
		$this->metadata->method('read')->willReturn($this->managed(self::ID, 'link'));

		$order = [];
		$this->mappings->method('delete')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'binding';
		});
		$connected->method('delete')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'file';
		});

		$this->service->remove(self::ID);

		self::assertSame(['binding', 'file'], $order);
	}

	/** A mapping whose folder was never provisioned has no files to answer for. */
	public function testAMissingFolderIsNotAnError(): void {
		$this->mappings->method('getById')->with(self::ID)->willReturn($this->mapping());
		$this->storage->method('findFolder')->willReturn(null);
		$this->mappings->expects(self::once())->method('delete')->with(self::ID);

		$this->service->remove(self::ID);
	}

	public function testAnUnknownMappingThrowsOutOfBounds(): void {
		$this->mappings->method('getById')->willReturn(null);
		$this->storage->expects(self::never())->method('findFolder');
		$this->mappings->expects(self::never())->method('delete');

		$this->expectException(\OutOfBoundsException::class);
		$this->service->remove('nope');
	}
}
