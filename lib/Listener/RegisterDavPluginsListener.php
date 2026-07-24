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
