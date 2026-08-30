<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * The `.grafana` files already sitting under a folder a mapping is about to claim
 * (`mapping/create.feature`).
 *
 * ## WHAT THIS PREVENTS IS A STATE THE APP HAS NO ANSWER FOR
 *
 * A `link` mirror is a pointer at a dashboard Grafana owns. A dashboard file that
 * is nobody's mirror sitting inside a link mapping is a contradiction, and every
 * rule that reads one has to guess which it is — {@see MappingTeardownService}
 * asks each file its mode and keeps the ones that are not links, while
 * `mapping/delete.feature` says a link mapping's dashboards all go. Both are right
 * about a tree that should not exist.
 *
 * It is not hypothetical, and the sibling reached it on a live instance in three
 * steps: a folder mapped `sync`, the mapping removed (leaving real dashboard files
 * behind, unmapped), then re-mapped `link` over them. CI could not have caught it,
 * because every scenario builds a clean tree.
 *
 * So the contradiction is designed out at the only moment it can be created.
 *
 * ## PURGED, NOT TRASHED — THE ONE PLACE THIS APP DESTROYS SOMETHING
 *
 * A trashed file offers a restore, and restoring INTO a link mapping is already
 * ruled out — a link folder refuses authoring, so there is nowhere for the bytes to
 * go. Rather than invent an answer for a restore that cannot work, the files never
 * reach the trash.
 *
 * Which is why {@see under()} exists as its own call. Nothing here purges without
 * the admin having been told HOW MANY and that they are not recoverable, and the
 * count has to come from the same walk that does the deleting, or the number in the
 * warning is a different question's answer.
 *
 * ## IT RUNS UNDER THE GUARD, OR IT DELETES THE DASHBOARDS IN GRAFANA
 *
 * The files this destroys are `unmapped`, and an unmapped file KEEPS its
 * `grafana_uid` — that is the whole point of the state ({@see MotionService}). So
 * each `delete()` here fires the same `BeforeNodeDeletedEvent` a person's delete
 * does, and {@see \OCA\GrafanaSync\Listener\DeleteToGrafanaListener} answers that by
 * deleting the dashboard in Grafana (bin off) or parking it (bin on). Without
 * {@see SyncGuard} raised, clearing a folder so it can mirror a Grafana folder would
 * destroy dashboards in the very folder it is about to mirror.
 *
 * ## ONLY `link`, AND ONLY UNMAPPED
 *
 * A `sync` mapping pushes what it finds up to Grafana, so nothing is destroyed and
 * nothing is confirmed — the caller decides that, not this class. And a tree that
 * already belongs to a mapping never reaches here: a folder in use is refused first
 * ({@see MappingService::assertNcFolderUnique()}). So "no `.grafana` anywhere in the
 * tree" holds implicitly for every mapped tree without being checked.
 */
final class ExistingDashboards {
	/**
	 * How deep the sweep will go.
	 *
	 * A ceiling rather than a limit anyone should reach: a mapped tree mirrors a
	 * Grafana folder, and Grafana's own nesting is far shallower than this. It is here
	 * so a symlink loop or a pathological tree cannot spin forever while an admin waits
	 * on a form.
	 *
	 * REACHING IT REFUSES THE MAPPING, it does not quietly end the walk. See
	 * {@see dashboardsBelow()}: not knowing what is down there is the same answer as
	 * not being able to read it, and both have to fail closed or the guard has a door
	 * in it.
	 */
	private const MAX_DEPTH = 32;

	public function __construct(
		private readonly StorageService $storage,
		private readonly TrashControl $trash,
		private readonly SyncGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Every `.grafana` at or below the folder this mapping would claim.
	 *
	 * ANSWERS `[]` FOR A FOLDER THAT IS NOT THERE, which is the ordinary case: most
	 * mappings are made against a name nothing has used yet, and a folder that does
	 * not exist holds nothing to warn about.
	 *
	 * COUNTS EVERY `.grafana`, TRACKED OR NOT, and that is deliberate. The mapped
	 * tree is about to be filled from Grafana, and a dashboard file the app has never
	 * heard of is no more able to survive there than one it has: both are documents in
	 * a folder that may only hold pointers. The distinction the pull draws between
	 * mirrored and untracked is about ownership, and ownership is not the question here.
	 *
	 * @return list<File>
	 */
	public function under(Mapping $mapping): array {
		$root = $this->storage->findFolder($mapping);

		return $root === null ? [] : $this->dashboardsBelow($root, 0);
	}

	/**
	 * Destroy them, permanently, and answer how many went.
	 *
	 * NEVER THROWS. A file that will not delete is logged and stepped over: the
	 * mapping this clears the way for has already been created, and failing here would
	 * leave the admin with a mapping they cannot see and an error they cannot act on.
	 * The survivor is visible in the folder and in the log.
	 *
	 * @param list<File> $dashboards from {@see under()}, so the count the admin
	 *                               acknowledged is the set that is destroyed
	 */
	public function purge(array $dashboards): int {
		if ($dashboards === []) {
			return 0;
		}

		$purged = 0;

		// ONE GUARD FOR THE WHOLE SWEEP. See the class docblock: without it every
		// delete below reaches Grafana and destroys the dashboard the file names.
		$this->guard->run(function () use ($dashboards, &$purged): void {
			foreach ($dashboards as $dashboard) {
				$path = $dashboard->getPath();

				try {
					$this->trash->withoutTrash(static function () use ($dashboard): void {
						$dashboard->delete();
					});
				} catch (\Throwable $e) {
					$this->logger->warning('grafana_sync: could not purge a dashboard file to make way for a link mapping', [
						'app' => Application::APP_ID,
						'file' => $path,
						'exception' => $e,
					]);

					continue;
				}

				$purged++;
				$this->logger->info('grafana_sync: purged a dashboard file to make way for a link mapping', [
					'app' => Application::APP_ID,
					'file' => $path,
				]);
			}
		});

		return $purged;
	}

	/**
	 * @return list<File>
	 */
	private function dashboardsBelow(Folder $folder, int $depth): array {
		if ($depth >= self::MAX_DEPTH) {
			// A FOLDER TOO DEEP TO SCAN IS NOT AN EMPTY FOLDER, which is the identical
			// reasoning to the unreadable case below — and this branch answered `[]`
			// while that one threw, so the class failed closed on one way of not knowing
			// and open on the other. Copilot caught it on the PR that added it.
			//
			// Answering "nothing found" here lets a link mapping be created over
			// dashboard files that really are there, just deeper than the ceiling. That
			// is the exact state this class exists to prevent, reached through the one
			// door left unlocked.
			$this->logger->error('grafana_sync: a folder tree was too deep to scan for existing dashboard files', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'depth' => $depth,
			]);

			throw new \InvalidArgumentException(sprintf(
				'"%s" is nested more than %d levels deep, so it is not possible to tell whether it '
				. 'already holds dashboard files. Nothing was changed — map a folder nearer the top, '
				. 'or flatten the tree.',
				$folder->getName(),
				self::MAX_DEPTH,
			));
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable $e) {
			// AN UNREADABLE FOLDER IS NOT AN EMPTY ONE. Answering "nothing found" here
			// would let the mapping be created over dashboard files nobody could see,
			// which is precisely the state this class exists to prevent.
			//
			// SO IT FAILS CLOSED, as an `InvalidArgumentException` — the type both front
			// doors already turn into a refusal the admin can read, rather than a 500
			// from the panel and a stack trace from `occ`. A folder that cannot be listed
			// is a folder nothing should be mapped over.
			$this->logger->error('grafana_sync: could not read a folder while looking for existing dashboard files', [
				'app' => Application::APP_ID,
				'folder' => $folder->getPath(),
				'exception' => $e,
			]);

			throw new \InvalidArgumentException(sprintf(
				'The contents of "%s" could not be read, so it is not possible to tell whether it '
				. 'already holds dashboard files. Nothing was changed — try again, and check the '
				. 'folder\'s permissions if this persists.',
				$folder->getName(),
			), 0, $e);
		}

		$found = [];
		foreach ($children as $child) {
			if ($child instanceof Folder) {
				foreach ($this->dashboardsBelow($child, $depth + 1) as $nested) {
					$found[] = $nested;
				}
				continue;
			}
			if (FilenameCodec::isDashboardFile($child)) {
				/** @var File $child — isDashboardFile guarantees a File */
				$found[] = $child;
			}
		}

		return $found;
	}
}
