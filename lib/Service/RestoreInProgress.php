<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * Request-scoped note that a WebDAV restore is under way, and of the identity the
 * file being restored was carrying.
 *
 * ## A RESTORE IS PERFORMED AS A PURGE, AND THAT IS NOT A FIGURE OF SPEECH
 *
 * Restoring from the trash in a browser is `MOVE /remote.php/dav/trashbin/…` to
 * `files/…`. The two ends live in different collections, so `Sabre\DAV\Tree::move()`
 * cannot rename and falls back to copy-then-delete — and the delete half is
 * `AbstractTrash::delete()` → `TrashManager::removeItem()` → `Trashbin::delete()`,
 * *the same function emptying the trash calls*, emitting the same `\OCP\Trashbin`
 * `preDelete` hook. Captured live:
 *
 *     #1 TrashPurgeHook.php(125): DeleteService->hardDelete(…)
 *     #3 Trashbin.php(726): OC_Hook::emit('\OCP\Trashbin', 'preDelete', …)
 *     #7 AbstractTrash.php(90): TrashManager->removeItem(…)
 *     #9 CorePlugin.php(612): Sabre\DAV\Tree->move('trashbin/…', 'files/…')
 *
 * So the app permanently deleted the parked dashboard the user was restoring. Nothing
 * inside the hook can tell the two gestures apart, because at that depth they are the
 * same gesture; only the HTTP request knows.
 *
 * ## AND NO RESTORE SIGNAL FIRES AT ALL ON THAT PATH
 *
 * Because Sabre does the copy and the delete itself, `Trashbin::restore()` never runs —
 * so `NodeRestoredEvent` is never dispatched and `post_restore` is never emitted.
 * {@see \OCA\GrafanaSync\Listener\RestoreFromTrashListener} and
 * {@see \OCA\GrafanaSync\Listener\TrashRestoreHook} are both dead on the one path a
 * person actually uses. The file came back only because the copy looked like a brand
 * new file and create-on-land minted a dashboard for it — which is why a restore
 * produced a new uid, a new URL and an empty history even before the delete above.
 *
 * That is why this class carries the IDENTITY as well as the flag: suppressing the
 * purge alone would leave the old dashboard parked in the bin forever while the
 * restored file pointed at a freshly minted one. Both halves are needed, and both are
 * driven from {@see \OCA\GrafanaSync\DAV\TrashRestorePlugin}, which brackets the move.
 *
 * ## WHY NO TEST CAUGHT IT
 *
 * Every restore scenario in the suite passes. They run against CI's local storage,
 * where the same MOVE is a rename: no copy, no delete, no purge hook, no mint. The
 * behaviour is correct there and wrong on every instance whose files live anywhere
 * else. Measured on the live instance; see `features/AGENTS.md`.
 */
final class RestoreInProgress {
	private bool $active = false;

	/** destination DAV path ⇒ the stamp the trashed file carried in. */
	private array $carried = [];

	/** This request is restoring from the trash; a purge inside it is not a purge. */
	public function mark(): void {
		$this->active = true;
	}

	public function active(): bool {
		return $this->active;
	}

	/**
	 * Remember what the file being restored was bound to, so the copy that lands at
	 * $destination can inherit it instead of being minted a new dashboard.
	 */
	public function carry(string $destination, ManagedFile $managed): void {
		$this->carried[$destination] = $managed;
	}

	/** The stamp owed to the file that just landed at $destination, if any. */
	public function claim(string $destination): ?ManagedFile {
		return $this->carried[$destination] ?? null;
	}
}
