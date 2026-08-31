<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\ExistingDashboards;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\StorageService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TrashControl;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The `.grafana` files already under a folder a link mapping would claim: finding
 * them at every depth, destroying them without a trash entry, and doing it inside
 * the guard — without which clearing the folder would delete the dashboards in
 * Grafana that the mapping is about to mirror.
 */
#[CoversClass(ExistingDashboards::class)]
final class ExistingDashboardsTest extends TestCase {
	private StorageService $storage;
	private TrashControl $trash;
	private SyncGuard $guard;
	private ExistingDashboards $existing;

	protected function setUp(): void {
		$this->storage = $this->createMock(StorageService::class);
		$this->trash = $this->createMock(TrashControl::class);
		// A REAL GUARD. Whether the sweep runs inside it is the safety property here;
		// a mock would answer `active()` however it was told to.
		$this->guard = new SyncGuard();
		$this->trash->method('withoutTrash')->willReturnCallback(static fn (callable $fn) => $fn());
		$this->existing = new ExistingDashboards($this->storage, $this->trash, $this->guard, new NullLogger());
	}

	private function mapping(string $mode = Mapping::MODE_LINK): Mapping {
		return Mapping::fromArray([
			'grafana_folder_uid' => 'gf',
			'grafana_folder_title' => 'observe',
			'nc_folder' => 'Dashboards',
			'mode' => $mode,
		]);
	}

	private function dashFile(string $name = 'Keeper.grafana'): File {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn($name);
		$f->method('getPath')->willReturn('/alice/files/Dashboards/' . $name);
		return $f;
	}

	private function folder(array $children): Folder {
		$f = $this->createMock(Folder::class);
		$f->method('getDirectoryListing')->willReturn($children);
		$f->method('getName')->willReturn('Dashboards');
		return $f;
	}

	/** Most mappings are made against a name nothing has used, and that is not a warning. */
	public function testAFolderThatDoesNotExistHoldsNothing(): void {
		$this->storage->method('findFolder')->willReturn(null);

		self::assertSame([], $this->existing->under($this->mapping()));
	}

	public function testFindsDashboardFilesAtTheTopLevel(): void {
		$keeper = $this->dashFile();
		$this->storage->method('findFolder')->willReturn($this->folder([$keeper]));

		self::assertSame([$keeper], $this->existing->under($this->mapping()));
	}

	/**
	 * AT EVERY DEPTH, which is the case worth catching: a purge that swept only the top
	 * level would leave the contradiction one folder down, and a link mapping would sit
	 * over a dashboard file after the admin was told the folder had been cleared.
	 */
	public function testFindsDashboardFilesInSubfolders(): void {
		$deep = $this->dashFile('Latency.grafana');
		$sub = $this->folder([$deep]);
		$this->storage->method('findFolder')->willReturn($this->folder([$sub]));

		self::assertSame([$deep], $this->existing->under($this->mapping()));
	}

	/** Only `.grafana`. A folder stays usable as a folder. */
	public function testIgnoresFilesThatAreNotDashboards(): void {
		$notes = $this->createMock(File::class);
		$notes->method('getName')->willReturn('notes.txt');
		$this->storage->method('findFolder')->willReturn($this->folder([$notes]));

		self::assertSame([], $this->existing->under($this->mapping()));
	}

	/**
	 * AN UNREADABLE FOLDER IS NOT AN EMPTY ONE. Answering "nothing found" would let the
	 * mapping be created over dashboard files nobody could see — the exact state this
	 * class exists to prevent — so it fails closed, as the type both front doors
	 * already turn into a readable refusal.
	 */
	public function testAFolderThatCannotBeListedIsRefusedRatherThanCalledEmpty(): void {
		$broken = $this->createMock(Folder::class);
		$broken->method('getName')->willReturn('Dashboards');
		$broken->method('getDirectoryListing')->willThrowException(new \RuntimeException('permission denied'));
		$this->storage->method('findFolder')->willReturn($broken);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/could not be read/');
		$this->existing->under($this->mapping());
	}

	/**
	 * THE OTHER WAY OF NOT KNOWING, AND IT HAS TO ANSWER THE SAME. A tree deeper than
	 * the ceiling used to end the walk with `[]` while an unreadable folder threw — the
	 * class failing closed on one and open on the other, which leaves the guard with a
	 * door in it: the files really are down there, and the link mapping would be made
	 * over them.
	 */
	public function testATreeTooDeepToScanIsRefusedRatherThanCalledEmpty(): void {
		// A folder that is its own child, so the walk can only end at the ceiling.
		$loop = $this->createMock(Folder::class);
		$loop->method('getName')->willReturn('Deep');
		$loop->method('getDirectoryListing')->willReturnCallback(static fn (): array => [$loop]);
		$this->storage->method('findFolder')->willReturn($loop);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/nested more than \d+ levels deep/');
		$this->existing->under($this->mapping());
	}

	/** Destroyed outright: a restore into a link mapping cannot work, so none is offered. */
	public function testPurgeDeletesWithoutATrashEntry(): void {
		$keeper = $this->dashFile();

		$this->trash->expects(self::once())->method('withoutTrash');
		$keeper->expects(self::once())->method('delete');

		self::assertSame(1, $this->existing->purge([$keeper]));
	}

	/**
	 * THE GUARD IS THE SAFETY PROPERTY. These files are `unmapped` and an unmapped file
	 * KEEPS its uid, so each delete fires the same event a person's delete does and the
	 * listener answers it by reaching into Grafana. Outside the guard, clearing a folder
	 * so it can mirror a Grafana folder would destroy the dashboards in it.
	 */
	public function testPurgeRunsWithTheSyncGuardActive(): void {
		$keeper = $this->dashFile();
		$seen = false;
		$keeper->method('delete')->willReturnCallback(function () use (&$seen): void {
			$seen = $this->guard->active();
		});

		$this->existing->purge([$keeper]);

		self::assertTrue($seen, 'the purge ran outside the SyncGuard, so a delete would reach Grafana');
		self::assertFalse($this->guard->active(), 'the guard was left active after the purge');
	}

	/**
	 * NEVER THROWS. The mapping this clears the way for has already been created, so
	 * failing here would leave the admin with a mapping they cannot see and an error
	 * they cannot act on. The survivor is visible in the folder and in the log.
	 */
	public function testAFileThatWillNotGoIsSteppedOverAndNotCounted(): void {
		$stuck = $this->dashFile('Stuck.grafana');
		$fine = $this->dashFile('Fine.grafana');
		$stuck->method('delete')->willThrowException(new \RuntimeException('file is locked'));
		$fine->expects(self::once())->method('delete');

		self::assertSame(1, $this->existing->purge([$stuck, $fine]));
	}

	public function testPurgingNothingIsANoOp(): void {
		$this->trash->expects(self::never())->method('withoutTrash');

		self::assertSame(0, $this->existing->purge([]));
	}
}
