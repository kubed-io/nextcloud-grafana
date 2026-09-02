<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\BackgroundJob\ReconcileNameJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The copy half of the write surface (Course 4 · Slice 1, `copy.feature`). Where a
 * move is "the SAME dashboard relocating," a **copy is ALWAYS a brand-new instance** —
 * it never inherits the original's Grafana identity.
 *
 * Copy is the single safest point to strip metadata: whatever the source was (sync,
 * link, unmapped), the copy starts clean. Two things happen here, driven by
 * {@see \OCA\GrafanaSync\Listener\CopyListener} on `NodeCopiedEvent`:
 *
 *   1. **Strip identity.** Wipe any `grafana_uid` / mode / mapping metadata from the
 *      copy. Nextcloud doesn't propagate Files-Metadata across a copy today, so this is
 *      normally a no-op — but doing it explicitly makes "a copy starts clean" a
 *      guarantee, not an accident of core.
 *   2. **Register if it landed in a mapped sync folder**, as an explicitly NEW dashboard
 *      ({@see CreateService::createForFile}'s `$asNewDashboard`). A copy outside a
 *      mapping (or in a `link` folder — pointers aren't authored) is left a plain,
 *      untracked file.
 *
 * ## WHY STEP 2 HAS TO SAY "NEW" OUT LOUD
 *
 * This class used to rely on step 1 for that, and said so in as many words: "the
 * created body carries no uid, so Grafana mints a fresh one". That is true of a
 * hand-made file and **false of every mirror the app has ever synced** — a dashboard
 * spec carries its own `uid`, so the file on disk does too, and
 * {@see DashboardBody::toUpsertBody} takes the body's uid as the identity to upsert on.
 * Wiping the METADATA does nothing about the uid sitting in the JSON.
 *
 * So copying a real dashboard file did not create a dashboard at all: it wrote the
 * copy's contents **over the original**, as a new version of it. Measured on a live
 * instance — the source dashboard gained a `v2 "Updated by Nextcloud"` and no second
 * dashboard ever existed. The integration suite could not see it, because its fixtures
 * were hand-written bodies with no uid in them; the shared arrange now seeds a body the
 * way a sync really leaves one.
 *
 * **And the fix cannot be "rewrite the copy without the uid".** The copy's own hook
 * runs while Nextcloud holds locks on the target — `putContent` there throws
 * `LockedException: 2 shared locks`, measured on the same instance. It is the trap
 * {@see \OCA\GrafanaSync\Listener\NameSyncListener} already defers to a background job
 * to avoid. So the uid is dropped from the SPEC ON ITS WAY TO GRAFANA and the file is
 * never touched; the body's stale uid is harmless, because a push forces the metadata
 * uid onto the spec and the next pull rewrites the file anyway.
 *
 * ## AND THE NAME NEXTCLOUD PICKED IS THE COPY'S REAL NAME
 *
 * A copy landing beside its source collides, so Nextcloud names it — `Board (1).grafana`.
 * That name is the copy's name **in all three places**: `createForFile` puts the
 * filename's display name on the spec so Grafana is right from the first write, and
 * `settleName()` below writes it into the file's JSON `title` to match. Without it a copy
 * reached Grafana under the ORIGINAL's title — two dashboards, one name, and a file
 * claiming a third thing.
 *
 * The title is a file write, so it is deferred to {@see ReconcileNameJob} — see the lock
 * above. Grafana is correct within the request; the file catches up a tick later.
 *
 * **THE FILE ITSELF NEEDS NO CORRECTING.** Nextcloud's collision counter lands exactly
 * where {@see FilenameCodec::format()} puts it, so a copy is born wearing a name this app
 * already reads.
 *
 * Failures are logged and swallowed: the NC copy already happened, and a copy that
 * failed to register is just an untracked `.grafana` the user can re-save to retry.
 */
final class CopyService {
	use ResolvesActingUser;

	public function __construct(
		private CreateService $createService,
		private MappingService $mappings,
		private DashboardMetadata $metadata,
		private SyncGuard $guard,
		private IJobList $jobList,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Handle a freshly-copied `*.grafana` file: strip any inherited identity, then
	 * register it as a new dashboard if it landed in a mapped sync folder.
	 */
	public function onCopy(File $node): void {
		$this->stripIdentity($node);

		$mapping = $this->mappings->resolveForPath($node->getPath());
		if ($mapping === null || $mapping->mode !== Mapping::MODE_SYNC) {
			return; // outside a mapping, or a link folder → a plain, untracked file
		}

		// Identity was just wiped, so this mints a brand-new uid — never the source's.
		// Logged + swallowed here (honouring this service's contract): the NC copy already
		// happened, so a failed registration is just an untracked .grafana to re-save.
		try {
			$this->createService->createForFile($node, $mapping, true);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: failed to register a copied file as a new dashboard', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'path' => $node->getPath(),
				'exception' => $e,
			]);
			return; // nothing to reconcile a name against — the copy is an untracked file
		}

		$this->settleName($node);
	}

	/**
	 * Hand the copy's name to {@see ReconcileNameJob}, which runs once the locks this
	 * hook holds are gone, and writes the file's display name into its JSON `title`.
	 *
	 * `title_from_filename` is the right action because a copy IS a naming — the file
	 * was just given a name by Nextcloud, exactly as a rename gives it one by hand, and
	 * both make the filename the authority. The job re-checks everything and no-ops when
	 * a copy needed neither (the ordinary case: a copy into a folder where nothing
	 * collided already agrees with itself).
	 */
	private function settleName(File $node): void {
		// The job resolves the file per-user, because team-folder files are mounted that
		// way — same reason the async push job takes one.
		$uid = $this->actingUserUid($node);
		if ($uid === '') {
			return;
		}
		$this->jobList->add(ReconcileNameJob::class, [
			'fileId' => $node->getId(),
			'userId' => $uid,
			'action' => 'title_from_filename',
		]);
	}

	/**
	 * Wipe the copy's managed metadata + ownership pill so it carries none of the
	 * original's Grafana identity. Wrapped in the {@see SyncGuard} so the implicit
	 * writes don't echo into the writeback listener.
	 */
	private function stripIdentity(File $node): void {
		$this->guard->run(function () use ($node): void {
			$this->metadata->clear($node->getId());
		});
	}
}
