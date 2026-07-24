<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;

/**
 * Gate-keeps a managed dashboard file's moves *before* they happen. NC fires
 * {@see BeforeNodeRenamedEvent} for both renames and moves; throwing
 * {@see AbortedEventException} aborts the operation and shows the message to the user.
 * The *consequences* of an allowed move are handled afterwards by {@see MotionListener}
 * on the post-move `NodeRenamedEvent`.
 *
 * Rules (only `*.grafana.json` files under a mapping are constrained):
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
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveGuardListener implements IEventListener {
	public function __construct(
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
		if (!FilenameCodec::isDashboardFile($source)) {
			return; // only managed dashboard files are constrained
		}

		$srcMapping = $this->mappings->resolveForPath($source->getPath());
		if ($srcMapping === null) {
			return; // not under any mapping (e.g. already unmapped) — nothing to enforce
		}
		$tgtMapping = $this->mappings->resolveForPath($event->getTarget()->getPath());
		if ($tgtMapping !== null) {
			// same mapping (rename/subfolder) or a different mapping (a real folder move) —
			// both allowed; MotionService re-parents on the post-move event.
			return;
		}

		// Leaving its mapping for an unmapped location. Sync is allowed (delete + strip);
		// link is refused (a pointer with no local JSON — moving it out orphans it).
		$managed = $this->metadata->read($source->getId());
		$mode = ($managed !== null && $managed->mode !== '') ? $managed->mode : $srcMapping->mode;
		if ($mode === Mapping::MODE_LINK) {
			throw new AbortedEventException(
				'A linked Grafana dashboard can’t be moved out of its mapped folder ("'
				. $srcMapping->ncFolder . '") — it’s only a pointer. Move it within that folder instead.',
			);
		}
		// sync → allow; MotionService deletes it in Grafana and unmaps the file afterwards.
	}
}
