<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Gate-keeps a managed dashboard file's moves *before* they happen. NC fires
 * {@see BeforeNodeRenamedEvent} for both renames and moves; throwing
 * {@see AbortedEventException} aborts the operation and shows the message to the user.
 * The *consequences* of an allowed move are handled afterwards by {@see MotionListener}
 * on the post-move `NodeRenamedEvent`.
 *
 * Rules (only `*.grafana` files under a mapping are constrained):
 *   - move/rename **within** the same mapping → allow (rename, subfolder move).
 *   - move into a **different** mapping → **allow** — this is a real Grafana folder move
 *     (the dashboard re-parents, uid kept). This is where Grafana diverges from the n8n
 *     master, which blocks it: n8n maps by tag with no folder to move to, so it never
 *     designed the cross-mapping case; we map by real folders, so MotionService just
 *     re-parents the dashboard.
 *   - move **out** to an unmapped location:
 *       · `sync`  → **allow** — the file holds the full JSON, so MotionService deletes the
 *                   dashboard in Grafana and strips the file (it becomes a plain document).
 *       · `link`  → **block** — a link has no NC-side JSON to keep; moving the pointer out
 *                   would orphan it, and there's nothing to rebuild from.
 *
 * An already-unmapped file lives outside every mapping, so resolveForPath on its source
 * returns null and it is never constrained here (pure relocation).
 *
 * ## FOLDERS OBEY THE SAME TWO REFUSALS
 *
 * A folder is not a file, but a folder move carries every dashboard inside it, so the
 * rules that protect one file have to protect a folder of them. Both refusals are the
 * file rules read one level up:
 *
 *   - a folder may not cross **between** a `sync` and a `link` mapping, because a mode
 *     belongs to the folder and a move may not change one;
 *   - a folder under a `link` mapping may not leave it, because its dashboards are
 *     pointers and moving them out orphans the lot.
 *
 * What a folder move does NOT get here is the sync-leaving-its-mapping case. For a file
 * that is one delete; for a folder it is a cascade over everything inside, with the
 * recycle bin deciding whether each dashboard is deleted or parked — a consequence, not
 * a gate, and it belongs with the rest of the leave-the-mapped-set work.
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveGuardListener implements IEventListener {
	public function __construct(
		private FolderMetadata $folders,
		private MappingService $mappings,
		private DashboardMetadata $metadata,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		$source = $event->getSource();
		if ($source instanceof Folder) {
			$this->guardFolder($source, $event->getTarget());
			return;
		}
		if (!FilenameCodec::isDashboardFile($source)) {
			return; // only managed dashboard files are constrained
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return; // not under any mapping (e.g. already unmapped) — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($event->getTarget()->getPath());

		// The file's own stamp wins over the mapping's mode: a file can be mid-re-mode,
		// and what it IS decides what may be done to it.
		$managed = $this->metadata->read($source->getId());
		$mode = ($managed !== null && $managed->mode !== '') ? $managed->mode : $srcMapping->mode;

		// WITHIN its own mapping, anything goes — a rename, a subfolder, anywhere under
		// the same mapping. Nothing about the file's membership changes, so there is
		// nothing here to protect. Keyed on the mapping ID rather than the folder,
		// because a subfolder resolves to the same mapping and must stay allowed.
		if ($tgtMapping !== null && $tgtMapping->id === $srcMapping->id) {
			return;
		}

		// A LINK IS NOT MOVABLE, AND THERE IS NOWHERE IT MAY GO. It is a read-only
		// projection of a dashboard that lives in Grafana, and its membership is decided
		// by which GRAFANA folder that dashboard sits in — never by where the file sits
		// here. So moving it to another link mapping does not re-home it, it just
		// disagrees with Grafana until the next pull prunes it from the destination and
		// writes it back at the source. Refusing is the only answer that is not a silent
		// undo one sync later.
		if ($mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'A linked Grafana dashboard can’t be moved out of its mapped folder ("'
				. $srcMapping->ncFolder . '") — it’s only a pointer. Move it within that folder instead.',
			);
		}

		// AND A LINK MAPPING IS NOT A DESTINATION. Its folder is filled from the Grafana
		// folder it mirrors and from nowhere else, so a file moved in by hand is at best
		// ignored and at worst pruned by the next pull.
		if ($tgtMapping !== null && $tgtMapping->mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'“' . $tgtMapping->ncFolder . '” mirrors a Grafana folder in link mode, so its dashboards '
				. 'are Grafana’s to place — files can’t be moved into it. Move the dashboard in Grafana instead.',
			);
		}

		// Sync leaving the mapped set is allowed; MotionService deletes it in Grafana
		// (or parks it in the recycle bin) and unmaps the file afterwards.
		// sync → allow; MotionService deletes it in Grafana and unmaps the file afterwards.
	}

	/**
	 * The two refusals a folder move inherits from the file rules, one level up.
	 *
	 * Both are about MODE, which is why they are gates rather than consequences: a mode
	 * belongs to the folder, and no move may change one. Everything else a folder move
	 * implies — re-parenting in Grafana, or the cascade of leaving the mapped set — is
	 * decided after the fact, not here.
	 */
	private function guardFolder(Folder $source, Node $target): void {
		// ONLY A MIRRORED FOLDER IS CONSTRAINED. A folder under a mapping is a plain
		// folder until a dashboard lands beneath it — that is the rule that keeps a
		// mapped folder usable for notes, exports and anything else — so a folder this
		// app has never stamped is the user's own and moves wherever they like. Without
		// this check the mode rules applied to every folder under a mapping, and a
		// "Notes" folder inside a link mapping could not be dragged out of it.
		try {
			if ($this->folders->uidOf($source->getId()) === '') {
				return;
			}
		} catch (\Throwable) {
			return; // cannot classify → never block
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return; // outside every mapping — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($target->getPath());

		if ($tgtMapping === null) {
			// Leaving the mapped set. Allowed for sync (the cascade handles it); refused
			// for link, whose dashboards are pointers with nothing to rebuild from.
			if ($srcMapping->mode === Mapping::MODE_LINK) {
				throw new AbortedEventException(
					'“' . $source->getName() . '” can’t be moved out of “' . $srcMapping->ncFolder
					. '” — that folder mirrors Grafana in link mode, so its dashboards are only pointers. '
					. 'Move it within that folder instead.',
				);
			}
			return;
		}

		if ($srcMapping->mode !== $tgtMapping->mode) {
			throw new AbortedEventException(
				'“' . $source->getName() . '” can’t be moved from “' . $srcMapping->ncFolder . '” to “'
				. $tgtMapping->ncFolder . '” — one mirrors Grafana in ' . $srcMapping->mode
				. ' mode and the other in ' . $tgtMapping->mode . ' mode, and a move may not change that.',
			);
		}
	}
}
