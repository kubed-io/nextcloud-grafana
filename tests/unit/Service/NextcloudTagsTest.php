<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\NextcloudTags;
use OCA\GrafanaSync\Service\TagSet;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see NextcloudTags} — the translation from Nextcloud's two-table tag model to a
 * plain set of names.
 *
 * The harness is an in-memory stand-in for both tables, because the interesting
 * behaviour is the DIFF: there is no "set the tags on this object" call in the public
 * API, so a whole-set replacement has to be computed, and getting it wrong either
 * strips tags off unrelated files or leaves stale ones behind.
 */
#[CoversClass(NextcloudTags::class)]
final class NextcloudTagsTest extends TestCase {
	private const FILE = 42;

	/** @var array<string,string> tag id → name */
	private array $catalog = [];

	/** @var array<int,list<string>> file id → assigned tag ids */
	private array $assigned = [];

	/** @var list<string> every assign/unassign, in order */
	private array $calls = [];

	private int $nextId = 100;
	private bool $readFails = false;

	public function testReadsTheNamesOfWhatIsAssigned(): void {
		$this->catalog = ['1' => 'dns', '2' => 'linux'];
		$this->assigned = [self::FILE => ['1', '2']];

		self::assertTrue(TagSet::of(['dns', 'linux'])->equals($this->tags()->of(self::FILE)));
	}

	public function testAnUntaggedNodeReadsAsEmpty(): void {
		self::assertTrue($this->tags()->of(self::FILE)->isEmpty());
	}

	/**
	 * NEXTCLOUD HANDS BACK INTS. Comparing them strictly against the strings every
	 * consuming API wants reads as "no tags", which is how a first pass at this class
	 * concluded no folder anywhere could be tagged.
	 */
	public function testIntegerTagIdsAreStillFound(): void {
		$this->catalog = ['7' => 'dns'];
		$this->assigned = [self::FILE => [7]]; // ints, as the real mapper returns

		self::assertTrue(TagSet::of(['dns'])->equals($this->tags()->of(self::FILE)));
	}

	/** THE LOOP GUARD. An identical set must not write, because a write raises an event. */
	public function testSettingTheSameTagsDoesNothingAndSaysSo(): void {
		$this->catalog = ['1' => 'dns', '2' => 'linux'];
		$this->assigned = [self::FILE => ['1', '2']];

		self::assertFalse($this->tags()->set(self::FILE, TagSet::of(['linux', 'dns'])));
		self::assertSame([], $this->calls, 'no assignment call at all');
	}

	/** Only the difference moves — a tag already correct is not re-assigned. */
	public function testOnlyTheDifferenceIsWritten(): void {
		$this->catalog = ['1' => 'dns', '2' => 'linux', '3' => 'prod'];
		$this->assigned = [self::FILE => ['1', '2']];

		self::assertTrue($this->tags()->set(self::FILE, TagSet::of(['dns', 'prod'])));

		self::assertSame(['assign:3', 'unassign:2'], $this->calls);
	}

	public function testClearingRemovesEverything(): void {
		$this->catalog = ['1' => 'dns'];
		$this->assigned = [self::FILE => ['1']];

		self::assertTrue($this->tags()->set(self::FILE, TagSet::empty()));

		self::assertSame(['unassign:1'], $this->calls);
	}

	/**
	 * A tag arriving from Grafana usually does not exist here yet, and the alternative
	 * to creating it is a mirror that imports some tags and not others depending on
	 * what happened to be there already.
	 */
	public function testAnUnknownTagIsCreated(): void {
		self::assertTrue($this->tags()->set(self::FILE, TagSet::of(['brand-new'])));

		self::assertSame('brand-new', $this->catalog['100'] ?? null);
		self::assertSame(['assign:100'], $this->calls);
	}

	/** Importing four of five tags beats importing none. */
	public function testOneUncreatableTagDoesNotStopTheOthers(): void {
		self::assertTrue($this->tags(refuse: 'bad')->set(self::FILE, TagSet::of(['good', 'bad'])));

		self::assertSame(['assign:100'], $this->calls);
	}

	/** A tag id with no catalog row must not blank out the tags that do resolve. */
	public function testAnOrphanedAssignmentIsSkippedNotFatal(): void {
		$this->catalog = ['1' => 'dns'];
		$this->assigned = [self::FILE => ['1', '999']];

		self::assertTrue(TagSet::of(['dns'])->equals($this->tags()->of(self::FILE)));
	}

	/**
	 * A FAILED READ IS NOT AN EMPTY SET. Downstream, "no tags" legitimately means
	 * REMOVE EVERYTHING, and the push carries that to Grafana — so a database blip
	 * that answered empty would silently strip a dashboard's tags, which has no undo
	 * on the far side. It has to travel as a failure instead.
	 */
	public function testAFailedReadThrowsRatherThanLookingLikeNoTags(): void {
		$this->readFails = true;

		$this->expectException(\RuntimeException::class);
		$this->tags()->of(self::FILE);
	}

	/** And the same read failure must stop a set() from computing a diff against it. */
	public function testAFailedReadStopsAWriteInsteadOfRemovingEverything(): void {
		$this->readFails = true;

		try {
			$this->tags()->set(self::FILE, TagSet::of(['dns']));
			self::fail('the write should not have been attempted');
		} catch (\RuntimeException) {
			self::assertSame([], $this->calls, 'nothing was assigned or unassigned');
		}
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function tags(string $refuse = ''): NextcloudTags {
		$manager = $this->createStub(ISystemTagManager::class);
		$manager->method('getTagsByIds')->willReturnCallback(
			function (array $ids): array {
				$out = [];
				foreach ($ids as $id) {
					if (!isset($this->catalog[(string)$id])) {
						throw new TagNotFoundException('no such tag');
					}
					$out[] = $this->tag((string)$id, $this->catalog[(string)$id]);
				}
				return $out;
			},
		);
		$manager->method('getTag')->willReturnCallback(
			function (string $name): ISystemTag {
				$id = array_search($name, $this->catalog, true);
				if ($id === false) {
					throw new TagNotFoundException('no such tag');
				}
				return $this->tag((string)$id, $name);
			},
		);
		$manager->method('createTag')->willReturnCallback(
			function (string $name) use ($refuse): ISystemTag {
				if ($name === $refuse) {
					throw new \RuntimeException('refused');
				}
				$id = (string)$this->nextId++;
				$this->catalog[$id] = $name;
				return $this->tag($id, $name);
			},
		);

		$mapper = $this->createStub(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(
			function (array $objIds): array {
				if ($this->readFails) {
					throw new \RuntimeException('the tag tables are unreachable');
				}
				return [(string)$objIds[0] => $this->assigned[(int)$objIds[0]] ?? []];
			},
		);
		$mapper->method('assignTags')->willReturnCallback(
			function (string $objId, string $type, array $ids): void {
				foreach ($ids as $id) {
					$this->calls[] = 'assign:' . $id;
					$this->assigned[(int)$objId][] = $id;
				}
			},
		);
		$mapper->method('unassignTags')->willReturnCallback(
			function (string $objId, string $type, array $ids): void {
				foreach ($ids as $id) {
					$this->calls[] = 'unassign:' . $id;
				}
			},
		);

		return new NextcloudTags($manager, $mapper, new NullLogger());
	}

	private function tag(string $id, string $name): ISystemTag {
		$tag = $this->createStub(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		$tag->method('getName')->willReturn($name);
		return $tag;
	}
}
