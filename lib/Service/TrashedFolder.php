<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * One FOLDER sitting in a Nextcloud trash, with what it holds already sorted into
 * "mirrors of ours" and "everything else" — the folder sibling of {@see TrashedFile}.
 *
 * See that class for why `OCA\Files_Trashbin\Trash\ITrashItem` stops at
 * {@see TrashControl}'s boundary instead of being passed around.
 *
 * ## WHY A TRASHED FOLDER NEEDS ITS OWN TYPE
 *
 * Trashing a folder produces ONE trash entry, not one per file. So the entry is named
 * `Emptied`, not `Alpha.grafana`, and {@see TrashReconcileService}'s filter — "is this
 * trash entry a dashboard file?" — answered no and walked past every mirror inside it.
 * `TrashControl::listTrashed()` never even offered them: it skips anything that is not
 * `TYPE_FILE`. A folder full of parked dashboards was invisible to the reconcile, and
 * emptying the Grafana bin left its trashed mirror behind forever
 * (`folders/purge.feature`).
 *
 * ## THE CONTENTS ARE THE MIRRORS THEMSELVES, AND THE OTHER FLAG IS THE VETO
 *
 * A trashed folder's children are not separate trash entries and its node is not
 * resolvable by path — the home trash and a Team Folder's trash live on different
 * mounts. The only door is `ITrashItem::getTrashBackend()->listTrashFolder()`, which is
 * the trash app's own type dispatching on its own backend: exactly what
 * {@see TrashControl} exists to keep at that boundary. So the walk happens there and
 * only the ANSWERS travel here — each mirror as a {@see TrashedFile} carrying its own
 * purge, because the reconcile has to be able to destroy ONE of them.
 *
 * `$holdsOtherFiles` is a VETO ON PURGING THE ENTRY, not on purging the mirrors. It is
 * true for a spreadsheet, for a subtree that could not be read, and for one deeper than
 * the walk goes — every way of holding something this app cannot account for. A folder
 * with it set keeps its entry, because a file with no far side cannot be destroyed by
 * something that happened in Grafana. The mirrors inside it still go: a purge is a
 * purge, and the dashboard they mirror is gone.
 *
 * ## WALKED EAGERLY, UNLIKE THE SIBLING
 *
 * `nextcloud-penpot` defers this walk behind a memoised closure, because its revive path
 * asks for trashed folders on EVERY PULL and wants only the id off the folder itself —
 * so an eager walk made the cheap caller pay the expensive caller's bill for an answer
 * it discards. That reason does not exist here: {@see TrashReconcileService::reapFolders}
 * is the only caller and it always wants the contents. If a second caller ever appears
 * that does not, take the sibling's closure rather than re-deriving why.
 */
final class TrashedFolder {
	/**
	 * @param string $name the ORIGINAL basename, not the trash's timestamped spelling
	 * @param list<TrashedFile> $dashboards every `.grafana` at any depth, each able to
	 *                                      destroy itself without taking the entry
	 * @param bool $holdsOtherFiles anything in the tree that is not one of those — see
	 *                              the class docblock: a veto on purging the ENTRY
	 * @param \Closure():void $purge destroy the entry, and everything that went in with it
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $dashboards,
		public readonly bool $holdsOtherFiles,
		private readonly \Closure $purge,
	) {
	}

	/** Destroy the whole trash entry, folder and everything under it. */
	public function purge(): void {
		($this->purge)();
	}
}
