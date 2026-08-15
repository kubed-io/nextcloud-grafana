<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\BackgroundJob\ReconcileNameJob;
use OCA\GrafanaSync\Listener\NameSyncListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see NameSyncListener} — the enqueue DECISION logic for three-way name sync:
 * a rename enqueues `title_from_filename`, a title edit enqueues `filename_from_title`, and the
 * gates (guard / managed / sync / a real mismatch) keep it from firing otherwise.
 */
#[CoversClass(NameSyncListener::class)]
final class NameSyncListenerTest extends TestCase {
	private DashboardMetadata $metadata;
	private SyncGuard $guard;
	private IJobList $jobList;
	private NameSyncListener $listener;

	protected function setUp(): void {
		$this->metadata = $this->createMock(DashboardMetadata::class);
		$this->guard = new SyncGuard();
		$this->jobList = $this->createMock(IJobList::class);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->listener = new NameSyncListener($this->metadata, $this->guard, $this->jobList, $session);
	}

	private function file(string $name, string $content): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn($name);
		$node->method('getContent')->willReturn($content);
		return $node;
	}

	private function managed(string $mode = Mapping::MODE_SYNC, string $uid = 'dash-1'): ManagedFile {
		return new ManagedFile($uid, $mode, '1', 'h', 'm-1', '', '');
	}

	private function json(string $title): string {
		return json_encode(['title' => $title, 'panels' => []]);
	}

	private function expectEnqueue(string $action): void {
		$this->jobList->expects(self::once())->method('add')->with(
			ReconcileNameJob::class,
			['fileId' => 42, 'userId' => 'alice', 'action' => $action],
		);
	}

	public function testRenameWithMismatchEnqueuesTitleFromFilename(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->expectEnqueue('title_from_filename');
		// file is now "New Name", JSON title still "Old" → mismatch
		$file = $this->file('New Name.grafana', $this->json('Old'));
		$this->listener->handle(new NodeRenamedEvent($this->file('Old.grafana', $this->json('Old')), $file));
	}

	public function testRenameWithMatchingTitleEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->jobList->expects(self::never())->method('add');
		$file = $this->file('Same.grafana', $this->json('Same'));
		$this->listener->handle(new NodeRenamedEvent($file, $file));
	}

	public function testTitleEditWithMismatchEnqueuesFilenameFromTitle(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->expectEnqueue('filename_from_title');
		// filename stem "Board", JSON title now "Renamed" → mismatch
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana', $this->json('Renamed'))));
	}

	public function testTitleEditMatchingFilenameEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->jobList->expects(self::never())->method('add');
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana', $this->json('Board'))));
	}

	public function testGuardActiveEnqueuesNothing(): void {
		$this->jobList->expects(self::never())->method('add');
		$file = $this->file('New Name.grafana', $this->json('Old'));
		// Run inside the guard → active() is true → bail (our own pull/stamp writes never reshuffle)
		$this->guard->run(fn () => $this->listener->handle(new NodeRenamedEvent($file, $file)));
	}

	public function testUnmanagedFileEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC, '')); // no uid
		$this->jobList->expects(self::never())->method('add');
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana', $this->json('Renamed'))));
	}

	public function testLinkModeEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_LINK));
		$this->jobList->expects(self::never())->method('add');
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana', $this->json('Renamed'))));
	}

	public function testNonDashboardFileEnqueuesNothing(): void {
		$this->jobList->expects(self::never())->method('add');
		$this->metadata->expects(self::never())->method('read');
		$this->listener->handle(new NodeWrittenEvent($this->file('notes.txt', $this->json('x'))));
	}

	// ── the collision counter travels one way and not the other ────────────────

	/**
	 * A RENAME IS A STATEMENT ABOUT THE WHOLE FILENAME. Renaming a file to carry a
	 * counter means the user wants a dashboard called `Fleet Health (1)`, and the title
	 * has to follow.
	 *
	 * Compared against the counter-STRIPPED stem — which is what this listener used to
	 * do — the two read as equal, nothing was enqueued, and the rename silently did
	 * nothing at all. The whole point of the filename being authoritative on a rename is
	 * that it is authoritative about every character of it.
	 */
	public function testRenamingAFileToCarryACounterPropagatesIt(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->expectEnqueue('title_from_filename');

		$before = $this->file('Fleet Health.grafana', $this->json('Fleet Health'));
		$after = $this->file('Fleet Health (1).grafana', $this->json('Fleet Health'));
		$this->listener->handle(new NodeRenamedEvent($before, $after));
	}

	/** And once it has followed, the same rename asks for nothing more. */
	public function testARenamedFileAlreadyTitledWithItsCounterEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->jobList->expects(self::never())->method('add');

		$file = $this->file('Fleet Health (1).grafana', $this->json('Fleet Health (1)'));
		$this->listener->handle(new NodeRenamedEvent($file, $file));
	}

	/**
	 * THE ONE PLACE A FILENAME AND A TITLE ARE MEANT TO DISAGREE. Grafana permits two
	 * dashboards in a folder to share a title and Nextcloud permits no two files to share
	 * a name, so a mirror of the second one legitimately sits at `Fleet Health (1).grafana`
	 * holding the title `Fleet Health`.
	 *
	 * Saving that file must enqueue NOTHING. Comparing a write against the counter-bearing
	 * name would queue a reconcile on every single save of every duplicate mirror — each
	 * one reaching the job only to discover the name it wants is the name it has.
	 */
	public function testSavingADuplicatesMirrorLeavesItsSuffixAlone(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->jobList->expects(self::never())->method('add');

		$this->listener->handle(new NodeWrittenEvent(
			$this->file('Fleet Health (1).grafana', $this->json('Fleet Health')),
		));
	}

	/** A real title edit on that same suffixed mirror is still a rename request. */
	public function testEditingTheTitleOfASuffixedMirrorStillEnqueues(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->expectEnqueue('filename_from_title');

		$this->listener->handle(new NodeWrittenEvent(
			$this->file('Fleet Health (1).grafana', $this->json('Capacity')),
		));
	}
}
