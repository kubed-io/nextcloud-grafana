<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Exception\GrafanaApiException;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\RecycleBin;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TeamFolderService;
use OCA\GrafanaSync\Service\TrashControl;
use OCA\GrafanaSync\Service\TrashedFile;
use OCA\GrafanaSync\Service\TrashedFolder;
use OCA\GrafanaSync\Service\TrashReconcileService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TrashReconcileService} — a mirror sits in the Nextcloud trash only for as long as
 * the dashboard it mirrors is out of its mapped folder, and both directions of that are
 * tested here.
 *
 * THE TESTS THAT MATTER ARE THE ONES WHERE NOTHING MOVES. Both passes run on the same
 * asymmetry — every way of being unsure has to end in leaving the entry alone — but they
 * are unsure about opposite things, and the stakes are not equal:
 *
 *   - {@see TrashReconcileService::reap()} and `reapFolders()` DESTROY. A wrong "yes"
 *     takes the last copy of a dashboard, and Grafana has no undo.
 *   - {@see TrashReconcileService::restoreFolders()} UNDOES A DELETE. A wrong "yes" puts
 *     back a file the user meant to be gone, for a dashboard still sitting in the bin.
 *
 * So the two halves of this file are near mirror images by design, and a case that only
 * appears on one side is worth asking about.
 */
#[CoversClass(TrashReconcileService::class)]
final class TrashReconcileServiceTest extends TestCase {
	private const MAPPING_ID = 'm-demo';
	private const BIN_UID = 'gf-bin';

	public function testAMirrorWhoseDashboardIsGoneIsPurged(): void {
		$purged = false;
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7, static function () use (&$purged): void {
				$purged = true;
			})],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(1, $service->reap($this->mapping()));
		self::assertTrue($purged, 'the trashed mirror was not purged');
	}

	/**
	 * PARKED IS NOT GONE, and this is the case the whole class exists to get right. A
	 * dashboard in the recycle-bin folder answers 200 — it is absent from the mapping's
	 * listing precisely BECAUSE it is parked, which is the state where its mirror
	 * belongs in the trash exactly where it is.
	 */
	public function testAParkedDashboardLeavesItsMirrorAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			$this->managed('dash-parked'),
			static fn (): array => ['meta' => ['folderUid' => 'gf-bin'], 'dashboard' => ['uid' => 'dash-parked']],
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/** An unreachable Grafana is not proof of anything. */
	public function testATransportFailureLeavesTheMirrorAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			$this->managed('dash-unknown'),
			static fn (): never => throw new \RuntimeException('connection refused'),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/** Nor is a 500 — only an explicit 404 counts. */
	public function testAServerErrorLeavesTheMirrorAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			$this->managed('dash-unknown'),
			static fn (): never => throw new GrafanaApiException('boom', 500),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	public function testAFileFromAnotherMappingIsNotOursToJudge(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			new ManagedFile('dash-gone', Mapping::MODE_SYNC, '1', 'h', 'm-other', ''),
			static fn (): never => throw new GrafanaApiException('gone', 404),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/**
	 * An `unmapped` file left its mapping deliberately, and its dashboard stopped being
	 * this app's business the moment it did — the same rule the user-driven purge states.
	 */
	public function testAnUnmappedFileIsLeftAlone(): void {
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7)],
			new ManagedFile('dash-gone', DashboardMetadata::MODE_UNMAPPED, '1', 'h', self::MAPPING_ID, ''),
			static fn (): never => throw new GrafanaApiException('gone', 404),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/** Somebody else's trashed spreadsheet, which never had anything to do with us. */
	public function testAForeignTrashEntryIsIgnored(): void {
		$service = $this->service(
			[$this->trashed('budget.xlsx', 7)],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('gone', 404),
		);

		self::assertSame(0, $service->reap($this->mapping()));
	}

	/**
	 * THE OTHER DIRECTION. A dashboard rescued out of the bin folder has its mirror
	 * brought back, rather than the pull writing a second file beside a trash entry for
	 * the one the user actually had.
	 */
	public function testARescuedDashboardHasItsMirrorRestored(): void {
		$restored = false;
		$service = $this->service(
			[$this->trashed('Fleet Health.grafana', 7, null, static function () use (&$restored): void {
				$restored = true;
			})],
			$this->managed('dash-rescued'),
			static fn (): array => ['meta' => [], 'dashboard' => ['uid' => 'dash-rescued']],
		);

		$service->restoreMirror($this->mapping(), 'dash-rescued');

		self::assertTrue($restored, 'the trashed mirror was not restored');
	}

	/** Nothing trashed for this dashboard: the caller writes a mirror as it always did. */
	public function testNoTrashedMirrorMeansNothingToRestore(): void {
		$service = $this->service([], $this->managed('dash-live'), static fn (): array => []);

		self::assertNull($service->restoreMirror($this->mapping(), 'dash-live'));
	}

	// ── trashed FOLDERS ───────────────────────────────────────────────────────

	/**
	 * A folder of nothing but finished mirrors goes WHOLE — one call that takes the
	 * folder with them, rather than emptying it and leaving an entry whose restore puts
	 * back an empty folder.
	 */
	public function testAFolderOfNothingButFinishedMirrorsIsPurgedWhole(): void {
		$entryPurged = false;
		$alpha = $this->trashed('Alpha.grafana', 7, static function (): void {
			self::fail('a mirror was purged individually when the whole entry should have gone');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Emptied', [$alpha], false, static function () use (&$entryPurged): void {
				$entryPurged = true;
			})],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(1, $service->reapFolders($this->mapping()));
		self::assertTrue($entryPurged, 'the trashed folder was not purged');
	}

	/**
	 * A PURGE IS A PURGE, AND THE SPREADSHEET STAYS. The mirror's dashboard was destroyed
	 * in Grafana, so the mirror goes; the file with no far side keeps the entry, and the
	 * entry is what the user restores to get it back.
	 *
	 * This is the case the class was built the WRONG way for first — sparing the whole
	 * entry, which left a `.grafana` in the trash whose dashboard no longer existed.
	 */
	public function testAFolderHoldingSomethingElseKeepsTheEntryAndStillPurgesTheMirror(): void {
		$entryPurged = false;
		$mirrorPurged = false;
		$alpha = $this->trashed('Alpha.grafana', 7, static function () use (&$mirrorPurged): void {
			$mirrorPurged = true;
		});
		$service = $this->folderService(
			[$this->trashedFolder('Spared', [$alpha], true, static function () use (&$entryPurged): void {
				$entryPurged = true;
			})],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(1, $service->reapFolders($this->mapping()));
		self::assertTrue($mirrorPurged, 'the mirror survived a purge of the dashboard it mirrored');
		self::assertFalse($entryPurged, 'the entry was destroyed, taking a file that has no far side');
	}

	/**
	 * ONE PARKED DASHBOARD KEEPS THE ENTRY, and its own mirror with it — parked is not
	 * gone, so there is nothing to finish. A finished sibling still goes.
	 */
	public function testAParkedDashboardKeepsTheEntryButNotItsFinishedSibling(): void {
		$entryPurged = false;
		$goneMirror = false;
		$gone = $this->trashed('Gone.grafana', 7, static function () use (&$goneMirror): void {
			$goneMirror = true;
		});
		$parked = $this->trashed('Parked.grafana', 8, static function (): void {
			self::fail('a parked dashboard\'s mirror was purged');
		});

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturnMap([
			[7, $this->managed('dash-gone')],
			[8, $this->managed('dash-parked')],
		]);
		$service = $this->folderService(
			[$this->trashedFolder('Mixed', [$gone, $parked], false, static function () use (&$entryPurged): void {
				$entryPurged = true;
			})],
			null,
			static fn (string $uid): array => $uid === 'dash-parked'
				? ['dashboard' => ['uid' => $uid]]
				: throw new GrafanaApiException('no such dashboard', 404),
			$metadata,
		);

		self::assertSame(1, $service->reapFolders($this->mapping()));
		self::assertTrue($goneMirror, 'the finished mirror was left behind');
		self::assertFalse($entryPurged, 'the entry went while a parked dashboard still needed it');
	}

	/** Another mapping's mirrors are that mapping's to judge, and they keep the entry. */
	public function testAFolderOfAnotherMappingsMirrorsIsLeftAlone(): void {
		$entryPurged = false;
		$theirs = $this->trashed('Alpha.grafana', 7, static function (): void {
			self::fail("another mapping's mirror was purged");
		});
		$service = $this->folderService(
			[$this->trashedFolder('Theirs', [$theirs], false, static function () use (&$entryPurged): void {
				$entryPurged = true;
			})],
			new ManagedFile('dash-gone', Mapping::MODE_SYNC, '1', 'hash', 'm-other', ''),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(0, $service->reapFolders($this->mapping()));
		self::assertFalse($entryPurged, "another mapping's folder was purged");
	}

	/** Grafana unreachable is not proof, so nothing is destroyed — the file pass's rule. */
	public function testAnUnreachableGrafanaPurgesNothingInAFolder(): void {
		$entryPurged = false;
		$alpha = $this->trashed('Alpha.grafana', 7, static function (): void {
			self::fail('a mirror was purged on an answer Grafana never gave');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Emptied', [$alpha], false, static function () use (&$entryPurged): void {
				$entryPurged = true;
			})],
			$this->managed('dash-unknown'),
			static fn (): never => throw new GrafanaApiException('connection refused', 0),
		);

		self::assertSame(0, $service->reapFolders($this->mapping()));
		self::assertFalse($entryPurged, 'a folder was purged on an answer Grafana never gave');
	}

	/**
	 * A trashed folder this app never mirrored into — someone's photos, say — holds no
	 * mirror to finish, so the pass has nothing to do with it. `holdsOtherFiles` is true
	 * because everything in it is something else, which is exactly the point.
	 */
	public function testAFolderWithNoMirrorsIsNotTouched(): void {
		$entryPurged = false;
		$service = $this->folderService(
			[$this->trashedFolder('Photos', [], true, static function () use (&$entryPurged): void {
				$entryPurged = true;
			})],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(0, $service->reapFolders($this->mapping()));
		self::assertFalse($entryPurged, 'a folder this app never mirrored into was purged');
	}

	// ── restoreFolders: a rescue in Grafana brings the trashed folder back ──────

	/**
	 * A folder of nothing but rescued mirrors comes back WHOLE — one call that brings
	 * the folder and its files together, rather than restoring each file and leaving an
	 * empty entry in the trash for the user to notice and clear.
	 *
	 * The exact mirror of {@see testAFolderOfNothingButFinishedMirrorsIsPurgedWhole()},
	 * which is the point: the two passes answer the same two-part question, and the only
	 * difference is which way the dashboard went.
	 */
	public function testAFolderOfNothingButRescuedMirrorsIsRestoredWhole(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail('a mirror was restored individually when the whole entry should have come back');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Revived', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-rescued'),
			static fn (): array => ['meta' => ['folderUid' => 'gf-revived']],
		);

		self::assertSame(1, $service->restoreFolders($this->mapping()));
		self::assertTrue($entryRestored, 'the trashed folder was not restored');
	}

	/**
	 * A GESTURE IN GRAFANA SPEAKS FOR DASHBOARDS AND NOTHING ELSE. The spreadsheet has no
	 * far side, so it stays where the user's own trash gesture put it — and the entry
	 * stays with it, holding only that. The rescued mirror still comes back.
	 *
	 * This is the half the sibling gets wrong in the purge direction and the reason
	 * `folders/restore.feature` stopped making the FOLDER its subject: after this there
	 * are two `Rescued`, a live one and a trash entry, and only files can be spoken about
	 * without contradiction.
	 */
	public function testAFolderHoldingSomethingElseKeepsTheEntryAndStillRestoresTheMirror(): void {
		$entryRestored = false;
		$mirrorRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function () use (&$mirrorRestored): void {
			$mirrorRestored = true;
		});
		$service = $this->folderService(
			[$this->trashedFolder('Rescued', [$alpha], true, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-rescued'),
			static fn (): array => ['meta' => ['folderUid' => 'gf-rescued']],
		);

		self::assertSame(1, $service->restoreFolders($this->mapping()));
		self::assertTrue($mirrorRestored, 'the rescued mirror was left in the trash');
		self::assertFalse($entryRestored, 'the entry came back, taking a file no Grafana gesture speaks for');
	}

	/**
	 * PARKED IS NOT RESCUED, and this is the case the pass exists to get right. A
	 * dashboard still sitting in the recycle-bin folder answers 200 with the BIN's uid —
	 * the state where its mirror belongs in the trash exactly where it is. Restoring it
	 * would undo a delete the user meant.
	 */
	public function testAStillParkedDashboardLeavesItsMirrorInTheTrash(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail("a still-parked dashboard's mirror was restored");
		});
		$service = $this->folderService(
			[$this->trashedFolder('Parked', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-parked'),
			static fn (): array => ['meta' => ['folderUid' => self::BIN_UID]],
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, 'a folder came back for a dashboard that is still in the bin');
	}

	/**
	 * ONE STILL-PARKED DASHBOARD KEEPS THE ENTRY, and its own mirror with it. A rescued
	 * sibling still comes back — the entry has to survive to hold what was left behind.
	 */
	public function testAStillParkedDashboardKeepsTheEntryButNotItsRescuedSibling(): void {
		$entryRestored = false;
		$rescuedMirror = false;
		$rescued = $this->trashed('Rescued.grafana', 7, null, static function () use (&$rescuedMirror): void {
			$rescuedMirror = true;
		});
		$parked = $this->trashed('Parked.grafana', 8, null, static function (): void {
			self::fail("a parked dashboard's mirror was restored");
		});

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturnMap([
			[7, $this->managed('dash-rescued')],
			[8, $this->managed('dash-parked')],
		]);
		$service = $this->folderService(
			[$this->trashedFolder('Mixed', [$rescued, $parked], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			null,
			static fn (string $uid): array => $uid === 'dash-parked'
				? ['meta' => ['folderUid' => self::BIN_UID]]
				: ['meta' => ['folderUid' => 'gf-mixed']],
			$metadata,
		);

		self::assertSame(1, $service->restoreFolders($this->mapping()));
		self::assertTrue($rescuedMirror, 'the rescued mirror was left in the trash');
		self::assertFalse($entryRestored, 'the entry came back while a parked dashboard still needed it');
	}

	/**
	 * A 404 IS reap()'S BUSINESS, NOT THIS PASS'S. The dashboard was destroyed rather
	 * than rescued, so there is nothing to come back to — and restoring the mirror would
	 * put back a file that mirrors nothing.
	 */
	public function testADestroyedDashboardRestoresNothing(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail('a mirror was restored for a dashboard that no longer exists');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Gone', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-gone'),
			static fn (): never => throw new GrafanaApiException('no such dashboard', 404),
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, 'a folder came back for a dashboard Grafana no longer has');
	}

	/** Grafana unreachable is not proof either way, so nothing moves. */
	public function testAnUnreachableGrafanaRestoresNothing(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail('a mirror was restored on an answer Grafana never gave');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Unknown', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-unknown'),
			static fn (): never => throw new GrafanaApiException('connection refused', 0),
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, 'a folder came back on an answer Grafana never gave');
	}

	/**
	 * A PARTIAL BODY IS NOT THE GRAFANA ROOT. Grafana has answered 200 with no `meta`
	 * before now, and reading that as "folderUid is empty, so it is out of the bin" would
	 * restore every trashed mirror in the mapping on one bad response.
	 */
	public function testAResponseWithNoMetaRestoresNothing(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail('a mirror was restored on a response that said nothing about where it is');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Partial', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-partial'),
			static fn (): array => ['dashboard' => ['uid' => 'dash-partial']],
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, 'a folder came back on a body that never said where the dashboard is');
	}

	/**
	 * AN EMPTY `folderUid` IS NOT PROOF EITHER, and it is the same bad response wearing a
	 * different shape — a body with `meta` but nothing usable in it reduces to the same
	 * empty string as a body with no `meta` at all.
	 *
	 * It also names the Grafana root, which under a root mapping is somewhere a rescuer
	 * could really put a dashboard. Given up on purpose: the root says nothing about the
	 * trashed SUBFOLDER this pass would bring back, so refusing is the more correct
	 * answer as well as the safe one.
	 */
	public function testAnEmptyFolderUidRestoresNothing(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail('a mirror was restored on a response that named no folder');
		});
		$service = $this->folderService(
			[$this->trashedFolder('Rootless', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-rootless'),
			static fn (): array => ['meta' => ['folderUid' => '']],
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, 'a folder came back on a response that named no folder');
	}

	/**
	 * BIN OFF MEANS NOTHING WAS EVER PARKED, so there is nothing to be rescued from and
	 * no signal to read — the dashboards were destroyed at trash time. A bin that is on
	 * but unresolvable answers the same way for the opposite reason: the one thing this
	 * pass must be able to recognise is the bin itself.
	 */
	public function testAnUnresolvableBinRestoresNothing(): void {
		$entryRestored = false;
		$alpha = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail('a mirror was restored without knowing where the bin is');
		});
		$service = $this->folderService(
			[$this->trashedFolder('NoBin', [$alpha], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			$this->managed('dash-rescued'),
			static function (): never {
				self::fail('Grafana was asked about a dashboard before the bin was resolved');
			},
			null,
			null,
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, 'a folder came back with no bin to have been rescued from');
	}

	/** Another mapping's mirrors are that mapping's to judge, and they keep the entry. */
	public function testAFolderOfAnotherMappingsMirrorsIsNotRestored(): void {
		$entryRestored = false;
		$theirs = $this->trashed('Alpha.grafana', 7, null, static function (): void {
			self::fail("another mapping's mirror was restored");
		});
		$service = $this->folderService(
			[$this->trashedFolder('Theirs', [$theirs], false, static function (): void {
			}, static function () use (&$entryRestored): void {
				$entryRestored = true;
			})],
			new ManagedFile('dash-rescued', Mapping::MODE_SYNC, '1', 'hash', 'm-other', ''),
			static fn (): array => ['meta' => ['folderUid' => 'gf-theirs']],
		);

		self::assertSame(0, $service->restoreFolders($this->mapping()));
		self::assertFalse($entryRestored, "another mapping's folder was restored");
	}

	// ── harness ────────────────────────────────────────────────────────────────

	/**
	 * The recycle bin, resolved or not.
	 *
	 * Null is BIN OFF — nothing was ever parked, so there is nothing to be rescued
	 * from. {@see TrashReconcileService::restoreFolders} treats a bin it cannot resolve
	 * the same way, which is the case worth having a stub for.
	 */
	private function bin(?string $uid): RecycleBin {
		$bin = $this->createStub(RecycleBin::class);
		$bin->method('activeFolderUid')->willReturn($uid);
		return $bin;
	}

	private function mapping(): Mapping {
		return Mapping::fromArray([
			'id' => self::MAPPING_ID,
			'grafana_folder_uid' => 'gf-demo',
			'nc_folder' => 'Demo',
			'mode' => Mapping::MODE_SYNC,
		]);
	}

	private function managed(string $uid): ManagedFile {
		return new ManagedFile($uid, Mapping::MODE_SYNC, '1', 'hash', self::MAPPING_ID, '');
	}

	private function trashed(string $name, int $fileId, ?\Closure $purge = null, ?\Closure $restore = null): TrashedFile {
		return new TrashedFile(
			$fileId,
			$name,
			$purge ?? static function (): void {
			},
			$restore ?? static function (): void {
			},
		);
	}

	/**
	 * @param list<TrashedFile> $dashboards
	 */
	private function trashedFolder(
		string $name,
		array $dashboards,
		bool $holdsOtherFiles,
		\Closure $purge,
		?\Closure $restore = null,
	): TrashedFolder {
		return new TrashedFolder($name, $dashboards, $holdsOtherFiles, $purge, $restore ?? static function (): void {
		});
	}

	/**
	 * @param list<TrashedFolder> $inTrash
	 * @param ManagedFile|null $managed what every file id reads back as, or null when the
	 *                                  test supplies its own $metadata per id
	 * @param \Closure():mixed $readDashboard what Grafana answers for the uid under test
	 */
	private function folderService(
		array $inTrash,
		?ManagedFile $managed,
		\Closure $readDashboard,
		?DashboardMetadata $metadata = null,
		?string $binUid = self::BIN_UID,
	): TrashReconcileService {
		$trash = $this->createStub(TrashControl::class);
		$trash->method('listTrashedFolders')->willReturn($inTrash);

		if ($metadata === null) {
			$metadata = $this->createStub(DashboardMetadata::class);
			$metadata->method('read')->willReturn($managed);
		}

		$grafana = $this->createStub(GrafanaClient::class);
		$grafana->method('readDashboard')->willReturnCallback($readDashboard);

		$teamFolders = $this->createStub(TeamFolderService::class);
		$teamFolders->method('resolveActorUid')->willReturn('admin');

		return new TrashReconcileService(
			$this->createStub(IRootFolder::class),
			$trash,
			$metadata,
			$this->createStub(FolderMetadata::class),
			$grafana,
			$this->bin($binUid),
			$teamFolders,
			new SyncGuard(),
			new NullLogger(),
		);
	}

	/**
	 * @param list<TrashedFile> $inTrash
	 * @param \Closure():mixed $readDashboard what Grafana answers for the uid under test
	 */
	private function service(
		array $inTrash,
		ManagedFile $managed,
		\Closure $readDashboard,
		?string $binUid = self::BIN_UID,
	): TrashReconcileService {
		$trash = $this->createStub(TrashControl::class);
		$trash->method('listTrashed')->willReturn($inTrash);

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturn($managed);

		$grafana = $this->createStub(GrafanaClient::class);
		$grafana->method('readDashboard')->willReturnCallback($readDashboard);

		$teamFolders = $this->createStub(TeamFolderService::class);
		$teamFolders->method('resolveActorUid')->willReturn('admin');

		return new TrashReconcileService(
			$this->createStub(IRootFolder::class),
			$trash,
			$metadata,
			$this->createStub(FolderMetadata::class),
			$grafana,
			$this->bin($binUid),
			$teamFolders,
			new SyncGuard(),
			new NullLogger(),
		);
	}
}
