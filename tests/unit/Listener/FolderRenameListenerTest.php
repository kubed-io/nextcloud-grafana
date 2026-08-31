<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\FolderRenameListener;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see FolderRenameListener} — the first thing in this app that looks at a folder.
 *
 * The two invariants worth guarding are both about NOT acting: a MOVE must not be
 * mistaken for a rename (Nextcloud fires the same event for both), and a folder this
 * app never stamped must be left entirely alone.
 */
#[CoversClass(FolderRenameListener::class)]
final class FolderRenameListenerTest extends TestCase {
	private GrafanaClient $grafana;
	private SyncGuard $guard;
	private SyncNotifier $notifier;

	/** @var array<int,string> folder id → banked Grafana uid */
	private array $stamped = [];

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->guard = new SyncGuard();
		$this->notifier = $this->createMock(SyncNotifier::class);
	}

	public function testARenamedMirroredFolderIsRenamedInGrafana(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->grafana->expects(self::once())->method('renameFolder')->with('gf-team', 'Team B');

		$this->listener()->handle($this->renamed('/alice/files/Demo/Team A', '/alice/files/Demo/Team B', 20));
	}

	/**
	 * A MOVE IS NOT A RENAME, and Nextcloud does not distinguish them — internally a
	 * move IS a rename to a path with a different parent. Sending Grafana the new
	 * title would leave the folder in the wrong place on both sides.
	 */
	public function testAMovedFolderIsLeftToTheMoveHandling(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->grafana->expects(self::never())->method('renameFolder');

		$this->listener()->handle($this->renamed('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Team', 20));
	}

	/** A move that also renames is still a move, and still not this listener's. */
	public function testAMoveThatAlsoRenamesIsLeftAlone(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->grafana->expects(self::never())->method('renameFolder');

		$this->listener()->handle($this->renamed('/alice/files/Demo/Team', '/alice/files/Demo/Archive/Squad', 20));
	}

	/**
	 * A FOLDER THE USER MADE STAYS THEIRS. No uid means this app has never had
	 * anything to do with it, and renaming it is none of our business.
	 */
	public function testAnUnstampedFolderIsIgnored(): void {
		$this->stamped = [];
		$this->grafana->expects(self::never())->method('renameFolder');

		$this->listener()->handle($this->renamed('/alice/files/Demo/Notes', '/alice/files/Demo/Ideas', 40));
	}

	public function testAFileRenameIsNotThisListenersBusiness(): void {
		$this->grafana->expects(self::never())->method('renameFolder');

		$source = $this->createStub(File::class);
		$source->method('getPath')->willReturn('/alice/files/Demo/Old.grafana');
		$target = $this->createStub(File::class);
		$target->method('getPath')->willReturn('/alice/files/Demo/New.grafana');

		$this->listener()->handle($this->event($source, $target));
	}

	/**
	 * THE LOOP GUARD. The pull's tree reconcile follows a Grafana rename by renaming
	 * the Nextcloud folder — without this, that would bounce straight back to Grafana
	 * as a fresh rename.
	 */
	public function testTheAppsOwnRenameIsNotSentBack(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->grafana->expects(self::never())->method('renameFolder');

		$this->guard->enter();
		try {
			$this->listener()->handle($this->renamed('/alice/files/Demo/Team A', '/alice/files/Demo/Team B', 20));
		} finally {
			$this->guard->leave();
		}
	}

	/**
	 * The local rename has already happened, so a Grafana failure cannot be undone by
	 * throwing — and the uid is what lets the next sync settle the disagreement.
	 */
	public function testAGrafanaFailureDoesNotEscapeAndIsReported(): void {
		$this->stamped = [20 => 'gf-team'];
		$this->grafana->method('renameFolder')->willThrowException(new \RuntimeException('unreachable'));

		// The two sides now disagree, and a silent divergence is the thing this app
		// exists to avoid — the user is the only one who knows whether it matters.
		$this->notifier->expects(self::once())
			->method('failed')
			->with('alice', 20, 'Team B', self::isString());

		$this->listener()->handle($this->renamed('/alice/files/Demo/Team A', '/alice/files/Demo/Team B', 20));
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function listener(): FolderRenameListener {
		$folders = $this->createStub(FolderMetadata::class);
		$folders->method('uidOf')->willReturnCallback(fn (int $id): string => $this->stamped[$id] ?? '');

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FolderRenameListener(
			$folders,
			$this->grafana,
			$this->guard,
			$this->notifier,
			$session,
			new NullLogger(),
		);
	}

	private function renamed(string $from, string $to, int $id): NodeRenamedEvent {
		$source = $this->createStub(Folder::class);
		$source->method('getPath')->willReturn($from);
		$source->method('getName')->willReturn(basename($from));

		$target = $this->createStub(Folder::class);
		$target->method('getPath')->willReturn($to);
		$target->method('getName')->willReturn(basename($to));
		$target->method('getId')->willReturn($id);

		return $this->event($source, $target);
	}

	private function event(Node $source, Node $target): NodeRenamedEvent {
		$event = $this->createStub(NodeRenamedEvent::class);
		$event->method('getSource')->willReturn($source);
		$event->method('getTarget')->willReturn($target);
		return $event;
	}
}
