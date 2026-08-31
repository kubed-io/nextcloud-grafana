<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\GrafanaSync\DAV\LinkWriteGuardPlugin;
use OCA\GrafanaSync\DAV\ReplacedByMovePlugin;
use OCA\GrafanaSync\DAV\TrashRestorePlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Attaches {@see LinkWriteGuardPlugin} to every Sabre (WebDAV) server the DAV app spins up.
 *
 * The DAV app fires {@see SabrePluginAddEvent} while assembling each server instance, which
 * is the supported seam for a third-party app to register its own Sabre plugin. Our plugin
 * then refuses WebDAV writes to link-mode dashboard files (see its class doc for why the
 * Sabre layer is the only reliable choke for that).
 *
 * {@see ReplacedByMovePlugin} rides along for a different reason: it refuses nothing and
 * only WATCHES, marking a dashboard file that a MOVE is about to overwrite so the delete
 * half of that overwrite does not reach Grafana. `beforeMove` is the only moment anything
 * knows the delete and the move are one gesture, and Sabre is the only layer that fires it.
 *
 * A SECOND PLUGIN LIVED HERE BRIEFLY AND HAD TO GO. `CopyNamePlugin` rewrote a COPY's
 * `Destination` header so a colliding copy was born under our spelling rather than
 * Nextcloud's. It worked, and it broke the Files app, because the client stats the path
 * IT chose the moment the copy returns. The rename is deferred to
 * {@see \OCA\GrafanaSync\BackgroundJob\ReconcileNameJob} instead — see
 * `features/AGENTS.md#the-copy-cannot-be-renamed-before-the-client-has-looked-at-it`.
 *
 * @implements IEventListener<SabrePluginAddEvent>
 */
final class RegisterDavPluginsListener implements IEventListener {
	public function __construct(
		private LinkWriteGuardPlugin $linkWriteGuard,
		private ReplacedByMovePlugin $replacedByMove,
		private TrashRestorePlugin $trashRestore,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$server = $event->getServer();
		$server->addPlugin($this->linkWriteGuard);
		$server->addPlugin($this->replacedByMove);
		$server->addPlugin($this->trashRestore);
	}
}
