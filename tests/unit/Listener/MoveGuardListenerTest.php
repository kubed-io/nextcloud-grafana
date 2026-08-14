<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\MoveGuardListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
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
		$this->expectExceptionMessageMatches('/only pointers/');
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

		$listener = new MoveGuardListener($folders, $mappings, $this->createStub(DashboardMetadata::class));
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));
	}
}
