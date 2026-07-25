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
		$file = $this->file('New Name.grafana.json', $this->json('Old'));
		$this->listener->handle(new NodeRenamedEvent($this->file('Old.grafana.json', $this->json('Old')), $file));
	}

	public function testRenameWithMatchingTitleEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->jobList->expects(self::never())->method('add');
		$file = $this->file('Same.grafana.json', $this->json('Same'));
		$this->listener->handle(new NodeRenamedEvent($file, $file));
	}

	public function testTitleEditWithMismatchEnqueuesFilenameFromTitle(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->expectEnqueue('filename_from_title');
		// filename stem "Board", JSON title now "Renamed" → mismatch
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana.json', $this->json('Renamed'))));
	}

	public function testTitleEditMatchingFilenameEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed());
		$this->jobList->expects(self::never())->method('add');
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana.json', $this->json('Board'))));
	}

	public function testGuardActiveEnqueuesNothing(): void {
		$this->jobList->expects(self::never())->method('add');
		$file = $this->file('New Name.grafana.json', $this->json('Old'));
		// Run inside the guard → active() is true → bail (our own pull/stamp writes never reshuffle)
		$this->guard->run(fn () => $this->listener->handle(new NodeRenamedEvent($file, $file)));
	}

	public function testUnmanagedFileEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_SYNC, '')); // no uid
		$this->jobList->expects(self::never())->method('add');
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana.json', $this->json('Renamed'))));
	}

	public function testLinkModeEnqueuesNothing(): void {
		$this->metadata->method('read')->willReturn($this->managed(Mapping::MODE_LINK));
		$this->jobList->expects(self::never())->method('add');
		$this->listener->handle(new NodeWrittenEvent($this->file('Board.grafana.json', $this->json('Renamed'))));
	}

	public function testNonDashboardFileEnqueuesNothing(): void {
		$this->jobList->expects(self::never())->method('add');
		$this->metadata->expects(self::never())->method('read');
		$this->listener->handle(new NodeWrittenEvent($this->file('notes.txt', $this->json('x'))));
	}
}
