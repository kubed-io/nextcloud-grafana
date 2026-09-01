<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * WHICH MOVES ARE REFUSED, AND IN WHAT WORDS — asked, never thrown.
 *
 * ## WHY THIS IS A SERVICE AND NOT A LISTENER
 *
 * The rules have to be stated in two places, because Nextcloud gives no single place
 * that both stops a move and tells the user why.
 *
 *  - {@see \OCA\GrafanaSync\Listener\MoveGuardListener} carries them on
 *    `BeforeNodeRenamedEvent`, which is the only hook that reaches EVERY route — `occ`,
 *    another app, a script — and the only way to abort one. Throwing
 *    `AbortedEventException` there is what stops the rename; the message goes to the
 *    log and no further, because `HookConnector::rename()` catches it and sets
 *    `run = false`, and `Directory::moveInto()` then answers `Forbidden('')` — an empty
 *    string, by literal.
 *  - {@see \OCA\GrafanaSync\DAV\LinkWriteGuardPlugin} carries them on Sabre's
 *    `method:MOVE`, which sees only WebDAV but is the one place a 403 with a readable
 *    reason actually reaches the client.
 *
 * ## AND EVERY OTHER EXCEPTION IS SWALLOWED WHOLE, WHICH IS THE TRAP
 *
 * `OC_Hook::emit()` wraps each slot in `catch (Throwable)`, logs it, and CARRIES ON —
 * only `HintException` and `ServerNotAvailableException` are re-thrown. So a listener
 * that throws anything else does not refuse the move at all: it is logged and the move
 * succeeds. Swapping `AbortedEventException` for `OCP\Files\ForbiddenException` here to
 * rescue the message turned nine refusals into HTTP 201 — measured in CI, and the reason
 * the rules live here now rather than being stated twice by hand.
 *
 * The target is a PATH and a NAME rather than a node, because the DAV side is asked
 * before the destination exists.
 */
final class MoveRules {
	public function __construct(
		private FolderMetadata $folders,
		private MappingService $mappings,
		private DashboardMetadata $metadata,
	) {
	}

	/**
	 * The reason this move must be refused, or null when it may go ahead.
	 *
	 * @param string $targetPath the internal path the node is moving TO (`/<uid>/files/…`)
	 * @param string $targetName the name it will have when it lands — a move may rename
	 */
	public function refusalFor(Node $source, string $targetPath, string $targetName): ?string {
		if ($source instanceof Folder) {
			return $this->forFolder($source, $targetPath);
		}
		if (!FilenameCodec::isDashboardFile($source)) {
			return null; // only managed dashboard files are constrained
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return null; // not under any mapping (e.g. already unmapped) — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($targetPath);

		// The file's own stamp wins over the mapping's mode: a file can be mid-re-mode,
		// and what it IS decides what may be done to it.
		$managed = $this->metadata->read($source->getId());
		$mode = ($managed !== null && $managed->mode !== '') ? $managed->mode : $srcMapping->mode;

		// A NAME CHANGE IS ITS OWN GESTURE, judged before the where-is-it-going rules:
		// the two refusals below hold wherever the file is headed, its own folder included.
		$renamed = $source->getName() !== $targetName;

		// A DASHBOARD ALWAYS HAS A NAME. Nextcloud refuses a fully empty filename on its
		// own, but ` .grafana` has a whitespace stem — and NameSyncListener would bail on
		// it silently, leaving the file, the JSON and Grafana in a three-way disagreement
		// nothing reports. Refused here, where the user can see why.
		if ($renamed && FilenameCodec::isDashboardName($targetName) && FilenameCodec::displayName($targetName) === '') {
			return 'A dashboard file needs a name — the title in Grafana comes from it. '
				. 'Give the file a non-blank name.';
		}

		// RENAMING A LINK NEVER RENAMES THE DASHBOARD — a pointer has no writeback
		// channel, so the new name would survive exactly until the next pull re-derived
		// the filename from Grafana and quietly undid it. A rename undone later is worse
		// than one refused now: the user is neither told no nor allowed to keep it.
		if ($renamed && $mode === Mapping::MODE_LINK) {
			return '“' . $source->getName() . '” is a linked Grafana dashboard, so its name comes from Grafana '
				. 'and can’t be changed here. Rename the dashboard in Grafana instead.';
		}

		// A LINK DOES NOT MOVE, AND THAT INCLUDES INSIDE ITS OWN MAPPING.
		//
		// JUDGED BEFORE THE WHERE-IS-IT-GOING RULES, exactly as the rename above is, and
		// for the same reason. This used to sit AFTER the same-mapping shortcut below,
		// so a link could be dragged into a subfolder of its own mapped folder — and the
		// message then told the user to do just that. It was wrong twice over: a link is
		// read-only in Nextcloud, so no gesture here moves it; and WHERE a mirror sits is
		// decided by which GRAFANA folder its dashboard is in, so a file dragged into a
		// subfolder here disagrees with Grafana until the next pull puts it back.
		//
		// The way to file a link into a subfolder is to move the DASHBOARD into that
		// subfolder in Grafana; the mirror then moves down on this side to follow it.
		// That is the same shape as every other link gesture: the far side decides, and
		// Nextcloud reflects.
		if ($mode === Mapping::MODE_LINK) {
			return '“' . $source->getName() . '” is a linked Grafana dashboard, so where it sits comes from '
				. 'Grafana and it can’t be moved here. Move the dashboard in Grafana instead, and the '
				. 'mirror will follow.';
		}

		// WITHIN its own mapping, anything goes — a rename, a subfolder, anywhere under
		// the same mapping. Nothing about the file's membership changes, so there is
		// nothing here to protect. Keyed on the mapping ID rather than the folder,
		// because a subfolder resolves to the same mapping and must stay allowed.
		//
		// SYNC FILES ONLY, because the link refusal above has already returned. A sync
		// file is authored here and its folder is the user's to arrange; a link is not.
		if ($tgtMapping !== null && $tgtMapping->id === $srcMapping->id) {
			return null;
		}

		// AND A LINK MAPPING IS NOT A DESTINATION. Its folder is filled from the Grafana
		// folder it mirrors and from nowhere else, so a file moved in by hand is at best
		// ignored and at worst pruned by the next pull.
		if ($tgtMapping !== null && $tgtMapping->mode === Mapping::MODE_LINK) {
			return '“' . $tgtMapping->ncFolder . '” mirrors a Grafana folder in link mode, so its dashboards '
				. 'are Grafana’s to place — files can’t be moved into it. Move the dashboard in Grafana instead.';
		}

		// Sync leaving the mapped set is allowed; MotionService deletes it in Grafana
		// (or parks it in the recycle bin) and unmaps the file afterwards.
		return null;
	}

	/**
	 * The two refusals a folder move inherits from the file rules, one level up.
	 *
	 * Both are about MODE, which is why they are gates rather than consequences: a mode
	 * belongs to the folder, and no move may change one. Everything else a folder move
	 * implies — re-parenting in Grafana, or the cascade of leaving the mapped set — is
	 * decided after the fact, not here.
	 */
	private function forFolder(Folder $source, string $targetPath): ?string {
		// ONLY A MIRRORED FOLDER IS CONSTRAINED. A folder under a mapping is a plain
		// folder until a dashboard lands beneath it — that is the rule that keeps a
		// mapped folder usable for notes, exports and anything else — so a folder this
		// app has never stamped is the user's own and moves wherever they like. Without
		// this check the mode rules applied to every folder under a mapping, and a
		// "Notes" folder inside a link mapping could not be dragged out of it.
		try {
			if ($this->folders->uidOf($source->getId()) === '') {
				return null;
			}
		} catch (\Throwable) {
			return null; // cannot classify → never block
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return null; // outside every mapping — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($targetPath);

		// A MIRRORED FOLDER IN A LINK MAPPING DOES NOT MOVE EITHER, AND FOR THE SAME
		// REASON AS THE FILE. It exists because a Grafana folder does, and WHERE it sits
		// is Grafana's to say — `Move a folder in Grafana` is the gesture that relocates
		// one, and this side follows. Asked before the destination is looked at, because
		// the destination does not enter into it.
		//
		// This used to refuse only a move OUT of the mapped set, which left two holes
		// either side of it: a mirrored folder could be dragged into a subfolder of its
		// own mapping, and — because that test compared MODES rather than mappings — into
		// a different link mapping as well, where the next pull would prune it. The old
		// message told the user to "move it within that folder instead", which was the
		// first of those holes written down as advice.
		//
		// AND IT REACHES ONLY MIRRORED FOLDERS, which is what makes the rule safe to
		// state this broadly. A folder is not part of a mapping until a dashboard lands
		// in it, and the stamp check at the top has already let every unstamped one go —
		// so a "Notes" folder someone made inside a link mapping is still theirs to move
		// anywhere. Being INSIDE a link mapping is not what binds a folder; mirroring a
		// Grafana folder is.
		if ($srcMapping->mode === Mapping::MODE_LINK) {
			return '“' . $source->getName() . '” mirrors a Grafana folder in “' . $srcMapping->ncFolder
				. '”, so where it sits comes from Grafana and it can’t be moved here. '
				. 'Move the folder in Grafana instead, and the mirror will follow.';
		}

		if ($tgtMapping === null) {
			return null; // a sync folder leaving the mapped set — the cascade handles it
		}

		if ($srcMapping->mode !== $tgtMapping->mode) {
			return '“' . $source->getName() . '” can’t be moved from “' . $srcMapping->ncFolder . '” to “'
				. $tgtMapping->ncFolder . '” — one mirrors Grafana in ' . $srcMapping->mode
				. ' mode and the other in ' . $tgtMapping->mode . ' mode, and a move may not change that.';
		}

		return null;
	}
}
