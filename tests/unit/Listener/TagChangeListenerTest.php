<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\TagChangeListener;
use OCA\GrafanaSync\Service\NextcloudTags;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TagSet;
use OCA\GrafanaSync\Service\TagSyncService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TagChangeListener} — a tag went on or came off something in the Files app.
 *
 * The behaviour worth pinning is that the event is treated as a NOTIFICATION, not as
 * data: it carries only the ids that moved, and only in one direction, so acting on
 * the delta would push three times for one gesture that added two tags and removed
 * one. The whole current set is re-read instead.
 */
#[CoversClass(TagChangeListener::class)]
final class TagChangeListenerTest extends TestCase {
	private TagSyncService $tagSync;
	private SyncGuard $guard;
	private ?Node $resolved = null;

	protected function setUp(): void {
		$this->tagSync = $this->createMock(TagSyncService::class);
		$this->guard = new SyncGuard();
	}

	public function testTaggingADashboardFileReachesTheDashboardPath(): void {
		$this->resolved = $this->file('Alpha.grafana');
		$this->tagSync->expects(self::once())->method('pushDashboard')->with(
			self::anything(),
			self::callback(fn (TagSet $t): bool => $t->equals(TagSet::of(['dns', 'prod']))),
		);
		$this->tagSync->expects(self::never())->method('pushFolder');

		$this->listener()->handle($this->assigned(11));
	}

	public function testTaggingAFolderReachesTheFolderPath(): void {
		$this->resolved = $this->createStub(Folder::class);
		$this->tagSync->expects(self::once())->method('pushFolder');
		$this->tagSync->expects(self::never())->method('pushDashboard');

		$this->listener()->handle($this->assigned(20));
	}

	/** An unassign is the same question with a different answer — the whole set, re-read. */
	public function testAnUnassignIsHandledTheSameWay(): void {
		$this->resolved = $this->file('Alpha.grafana');
		$this->tagSync->expects(self::once())->method('pushDashboard');

		$this->listener()->handle(new MapperEvent(MapperEvent::EVENT_UNASSIGN, 'files', '11', [3]));
	}

	/** Tagging a spreadsheet in a mapped folder is not this app's business. */
	public function testANonDashboardFileIsIgnored(): void {
		$this->resolved = $this->file('Budget.xlsx');
		$this->tagSync->expects(self::never())->method('pushDashboard');
		$this->tagSync->expects(self::never())->method('pushFolder');

		$this->listener()->handle($this->assigned(11));
	}

	/** Tags exist on other object types (comments, contacts); only files are ours. */
	public function testAnotherObjectTypeIsIgnored(): void {
		$this->resolved = $this->file('Alpha.grafana');
		$this->tagSync->expects(self::never())->method('pushDashboard');

		$this->listener()->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'comments', '11', [3]));
	}

	/** The pull assigns tags as it imports them; without this they bounce straight back. */
	public function testTheAppsOwnImportIsNotSentBack(): void {
		$this->resolved = $this->file('Alpha.grafana');
		$this->tagSync->expects(self::never())->method('pushDashboard');

		$this->guard->enter();
		try {
			$this->listener()->handle($this->assigned(11));
		} finally {
			$this->guard->leave();
		}
	}

	public function testANodeThatCannotBeResolvedIsSkipped(): void {
		$this->resolved = null;
		$this->tagSync->expects(self::never())->method('pushDashboard');
		$this->tagSync->expects(self::never())->method('pushFolder');

		$this->listener()->handle($this->assigned(11));
	}

	/** A tag click must never fail; the far side catches up on the next sync. */
	public function testAFailureDoesNotEscape(): void {
		$this->resolved = $this->file('Alpha.grafana');
		$this->tagSync->method('pushDashboard')->willThrowException(new \RuntimeException('unreachable'));

		$this->listener()->handle($this->assigned(11));

		self::assertTrue(true, 'the tag stayed applied in Nextcloud');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function listener(): TagChangeListener {
		$ncTags = $this->createStub(NextcloudTags::class);
		$ncTags->method('of')->willReturn(TagSet::of(['dns', 'prod']));

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$home = $this->createStub(Folder::class);
		$home->method('getFirstNodeById')->willReturnCallback(fn (): ?Node => $this->resolved);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		$root->method('getFirstNodeById')->willReturnCallback(fn (): ?Node => $this->resolved);

		return new TagChangeListener($this->tagSync, $ncTags, $this->guard, $root, $session, new NullLogger());
	}

	private function assigned(int $fileId): MapperEvent {
		return new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', (string)$fileId, [3]);
	}

	private function file(string $name): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn(11);
		$file->method('getName')->willReturn($name);
		return $file;
	}
}
