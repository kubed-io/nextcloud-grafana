<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\MoveGuardListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MoveRules;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see MoveGuardListener}'s FOLDER branch — the two refusals a folder move inherits
 * from the file rules, one level up.
 *
 * The invariant that matters most is the one about NOT refusing: a folder under a
 * mapping is a plain folder until a dashboard lands beneath it, so a folder this app
 * has never stamped is the user's own and moves wherever they like. Guarding every
 * folder under a mapping would mean a "Notes" folder inside a link mapping could not
 * be dragged out of it.
 */
#[CoversClass(MoveGuardListener::class)]
#[CoversClass(MoveRules::class)]
final class MoveGuardListenerTest extends TestCase {
	/** @var array<int,string> folder id → banked Grafana uid */
	private array $stamped = [];

	/** @var array<string,string> nc folder → mapping mode; absent = unmapped */
	private array $mapped = [];

	public function testAMirroredFolderMayNotCrossFromSyncToLink(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/may not change that/');
		$this->guard('/alice/files/Demo/Team', '/alice/files/Pointers/Team', 20);
	}

	public function testAMirroredFolderMayNotCrossFromLinkToSync(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->guard('/alice/files/Pointers/Team', '/alice/files/Demo/Team', 20);
	}

	/** A link's dashboards are pointers — moving them out orphans the lot. */
	public function testAMirroredLinkFolderMayNotLeaveItsMapping(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/comes from Grafana/u');
		$this->guard('/alice/files/Pointers/Team', '/alice/files/Scratch/Team', 20);
	}

	/**
	 * A SYNC FOLDER MAY LEAVE. The cascade that follows is a consequence, decided
	 * afterwards — this gate is only about mode.
	 */
	public function testAMirroredSyncFolderMayLeaveItsMapping(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];

		$this->guard('/alice/files/Demo/Team', '/alice/files/Scratch/Team', 20);

		self::assertTrue(true, 'not refused');
	}

	public function testAMoveWithinOneMappingIsAllowed(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];

		$this->guard('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team', 20);

		self::assertTrue(true, 'not refused');
	}

	/**
	 * THE ONE THAT WAS WRONG. A folder under a mapping is a plain folder until a
	 * dashboard lands beneath it, so an unstamped folder is the user's own — and
	 * refusing to move it out of a link mapping would take away exactly the thing a
	 * link mapping is supposed to still allow.
	 */
	public function testAnUnstampedFolderMayLeaveALinkMapping(): void {
		$this->stamped = []; // the user's own "Notes" folder
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];

		$this->guard('/alice/files/Pointers/Notes', '/alice/files/Scratch/Notes', 40);

		self::assertTrue(true, 'not refused');
	}

	public function testAnUnstampedFolderMayCrossBetweenModes(): void {
		$this->stamped = [];
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Pointers' => Mapping::MODE_LINK];

		$this->guard('/alice/files/Demo/Notes', '/alice/files/Pointers/Notes', 40);

		self::assertTrue(true, 'not refused');
	}

	public function testAFolderOutsideEveryMappingIsNotConstrained(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->mapped = [];

		$this->guard('/alice/files/Scratch/Team', '/alice/files/Elsewhere/Team', 20);

		self::assertTrue(true, 'not refused');
	}

	// ── the FILE branch ────────────────────────────────────────────────────────

	/**
	 * THE REGRESSION THIS FILE EXISTS TO HOLD. A link moved between two link mappings
	 * used to be allowed and re-stamped onto the destination, which reads like a
	 * re-home and is not one: a pointer's membership is decided by which GRAFANA
	 * folder its dashboard sits in, so the stamp simply disagreed with Grafana until
	 * the next pull deleted it from the destination and wrote it back at the source.
	 */
	public function testALinkMayNotMoveToAnotherLinkMapping(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK, 'Mirrors' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/comes from Grafana/u');
		$this->guardFile('/alice/files/Pointers/Fleet.grafana', '/alice/files/Mirrors/Fleet.grafana', Mapping::MODE_LINK);
	}

	public function testALinkMayNotMoveIntoASyncMapping(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK, 'Demo' => Mapping::MODE_SYNC];

		$this->expectException(AbortedEventException::class);
		$this->guardFile('/alice/files/Pointers/Fleet.grafana', '/alice/files/Demo/Fleet.grafana', Mapping::MODE_LINK);
	}

	public function testALinkMayNotLeaveForAnUnmappedFolder(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->guardFile('/alice/files/Pointers/Fleet.grafana', '/alice/files/Scratch/Fleet.grafana', Mapping::MODE_LINK);
	}

	public function testASyncFileMayNotMoveIntoALinkMapping(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/Grafana’s to place/u');
		$this->guardFile('/alice/files/Demo/Fleet.grafana', '/alice/files/Pointers/Fleet.grafana', Mapping::MODE_SYNC);
	}

	/**
	 * A LINK DOES NOT MOVE, AND ITS OWN MAPPING IS NOT AN EXCEPTION.
	 *
	 * This test asserted the opposite, and called it "the invariant worth breaking a
	 * build over" — the wrong rule, written down as the most important thing in the
	 * file. A link is read-only in Nextcloud: WHERE a mirror sits is decided by which
	 * Grafana folder its dashboard is in, so a file dragged into a subfolder here
	 * disagrees with Grafana until the next pull puts it back. To file a link into a
	 * subfolder, move the DASHBOARD there in Grafana and the mirror follows.
	 *
	 * It never covered the rename it claimed to, either: the path keeps the same
	 * basename, so `$renamed` was false and the link-rename refusal — which has always
	 * been correct, and sits above the where-is-it-going rules for exactly this reason —
	 * was never reached. The file refused a link rename while allowing a link move, and
	 * this test documented only the half that was wrong.
	 */
	public function testALinkMayNotMoveWithinItsOwnMapping(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/comes from Grafana/u');
		$this->guardFile('/alice/files/Pointers/Fleet.grafana', '/alice/files/Pointers/Sub/Fleet.grafana', Mapping::MODE_LINK);
	}

	/**
	 * AND THE SHORTCUT IT USED TO GUARD IS STILL THERE, for sync files, which is all it
	 * ever correctly covered. A sync file is authored here and its folder is the user's
	 * to arrange.
	 */
	public function testASyncFileMayMoveWithinItsOwnMapping(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];

		$this->guardFile('/alice/files/Demo/Fleet.grafana', '/alice/files/Demo/Sub/Fleet.grafana', Mapping::MODE_SYNC);

		self::assertTrue(true, 'not refused');
	}

	public function testASyncFileMayLeaveItsMapping(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];

		$this->guardFile('/alice/files/Demo/Fleet.grafana', '/alice/files/Scratch/Fleet.grafana', Mapping::MODE_SYNC);

		self::assertTrue(true, 'not refused');
	}

	public function testASyncFileMayMoveToAnotherSyncMapping(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Reports' => Mapping::MODE_SYNC];

		$this->guardFile('/alice/files/Demo/Fleet.grafana', '/alice/files/Reports/Fleet.grafana', Mapping::MODE_SYNC);

		self::assertTrue(true, 'not refused');
	}

	/**
	 * RENAMING A LINK NEVER RENAMES THE DASHBOARD, so the rename is refused rather than
	 * left to be silently undone by the next pull. Within the SAME mapping — the case
	 * the mapping-ID early return used to wave through.
	 */
	public function testALinkRenameIsRefused(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/name comes from Grafana/u');
		$this->guardFile('/alice/files/Pointers/Fleet.grafana', '/alice/files/Pointers/Renamed.grafana', Mapping::MODE_LINK);
	}

	public function testARenameToAWhitespaceStemIsRefused(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/needs a name/');
		$this->guardFile('/alice/files/Demo/Fleet.grafana', '/alice/files/Demo/ .grafana', Mapping::MODE_SYNC);
	}

	public function testASyncRenameWithinItsFolderIsAllowed(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC];

		$this->guardFile('/alice/files/Demo/Fleet.grafana', '/alice/files/Demo/Renamed.grafana', Mapping::MODE_SYNC);

		self::assertTrue(true, 'not refused');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function guard(string $from, string $to, int $id): void {
		$folders = $this->createStub(FolderMetadata::class);
		$folders->method('uidOf')->willReturnCallback(fn (int $i): string => $this->stamped[$i] ?? '');

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('resolveForPath')->willReturnCallback(
			function (string $path): ?Mapping {
				foreach ($this->mapped as $folder => $mode) {
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

		$source = $this->createStub(Folder::class);
		$source->method('getPath')->willReturn($from);
		$source->method('getName')->willReturn(basename($from));
		$source->method('getId')->willReturn($id);

		$target = $this->createStub(Folder::class);
		$target->method('getPath')->willReturn($to);
		$target->method('getName')->willReturn(basename($to));

		$rules = new MoveRules($folders, $mappings, $this->createStub(DashboardMetadata::class));
		$listener = new MoveGuardListener($rules, new SyncGuard());
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));
	}

	/**
	 * The file branch. $mode is the file's own stamp, which is what the listener reads
	 * in preference to the source mapping's mode.
	 */
	private function guardFile(string $from, string $to, string $mode): void {
		$mappings = $this->createStub(MappingService::class);
		$mappings->method('resolveForPath')->willReturnCallback(
			function (string $path): ?Mapping {
				foreach ($this->mapped as $folder => $m) {
					if (str_contains($path, '/files/' . $folder . '/')) {
						return Mapping::fromArray([
							'id' => 'm-' . $folder,
							'grafana_folder_uid' => 'gf-' . $folder,
							'nc_folder' => $folder,
							'mode' => $m,
						]);
					}
				}
				return null;
			},
		);

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturn(new ManagedFile('dash-1', $mode, '1', 'h', 'm-src', 'gf-src'));

		$source = $this->createStub(File::class);
		$source->method('getPath')->willReturn($from);
		$source->method('getName')->willReturn(basename($from));
		$source->method('getId')->willReturn(42);

		$target = $this->createStub(File::class);
		$target->method('getPath')->willReturn($to);
		$target->method('getName')->willReturn(basename($to));

		$rules = new MoveRules($this->createStub(FolderMetadata::class), $mappings, $metadata);
		$listener = new MoveGuardListener($rules, new SyncGuard());
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));
	}
}
