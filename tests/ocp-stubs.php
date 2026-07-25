<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Minimal OCP stubs for the standalone unit suite.
 *
 * `nextcloud/ocp` ships its public API as bare source with **no autoload block**,
 * so nothing under `OCP\` resolves outside a real Nextcloud server tree (verified:
 * `interface_exists(OCP\IConfig::class) === false` after a clean composer install).
 * Pure-logic tests (FilenameCodec, Mapping) never touch OCP and so don't care; but
 * any app class that merely *references* an OCP symbol to load — e.g.
 * {@see OCA\GrafanaSync\AppInfo\Application} (extended only for its `APP_ID` constant in
 * log context) — needs the base symbol to exist for its class declaration.
 *
 * These are declaration-only shims, just enough to let those classes autoload. They
 * carry no behaviour; collaborators that need real behaviour are mocked in the test.
 */

namespace OCP\AppFramework {
	if (!class_exists(App::class, false)) {
		class App {
			public function __construct(string $appName, array $urlParams = []) {
			}
		}
	}
}

namespace OCP\AppFramework\Bootstrap {
	if (!interface_exists(IBootstrap::class, false)) {
		interface IBootstrap {
			public function register(IRegistrationContext $context): void;

			public function boot(IBootContext $context): void;
		}
	}
	if (!interface_exists(IRegistrationContext::class, false)) {
		interface IRegistrationContext {
		}
	}
	if (!interface_exists(IBootContext::class, false)) {
		interface IBootContext {
		}
	}
}

namespace OCP\Files {
	// `File`/`Folder` (and their parent `Node`) are mocked in motion/listener/sync
	// tests; PHPUnit needs the interfaces to exist to generate the double.
	// Declaration-only — the real server provides the full surface; here we name
	// just what the tests call.
	if (!interface_exists(Node::class, false)) {
		interface Node {
			public function getId(): int;

			public function getName(): string;

			public function getPath(): string;

			public function getParent(): Folder;

			public function delete(): void;

			public function move(string $targetPath): Node;
		}
	}
	if (!interface_exists(File::class, false)) {
		interface File extends Node {
			public function getContent(): string;

			public function putContent($data): void;
		}
	}
	if (!interface_exists(Folder::class, false)) {
		interface Folder extends Node {
			/** @return list<Node> */
			public function getDirectoryListing(): array;

			public function nodeExists(string $path): bool;

			public function newFile(string $path, $content = null): File;
		}
	}
	if (!interface_exists(IMimeTypeLoader::class, false)) {
		interface IMimeTypeLoader {
			public function getId(string $mimetype): int;

			public function updateFilecache(string $ext, int $mimetypeId): int;
		}
	}
}

namespace OCP\BackgroundJob {
	// Passed to SyncService for the async dispatch path; the sync-path tests never
	// enqueue, so declaration-only is enough to satisfy the type.
	if (!interface_exists(IJobList::class, false)) {
		interface IJobList {
			public function add($job, $argument = null): void;
		}
	}
}

namespace OCP {
	// SyncService/MappingService read + write config via get/setValueString; declaration-only.
	if (!interface_exists(IAppConfig::class, false)) {
		interface IAppConfig {
			public function getValueString(string $app, string $key, string $default = '', bool $lazy = false, bool $sensitive = false): string;
			public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;
		}
	}
	// LinkWriteGuardPlugin resolves the acting user for its notification; both are
	// mocked in the plugin test, declaration-only here.
	if (!interface_exists(IUser::class, false)) {
		interface IUser {
			public function getUID(): string;
		}
	}
	if (!interface_exists(IUserSession::class, false)) {
		interface IUserSession {
			public function getUser(): ?IUser;
		}
	}
	// MappingSettings reads the group list for the per-mapping picker; both are
	// mocked in the SyncSettings test (which instantiates MappingSettings).
	if (!interface_exists(IGroup::class, false)) {
		interface IGroup {
			public function getGID(): string;
		}
	}
	if (!interface_exists(IGroupManager::class, false)) {
		interface IGroupManager {
			/** @return list<IGroup> */
			public function search(string $search): array;
		}
	}
}

namespace OCP\App {
	// MappingSettings checks whether groupfolders is installed (Team Folder flag).
	if (!interface_exists(IAppManager::class, false)) {
		interface IAppManager {
			public function isInstalled(string $appId): bool;
		}
	}
}

namespace OCP\FilesMetadata {
	// DashboardMetadata wraps the Files-Metadata API; the DashboardMetadata test drives
	// it through an in-memory fake of this manager to pin the link↔reference wire + the
	// loop-guard hash. Declaration-only, just the methods DashboardMetadata calls.
	if (!interface_exists(IFilesMetadataManager::class, false)) {
		interface IFilesMetadataManager {
			public function getMetadata(int $fileId, bool $generate = false): \OCP\FilesMetadata\Model\IFilesMetadata;

			public function saveMetadata(\OCP\FilesMetadata\Model\IFilesMetadata $filesMetadata): void;

			public function deleteMetadata(int $fileId): void;

			public function initMetadata(string $key, string $type, bool $indexed, int $editPermission): void;
		}
	}
}

namespace OCP\FilesMetadata\Model {
	if (!interface_exists(IFilesMetadata::class, false)) {
		interface IFilesMetadata {
			public function hasKey(string $needle): bool;

			public function getString(string $key): string;

			public function setString(string $key, string $value, bool $index = false): self;
		}
	}
	if (!interface_exists(IMetadataValueWrapper::class, false)) {
		interface IMetadataValueWrapper {
			public const TYPE_STRING = 'string';
			public const EDIT_FORBIDDEN = 0;
		}
	}
}

namespace OCP\FilesMetadata\Exceptions {
	if (!class_exists(FilesMetadataNotFoundException::class, false)) {
		class FilesMetadataNotFoundException extends \Exception {
		}
	}
}

namespace OCP\EventDispatcher {
	// Base event class other bundled-app events (e.g. SabrePluginAddEvent) extend;
	// PHP resolves the parent at declaration time, so the external-stubs file needs
	// this to exist first. Declaration-only.
	if (!class_exists(Event::class, false)) {
		class Event {
		}
	}
	// Implemented by every app listener; needed so a listener class can be autoloaded
	// (PHP resolves implemented interfaces at declaration time). Declaration-only.
	if (!interface_exists(IEventListener::class, false)) {
		interface IEventListener {
			public function handle(Event $event): void;
		}
	}
}

namespace OCP\Files\Events\Node {
	// The two node events the name-sync / writeback / move listeners key off. nextcloud/ocp
	// ships no event classes, so the standalone unit suite needs constructable stubs to exercise
	// a listener directly. Just enough surface for `instanceof` + the getters the app calls.
	if (!class_exists(NodeWrittenEvent::class, false)) {
		class NodeWrittenEvent extends \OCP\EventDispatcher\Event {
			public function __construct(private \OCP\Files\Node $node) {
			}

			public function getNode(): \OCP\Files\Node {
				return $this->node;
			}
		}
	}
	if (!class_exists(NodeRenamedEvent::class, false)) {
		class NodeRenamedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(private \OCP\Files\Node $source, private \OCP\Files\Node $target) {
			}

			public function getSource(): \OCP\Files\Node {
				return $this->source;
			}

			public function getTarget(): \OCP\Files\Node {
				return $this->target;
			}
		}
	}
}

namespace OCP\Settings {
	// InstanceSettings implements IDeclarativeSettingsForm and reads
	// DeclarativeSettingsTypes constants; the InstanceSettings test instantiates one
	// to assert its dynamic "is a token stored?" copy. Declaration-only — the constant
	// *values* are irrelevant to the assertions (they check id/sensitive/description/
	// placeholder), so any strings suffice.
	if (!interface_exists(IDeclarativeSettingsForm::class, false)) {
		interface IDeclarativeSettingsForm {
			public function getSchema(): array;
		}
	}
	if (!class_exists(DeclarativeSettingsTypes::class, false)) {
		final class DeclarativeSettingsTypes {
			public const SECTION_TYPE_ADMIN = 'admin';
			public const STORAGE_TYPE_INTERNAL = 'internal';
			public const TEXT = 'text';
			public const PASSWORD = 'password';
			public const URL = 'url';
			public const CHECKBOX = 'checkbox';
		}
	}
	// SyncSettings / MappingSettings implement IDelegatedSettings; the SyncSettings
	// test instantiates them to assert the panel ordering (priority). The classes
	// mark these methods #[\Override], so the stub must declare all five for the
	// attribute to be valid. `getForm()` is left untyped so the real
	// `: TemplateResponse` return is a compatible narrowing without needing the
	// TemplateResponse class stubbed here.
	if (!interface_exists(IDelegatedSettings::class, false)) {
		interface IDelegatedSettings {
			public function getForm();

			public function getSection(): string;

			public function getPriority(): int;

			public function getName(): ?string;

			public function getAuthorizedAppConfig(): array;
		}
	}
}
