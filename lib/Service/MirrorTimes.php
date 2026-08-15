<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Gives a mirror the timestamps of the thing it mirrors: Grafana's `meta.updated` becomes the
 * file's modification time, `meta.created` its creation time.
 *
 * ## Why this exists at all
 *
 * Nextcloud's own clocks are honest about the NODE — "the app wrote this file at
 * 15:02" — and that is never the question a person sorting a folder of dashboards by
 * date is asking. Left alone, a dashboard edited at 15:00 and pulled at 15:02 reads
 * `15:02`, and one nobody has touched in a year reads the moment its mirror was first
 * created. The first is wrong by the poll interval; the second is wrong by a year.
 *
 * ## Why it is a class and not four lines in the reconciler
 *
 * Reading both clocks is public API — `OCP\Files\Node` extends `FileInfo`, so `getMTime()`
 * and `getCreationTime()` are right there — and so is *setting* the modification time
 * (`Node::touch()`). Setting the CREATION time is not: `getCreationTime()` has no matching
 * setter and the value lives in the filecache extension table. The supported route is the
 * public cache API — `Node::getStorage()` → `IStorage::getCache()` → `ICache::update()`,
 * all `@since 9.0.0` — and that is framework plumbing with no business inside a reconciler
 * loop. Keeping it here means the sibling apps port one small class instead of re-deriving
 * the hops, and the reconciler's unit tests mock one collaborator instead of a storage stack.
 *
 * ## The trap this class is shaped around, measured rather than assumed
 *
 * The apprentice's spec warned that "a naive implementation writes the timestamp every
 * run, which is exactly the churn `reconcile.feature` forbids." True, but NOT for the
 * reason it looks like, and the difference decides the design. Measured on a live
 * instance (NC 33, S3 primary storage):
 *
 *  - `touch()` does **not** change the file's own etag. A client looking straight at the
 *    file would see nothing, so "every desktop re-downloads every mirror" is wrong.
 *  - `touch()` **does propagate a fresh etag to the parent folder** — `6a6f71a7cf733` →
 *    `6a6f71eae4fc5` on an otherwise untouched folder.
 *
 * And the folder etag is precisely what sync clients poll to decide *"something in here
 * changed, re-scan it."* So stamping unconditionally would not churn the files; it would
 * churn the FOLDER, every tick, forever — the same defect as saga Ch2 Course 7 moved one level
 * up where it is harder to see and no one is looking. Hence: every write here is
 * conditional, and {@see apply()} is a no-op on a mirror that already carries the right
 * clocks. `$force` exists only for the case where the caller has just rewritten the body
 * and therefore KNOWS the mtime is `now`.
 */
final class MirrorTimes {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stamp `$mtime` / `$creationTime` onto `$file`, writing only what actually differs.
	 *
	 * A null value means "leave that clock alone" — never "stamp the epoch", which is
	 * what makes an absent or unparseable source timestamp harmless rather than
	 * destructive.
	 *
	 * Best-effort by design: callers reach this after the body, the metadata, and the
	 * tags are already committed, so a clock that will not set is logged and swallowed.
	 * It must never turn a good pull into a failed one, and the next pull retries.
	 *
	 * @param bool $force the caller just rewrote the body, so the file's mtime is `now`
	 *                    and there is nothing to compare against — restamp regardless
	 */
	public function apply(File $file, ?int $mtime, ?int $creationTime, bool $force = false): void {
		try {
			if ($mtime !== null && ($force || $file->getMTime() !== $mtime)) {
				$file->touch($mtime);
			}
			if ($creationTime !== null && $file->getCreationTime() !== $creationTime) {
				// No OCP setter for creation time; the public cache API is the route.
				// `$force` is deliberately NOT honoured here — unlike mtime, writing the
				// body does not disturb the creation time, so the comparison is always
				// meaningful and always sufficient.
				$file->getStorage()->getCache()->update($file->getId(), ['creation_time' => $creationTime]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not stamp the source timestamps onto the mirror', [
				'app' => Application::APP_ID,
				'file' => $file->getName(),
				'exception' => $e,
			]);
		}
	}
}
