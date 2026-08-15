<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\GrafanaSync\DAV\LinkWriteGuardPlugin;
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
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->linkWriteGuard);
	}
}
