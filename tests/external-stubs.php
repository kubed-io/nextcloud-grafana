<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Declaration-only stubs for classes the app references from OTHER bundled apps
 * and from the Sabre/DAV library, neither of which is shipped in `nextcloud/ocp`.
 *
 * Two consumers, one file:
 *   - the unit bootstrap `require`s it so PHPUnit can generate doubles of
 *     {@see \OCA\DAV\Connector\Sabre\File} et al. for {@see \OCA\GrafanaSync\DAV\LinkWriteGuardPlugin};
 *   - `psalm.xml` loads it via `<stubs>` so static analysis resolves the same
 *     symbols instead of reporting UndefinedClass.
 *
 * They carry no behaviour — just enough surface (signatures) for the type system
 * and the mock builder. The real classes live in a running Nextcloud + Sabre.
 */

namespace Sabre\DAV {
	if (!interface_exists(INode::class, false)) {
		interface INode {
			public function getName(): string;
		}
	}
	if (!class_exists(Server::class, false)) {
		class Server {
			public function on(string $eventName, callable $callBack, int $priority = 100): bool {
				return true;
			}

			public function addPlugin(ServerPlugin $plugin): void {
			}
		}
	}
	if (!class_exists(ServerPlugin::class, false)) {
		abstract class ServerPlugin {
			abstract public function initialize(Server $server): void;
		}
	}
}

namespace Sabre\DAV\Exception {
	if (!class_exists(Forbidden::class, false)) {
		class Forbidden extends \Exception {
		}
	}
}

namespace OCA\DAV\Connector\Sabre {
	if (!class_exists(File::class, false)) {
		class File implements \Sabre\DAV\INode {
			public function getName(): string {
				return '';
			}

			public function getId(): int {
				return 0;
			}
		}
	}
}

namespace OCA\DAV\Events {
	if (!class_exists(SabrePluginAddEvent::class, false)) {
		class SabrePluginAddEvent extends \OCP\EventDispatcher\Event {
			public function getServer(): \Sabre\DAV\Server {
				return new \Sabre\DAV\Server();
			}
		}
	}
}

namespace OCA\Files\Event {
	// Fired by the bundled Files app right before it emits its <script> tags (not shipped
	// in nextcloud/ocp). Stubbed so the script-load listener's
	// `@implements IEventListener<LoadAdditionalScriptsEvent>` type-checks cleanly.
	if (!class_exists(LoadAdditionalScriptsEvent::class, false)) {
		class LoadAdditionalScriptsEvent extends \OCP\EventDispatcher\Event {
		}
	}
}

namespace OCA\Files_Trashbin\Events {
	// The restore-from-trash event, owned by the bundled Files_Trashbin app (not shipped in
	// nextcloud/ocp). Stubbing it — rather than suppressing — lets Psalm resolve it as a real
	// Event subclass, so the restore listener's `@implements IEventListener<NodeRestoredEvent>`
	// type-checks cleanly, exactly as an OCP event would.
	if (!class_exists(NodeRestoredEvent::class, false)) {
		class NodeRestoredEvent extends \OCP\EventDispatcher\Event {
			public function getTarget(): \OCP\Files\Node {
				throw new \RuntimeException('stub');
			}
		}
	}
}
