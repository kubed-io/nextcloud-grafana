<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Migration;

use OCA\GrafanaSync\Migration\MigrateFileExtension;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\StorageService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see MigrateFileExtension} — the one-time rename from the retired `.grafana.json`
 * to `.grafana`.
 *
 * THIS IS THE ONLY THING IN THE CUT THAT TOUCHES A USER'S FILES, and it runs
 * unattended during an upgrade, so the failure modes worth pinning are the quiet
 * ones: a file skipped (its dashboard is then unreachable forever, because the app
 * no longer recognises the old extension), a file renamed twice, or one rename
 * throwing and taking the rest of the folder down with it.
 */
#[CoversClass(MigrateFileExtension::class)]
final class MigrateFileExtensionTest extends TestCase {
	/** @var list<string> every move() the step performed, as "<old> -> <new path>" */
	private array $moves = [];

	/**
	 * Names that already exist in the folder, so a rename onto one has to step aside.
	 *
	 * @var list<string>
	 */
	private array $occupied = [];

	protected function setUp(): void {
		$this->moves = [];
		$this->occupied = [];
	}

	public function testTheRetiredExtensionIsRenamedToTheNewOne(): void {
		$this->run(['Fleet Health.grafana.json']);

		self::assertSame(
			['Fleet Health.grafana.json -> /alice/files/Demo/Fleet Health.grafana'],
			$this->moves,
		);
	}

	/**
	 * THE SHAPE THE OLD EXTENSION LEFT BEHIND. Nextcloud counted before the LAST
	 * extension, so a copy landed as `Fleet Health.grafana (1).json` — and it has to
	 * come out the far side as `Fleet Health (1).grafana`, the name the new extension
	 * would have produced, not as a file with a counter buried in its middle.
	 */
	public function testNextcloudsOldCollisionSpellingBecomesATrailingCounter(): void {
		$this->run(['Fleet Health.grafana (1).json']);

		self::assertSame(
			['Fleet Health.grafana (1).json -> /alice/files/Demo/Fleet Health (1).grafana'],
			$this->moves,
		);
	}

	/** The uid-suffixed shape keeps its uid, with the counter moving to the end. */
	public function testTheUidSuffixedShapeSurvivesWithItsCounterMoved(): void {
		$this->run(['Board.af397c9y8enswf.grafana (2).json']);

		self::assertSame(
			['Board.af397c9y8enswf.grafana (2).json -> /alice/files/Demo/Board.af397c9y8enswf (2).grafana'],
			$this->moves,
		);
	}

	/** Idempotent: run it twice and the second pass has nothing to do. */
	public function testAFileAlreadyOnTheNewExtensionIsLeftAlone(): void {
		$this->run(['Fleet Health.grafana', 'Fleet Health (1).grafana']);

		self::assertSame([], $this->moves);
	}

	/** Somebody else's file with the same tail is none of our business. */
	public function testAnUnrelatedFileIsNeverTouched(): void {
		$this->run(['Budget.xlsx', 'notes.json', 'board.grafana.yaml']);

		self::assertSame([], $this->moves);
	}

	/**
	 * TWO OLD NAMES CAN WANT ONE NEW NAME — a copy Nextcloud named and a file somebody
	 * had already renamed by hand both land on `Fleet Health (1).grafana`. The second
	 * one steps aside instead of throwing, because a throw here would strand every
	 * remaining file in the folder on an extension the app no longer reads.
	 */
	public function testASecondFileWantingTheSameNameStepsAside(): void {
		$this->occupied = ['Fleet Health (1).grafana'];
		$this->run(['Fleet Health.grafana (1).json']);

		self::assertSame(
			['Fleet Health.grafana (1).json -> /alice/files/Demo/Fleet Health (1) (1).grafana'],
			$this->moves,
		);
	}

	/** Subfolders are mirrored into Grafana too, so their files migrate as well. */
	public function testItRecursesIntoSubfolders(): void {
		$this->run(['Top.grafana.json'], ['Nested' => ['Deep.grafana.json']]);

		self::assertSame(
			[
				'Top.grafana.json -> /alice/files/Demo/Top.grafana',
				'Deep.grafana.json -> /alice/files/Demo/Nested/Deep.grafana',
			],
			$this->moves,
		);
	}

	/**
	 * One unrenamable file must not cost the others. The upgrade is unattended, so a
	 * half-migrated folder that reported success is the worst outcome available.
	 */
	public function testAFailedRenameDoesNotStopTheRest(): void {
		$this->run(['Broken.grafana.json', 'Fine.grafana.json'], [], 'Broken.grafana.json');

		self::assertSame(['Fine.grafana.json -> /alice/files/Demo/Fine.grafana'], $this->moves);
	}

	// ── harness ────────────────────────────────────────────────────────────────

	/**
	 * @param list<string> $files names directly in the mapped folder
	 * @param array<string, list<string>> $subfolders name → the files inside it
	 * @param string $throwsOn a filename whose move() blows up
	 */
	private function run(array $files, array $subfolders = [], string $throwsOn = ''): void {
		$folder = $this->folderNode('/alice/files/Demo', $files, $subfolders, $throwsOn);

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('list')->willReturn([$this->mapping()]);
		$storage = $this->createStub(StorageService::class);
		$storage->method('findFolder')->willReturn($folder);

		$step = new MigrateFileExtension($mappings, $storage, new SyncGuard(), new NullLogger());
		$step->run($this->createStub(IOutput::class));
	}

	/**
	 * @param list<string> $files
	 * @param array<string, list<string>> $subfolders
	 */
	private function folderNode(string $path, array $files, array $subfolders, string $throwsOn): Folder {
		/** @var list<Node> $children */
		$children = [];
		foreach ($files as $name) {
			$children[] = $this->fileNode($path, $name, $throwsOn);
		}
		foreach ($subfolders as $name => $contents) {
			$children[] = $this->folderNode($path . '/' . $name, $contents, [], $throwsOn);
		}

		$folder = $this->createStub(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getDirectoryListing')->willReturn($children);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => in_array($name, $this->occupied, true),
		);
		return $folder;
	}

	private function fileNode(string $parentPath, string $name, string $throwsOn): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(crc32($parentPath . '/' . $name));
		$file->method('move')->willReturnCallback(function (string $target) use ($name, $throwsOn, $file): Node {
			if ($name === $throwsOn) {
				throw new \RuntimeException('locked');
			}
			$this->moves[] = $name . ' -> ' . $target;
			return $file;
		});
		return $file;
	}

	private function mapping(): Mapping {
		return Mapping::fromArray([
			'id' => 'map-alpha',
			'grafana_folder_uid' => 'gf-alpha',
			'grafana_folder_title' => 'alpha',
			'nc_folder' => 'Demo',
			'mode' => Mapping::MODE_SYNC,
		]);
	}
}
