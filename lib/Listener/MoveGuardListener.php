<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\Service\MoveRules;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;

/**
 * STOPS a refused move, on every route there is. What it cannot do is say why.
 *
 * The rules themselves are {@see MoveRules}, stated once and asked twice — here and in
 * {@see \OCA\GrafanaSync\DAV\LinkWriteGuardPlugin}, which is where the reason reaches a
 * person. This listener is the half that reaches `occ`, another app and a script, none
 * of which go anywhere near Sabre.
 *
 * ## `AbortedEventException` IS THE ONLY THING THAT WORKS HERE, AND IT LOSES THE MESSAGE
 *
 * `OC_Hook::emit()` wraps every slot in `catch (Throwable)`, logs it and CARRIES ON —
 * only `HintException` and `ServerNotAvailableException` are re-thrown. The one
 * exception the rename path treats as a decision rather than an accident is
 * `AbortedEventException`, which `HookConnector::rename()` catches by name and turns
 * into `run = false`.
 *
 * And that catch is also where the message dies: it goes to the log, `View::rename()`
 * returns false, and `Directory::moveInto()` answers `throw new Forbidden('')` — an
 * empty string, by literal. So a refusal made here reaches the Files app as a 403 with
 * nothing in it.
 *
 * Both halves of that were measured rather than reasoned, one CI round each: swapping in
 * `OCP\Files\ForbiddenException` to rescue the message turned nine refusals into HTTP
 * 201, because `OC_Hook::emit()` swallowed it and the move went ahead. Abort here; speak
 * in the DAV plugin. The same split PUT and COPY already use, for the same reason.
 *
 * The *consequences* of an allowed move are handled afterwards by {@see MotionListener}
 * on the post-move `NodeRenamedEvent`, and by {@see FolderMoveListener} for a folder.
 *
 * @implements IEventListener<BeforeNodeRenamedEvent>
 */
final class MoveGuardListener implements IEventListener {
	public function __construct(
		private MoveRules $rules,
		private SyncGuard $guard,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeRenamedEvent) {
			return;
		}
		// THE PULL RENAMES MIRRORS ITSELF — links included — via Node::move() on the
		// file's own folder, which fires this very event. A guard the app's own
		// reconcile can trip is not a safety, it is a broken pull.
		if ($this->guard->active()) {
			return;
		}
		$target = $event->getTarget();
		$refusal = $this->rules->refusalFor($event->getSource(), $target->getPath(), $target->getName());
		if ($refusal !== null) {
			throw new AbortedEventException($refusal);
		}
	}
}
