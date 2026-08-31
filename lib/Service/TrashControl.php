<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\FileInfo;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every conversation this app has with the Nextcloud trash: making a delete permanent,
 * reading what is in there, and destroying one entry.
 *
 * ## MAKING A DELETE PERMANENT
 *
 * The one gesture in this app where the Nextcloud trash is the wrong answer.
 *
 * A `link` file is a read-only projection of a dashboard. When the dashboard leaves the
 * mirrored Grafana folder — deleted there, or moved elsewhere — the pointer stops
 * meaning anything, and putting it in the trash would offer the user a restore that
 * reconnects nothing: there is nothing to restore FROM. `sync` files are the opposite
 * and keep the trash, because their file IS the dashboard's content.
 *
 * ## `pauseTrash()` IS THE SUPPORTED BYPASS, AND THE ONLY ONE
 *
 * `Files_Trashbin\Storage::unlink()` consults a private `$trashEnabled`, and
 * `Trashbin::move2trash()` offers no opt-out — neither is reachable from an app. The
 * one public seam is {@see ITrashManager::pauseTrash()}: `TrashManager::moveToTrash()`
 * returns false while paused, and the storage wrapper then performs a real unlink.
 *
 * It is also **backend-agnostic**, which is why it is worth reaching for rather than
 * calling `Trashbin::delete()` afterwards to undo a trashing we just did. Every trash
 * backend registers with the same manager, so this covers a Team Folder's trash exactly
 * as it covers a user's home — and Team Folders are what this app's mappings actually
 * use. A `Trashbin::`-based purge would have quietly missed them.
 *
 * ## RESOLVED LAZILY, BECAUSE THE TRASH IS AN APP
 *
 * `files_trashbin` is shipped but removable, and `ITrashManager` lives in ITS namespace,
 * not OCP — a constructor dependency would make this app fail to boot on an instance
 * without it. When it is absent there is no trash to pause and `delete()` is already
 * permanent, so the fallback is simply to run the callback.
 *
 * ## THE TRASH APP'S TYPES STOP HERE
 *
 * {@see listTrashed} and the two operations it hands back are the reading half, used by
 * {@see TrashReconcileService} to reap mirrors whose dashboard is gone for good and to
 * bring back the ones whose dashboard was rescued from the bin. They answer in
 * {@see TrashedFile}, this app's own shape, for the reason above: a signature naming
 * `ITrashItem` is a file the unit suite cannot load and psalm cannot resolve. One class
 * pays that cost; everything downstream is ordinary code.
 *
 * Both halves are also **backend-agnostic in the same way and for the same reason** —
 * `ITrashManager` aggregates every registered backend, so a Team Folder's trash is
 * listed and purged exactly like a user's home. That is not a nicety here: Team Folders
 * are what this app's mappings actually use, and every trash bug this app has had came
 * from reaching for a home-storage-only mechanism.
 */
final class TrashControl {
	/**
	 * How deep the reconcile will descend into a trashed folder.
	 *
	 * Far deeper than any mirrored tree. Reaching it means the tree is wrong rather than
	 * the limit, and stopping leaves the entry alone — the safe direction for a
	 * reconcile, which is the opposite of {@see ExistingDashboards}, where not knowing
	 * has to refuse because the next step destroys something.
	 */
	private const MAX_TRASH_DEPTH = 32;

	public function __construct(
		private ContainerInterface $container,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run $fn with the trash paused, so any delete inside it is permanent.
	 *
	 * The pause is process-wide while it is held, so $fn must be exactly the delete and
	 * nothing else — `finally` restores it even if the delete throws, because leaving
	 * the trash paused would silently make every later delete on the request
	 * unrecoverable, including the user's own.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function withoutTrash(callable $fn): mixed {
		$manager = $this->trashManager();
		if ($manager === null) {
			return $fn();
		}
		$manager->pauseTrash();
		try {
			return $fn();
		} finally {
			$manager->resumeTrash();
		}
	}

	/**
	 * Every file in the root of $uid's trash — their home trash AND the trash of every
	 * Team Folder they can see, because `ITrashManager::listTrashRoot()` folds in each
	 * registered backend.
	 *
	 * ROOT ONLY, DELIBERATELY. A file trashed on its own is a root item; one that went
	 * in as part of a deleted FOLDER is nested inside it, and this does not recurse
	 * into those. Descending would mean destroying single files out of the middle of a
	 * folder the user trashed as a unit, leaving them a restore that silently comes
	 * back incomplete. A folder is restored or purged whole, and its contents settle
	 * on the pull that follows.
	 *
	 * Cost is one query per backend — a directory listing of `files_trashbin/files`
	 * for the home, one indexed lookup for the Team Folders — not one per entry. The
	 * caller filters the result by name and metadata before spending anything on it.
	 *
	 * Answers `[]` for an unknown user, or when there is no trash app at all: an
	 * instance without `files_trashbin` cannot have a trashed mirror to reap.
	 *
	 * ## THE FILESYSTEM HAS TO BE SET UP FIRST, OR A TEAM FOLDER'S TRASH IS INVISIBLE
	 *
	 * `listTrashRoot()` reads nothing from groupfolders' backend until the user's mounts
	 * exist — it answers an EMPTY LIST rather than failing, which is the worst possible
	 * shape for a bug: the reconcile then decides there is nothing to reap and nothing to
	 * bring back, reports zero, and looks like it is working.
	 *
	 * Measured on the live instance: without this the same trash answered 0 entries; with
	 * it, 4. The pull happens to satisfy it already — `StorageService::ensureFolder()`
	 * sets the actor's filesystem up before any of this runs — but a feature standing on
	 * a side effect of an unrelated call is a regression waiting for the day that call
	 * moves. It is idempotent and it is one line, so it is stated here rather than
	 * assumed. {@see TeamFolderService::getWritableFolder} does the same for the same
	 * reason.
	 *
	 * @return list<TrashedFile>
	 */
	public function listTrashed(string $uid): array {
		$manager = $this->trashManager();
		if ($manager === null) {
			return [];
		}
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}
		\OC_Util::setupFS($uid);

		try {
			$items = $manager->listTrashRoot($user);
		} catch (\Throwable $e) {
			// A trash we cannot read is not a reason to fail the pull that asked. The
			// reconcile simply finds nothing this time round and runs again next tick.
			$this->logger->warning('grafana_sync: could not list the trash', [
				'app' => Application::APP_ID,
				'user' => $uid,
				'exception' => $e,
			]);
			return [];
		}

		$out = [];
		foreach ($items as $item) {
			if (!$item instanceof ITrashItem || $item->getType() !== FileInfo::TYPE_FILE) {
				continue;
			}
			// `FileInfo::getId()` is `int|null`. Without an id there is no metadata to
			// read, so there is no way to know whether this is one of ours — and a file
			// this app cannot identify is never a file it may destroy.
			$fileId = $item->getId();
			if ($fileId === null) {
				continue;
			}
			$out[] = new TrashedFile(
				$fileId,
				// The ORIGINAL name. `getName()` answers the trash's own spelling, which
				// carries the deletion timestamp AFTER the extension — the exact shape
				// that made `str_ends_with($name, '.grafana')` false for every trashed file
				// and left the purge step dead for a whole release.
				basename($item->getOriginalLocation()),
				function () use ($manager, $item): void {
					$manager->removeItem($item);
				},
				function () use ($manager, $item, $user): void {
					// AS THE ITEM'S OWNER, unlike the purge beside it — see {@see asUser}.
					$this->asUser($user, static function () use ($manager, $item): void {
						$manager->restoreItem($item);
					});
				},
			);
		}
		return $out;
	}

	/**
	 * The FOLDERS in $uid's trash, each with what it holds already sorted.
	 *
	 * The companion to {@see listTrashed()}, which answers files only — it skips every
	 * entry that is not `TYPE_FILE`, so a trashed folder was invisible to everything
	 * downstream. Trashing a folder produces ONE entry named after the folder, so the
	 * mirrors inside it can only be reached by descending into it.
	 *
	 * @return list<TrashedFolder>
	 */
	public function listTrashedFolders(string $uid): array {
		$manager = $this->trashManager();
		if ($manager === null) {
			return [];
		}
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}
		\OC_Util::setupFS($uid);

		try {
			$items = $manager->listTrashRoot($user);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not list the trash for folders', [
				'app' => Application::APP_ID,
				'user' => $uid,
				'exception' => $e,
			]);
			return [];
		}

		$out = [];
		foreach ($items as $item) {
			if (!$item instanceof ITrashItem || $item->getType() === FileInfo::TYPE_FILE) {
				continue;
			}
			[$mirrors, $other] = $this->inspect($item, 0, $user);
			$out[] = new TrashedFolder(
				basename($item->getOriginalLocation()),
				$mirrors,
				$other,
				function () use ($manager, $item): void {
					$manager->removeItem($item);
				},
				// AS THE USER, for the reason {@see asUser()} spells out: the home trash's
				// restore reads the SESSION's user rather than taking one, and a pull has
				// no session. The purge above needs no such thing — `removeItem()` takes
				// the item and nothing else — which is why only this one is wrapped.
				function () use ($manager, $item, $user): void {
					$this->asUser($user, static function () use ($manager, $item): void {
						$manager->restoreItem($item);
					});
				},
			);
		}
		return $out;
	}

	/**
	 * Everything under one trashed folder, as [the mirrors, holds anything else].
	 *
	 * ## EVERY WAY OF NOT KNOWING ANSWERS "SOMETHING ELSE IS IN HERE"
	 *
	 * Past the ceiling, on an unreadable subtree, on a child this app cannot even type —
	 * the answer is `[[], true]`, which makes the folder un-purgeable. Returning "no ids
	 * and nothing else" would say the folder is EMPTY of anything worth keeping, and the
	 * caller would destroy it. Not knowing has to veto, the same asymmetry
	 * {@see ExistingDashboards} runs on and for the same reason: the failure that
	 * destroys something is the one worth being wrong about.
	 *
	 * ## THE BACKEND DISPATCHES, NOT THE MANAGER
	 *
	 * `ITrashItem::getTrashBackend()->listTrashFolder()` — a Team Folder's trash and the
	 * home trash are different backends, and the item is the only thing that knows which
	 * one it came out of.
	 *
	 * ## AND THE NAME IS THE ORIGINAL ONE, NEVER `getName()`
	 *
	 * The same trap {@see listTrashed()} spells out: the trash's own spelling carries the
	 * deletion stamp AFTER the extension (`Alpha.grafana.d1788058484`), so
	 * `isDashboardName()` is false for every trashed mirror there has ever been. Every
	 * `.grafana` in here would be counted as "some other file", making the folder
	 * permanently un-purgeable — a silent no-op wearing the shape of caution. The sibling
	 * walked straight into it; this is the fix, ported.
	 *
	 * EACH MIRROR CARRIES ITS OWN PURGE, because the reconcile destroys them one at a
	 * time when the entry has to survive — a folder holding a spreadsheet keeps its entry
	 * and still loses the dashboard whose far side is gone.
	 *
	 * @return array{0: list<TrashedFile>, 1: bool}
	 */
	private function inspect(ITrashItem $folder, int $depth, IUser $user): array {
		if ($depth >= self::MAX_TRASH_DEPTH) {
			$this->logger->warning('grafana_sync: a trashed folder was deeper than the reconcile will walk', [
				'app' => Application::APP_ID,
				'folder' => $folder->getName(),
			]);
			return [[], true];
		}

		try {
			$children = $folder->getTrashBackend()->listTrashFolder($folder);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not look inside a trashed folder', [
				'app' => Application::APP_ID,
				'folder' => $folder->getName(),
				'exception' => $e,
			]);
			return [[], true];
		}

		$found = [];
		$other = false;
		foreach ($children as $child) {
			if (!$child instanceof ITrashItem) {
				$other = true;
				continue;
			}
			if ($child->getType() === FileInfo::TYPE_FOLDER) {
				[$nested, $nestedOther] = $this->inspect($child, $depth + 1, $user);
				foreach ($nested as $mirror) {
					$found[] = $mirror;
				}
				$other = $other || $nestedOther;
				continue;
			}
			$id = $child->getId();
			$name = basename($child->getOriginalLocation());
			if ($id !== null && FilenameCodec::isDashboardName($name)) {
				$found[] = new TrashedFile(
					$id,
					$name,
					function () use ($child): void {
						$child->getTrashBackend()->removeItem($child);
					},
					function () use ($child, $user): void {
						$this->asUser($user, static function () use ($child): void {
							$child->getTrashBackend()->restoreItem($child);
						});
					},
				);
				continue;
			}
			$other = true;
		}

		return [$found, $other];
	}

	/**
	 * Run $fn with $user as the active user, then put back whoever was there.
	 *
	 * ## THE HOME TRASH RESTORES WHOEVER IS LOGGED IN, NOT WHOEVER YOU ASK ABOUT
	 *
	 * `Trashbin::restore()` takes no user: it reads `OC_User::getUser()` — which is the
	 * SESSION's `user_id` — and builds its `View` on that. A pull has no session, so the
	 * call threw `Tried to restore a file while not logged in` and the mirror of an
	 * unarchived workflow stayed in the trash. Found in CI, in the app's own log, because
	 * the failure was caught and logged rather than swallowed.
	 *
	 * `IUserSession::setUser()` is the public seam for this and it writes exactly that
	 * key. The previous user is restored in `finally`, so an inline pull triggered from
	 * the admin's browser ends the request with the session it began with.
	 *
	 * ## ONLY THE RESTORE NEEDS IT
	 *
	 * The purge does not, and is deliberately left alone: `Trashbin::delete()` is passed
	 * the uid explicitly, and groupfolders' backend reads `$item->getUser()` for both
	 * operations. Proven rather than assumed — the purge worked in the same CI run that
	 * caught this. Mutating the session where it is not needed would be a bigger blast
	 * radius for no gain.
	 *
	 * `setupFS` comes along because the `View` needs the user's mounts, and it is the
	 * same call {@see TeamFolderService::getWritableFolder} already makes for the actor
	 * on every pull — this leaves the filesystem no more re-pointed than the pull that
	 * carried it already did.
	 */
	private function asUser(IUser $user, callable $fn): void {
		$previous = $this->userSession->getUser();
		$this->userSession->setUser($user);
		\OC_Util::setupFS($user->getUID());
		try {
			$fn();
		} finally {
			$this->userSession->setUser($previous);
		}
	}

	/**
	 * Is there a Nextcloud trash at all?
	 *
	 * `files_trashbin` is a REMOVABLE app, and an instance without it deletes files
	 * outright — there is no second step and nothing to restore from. That changes what a
	 * delete MEANS to this app, which is why the answer is public: the Grafana recycle bin
	 * only ever made sense as the far half of a Nextcloud trashing, so with no trash to
	 * pair with, parking a dashboard would strand it behind a file that no longer exists.
	 * {@see \OCA\GrafanaSync\Listener\DeleteToGrafanaListener} reads this to decide.
	 */
	public function isAvailable(): bool {
		return $this->trashManager() !== null;
	}

	/** The trash manager, or null when `files_trashbin` is not installed/enabled. */
	private function trashManager(): ?ITrashManager {
		if (!interface_exists(ITrashManager::class)) {
			return null;
		}
		try {
			$manager = $this->container->get(ITrashManager::class);
		} catch (\Throwable $e) {
			$this->logger->debug('grafana_sync: no trash manager available; a delete will be permanent anyway', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return null;
		}
		return $manager instanceof ITrashManager ? $manager : null;
	}
}
