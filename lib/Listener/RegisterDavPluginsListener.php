<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\GrafanaSync\DAV\CopyNamePlugin;
use OCA\GrafanaSync\DAV\LinkWriteGuardPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Attaches this app's Sabre (WebDAV) plugins to every server the DAV app spins up.
 *
 * The DAV app fires {@see SabrePluginAddEvent} while assembling each server instance, which
 * is the supported seam for a third-party app to register its own Sabre plugin.
 *
 * Both plugins are here for the same underlying reason: **some things can only be decided
 * before the request runs.** {@see LinkWriteGuardPlugin} refuses WebDAV writes to link-mode
 * dashboard files, because by the time a node event fires the bytes are already committed.
 * {@see CopyNamePlugin} renames a copy's destination, because by the time a node event fires
 * the file exists under the name the browser picked and Nextcloud is holding locks on it.
 *
 * @implements IEventListener<SabrePluginAddEvent>
 */
final class RegisterDavPluginsListener implements IEventListener {
	public function __construct(
		private LinkWriteGuardPlugin $linkWriteGuard,
		private CopyNamePlugin $copyName,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->linkWriteGuard);
		$event->getServer()->addPlugin($this->copyName);
	}
}
