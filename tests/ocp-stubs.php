<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
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

			/**
			 * The next four live on `FileInfo` upstream, which the real `Node` extends;
			 * the stub has no FileInfo, so they are declared here — the same place in
			 * the hierarchy. `getSize` is widened to `int|float` like the real
			 * signature, because the filecache size of a very large file exceeds PHP's
			 * int range on 32-bit.
			 */
			public function getSize(bool $includeMounts = true): int|float;

			public function getMTime(): int;

			public function getCreationTime(): int;

			public function getStorage(): \OCP\Files\Storage\IStorage;

			/** Sets the modification time — `$mtime` null means "now". */
			public function touch($mtime = null): void;
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

			// TrashPurgeHook resolves the legacy hook's trash-relative path against the
			// home folder's parent, since the trash sits beside /files rather than in it.
			public function get(string $path): Node;

			public function newFile(string $path, $content = null): File;

			// FolderTreeMirror creates a Nextcloud folder for each Grafana folder it
			// finds, so the pull can place dashboards in the folder that mirrors theirs.
			public function newFolder(string $path): Folder;

			// StorageService turns a mapping's stored folder id back into a path, and
			// TagChangeListener turns the bare file id a tag event carries into a node.
			public function getFirstNodeById(int $id): ?Node;
		}
	}
	// The storage root — declared after Folder, which it extends. TrashPurgeHook asks it
	// for a user's home so it can step up into /files_trashbin; TagChangeListener asks it
	// to turn the bare file id a tag event carries back into a node.
	if (!interface_exists(IRootFolder::class, false)) {
		interface IRootFolder extends Folder {
			public function getUserFolder(string $userId): Folder;
		}
	}
	if (!interface_exists(IMimeTypeLoader::class, false)) {
		interface IMimeTypeLoader {
			public function getId(string $mimetype): int;

			public function updateFilecache(string $ext, int $mimetypeId): int;
		}
	}
}

namespace OCP\Files\Storage {
	// The first of the two hops {@see OCA\GrafanaSync\Service\MirrorTimes} takes to set
	// a creation time — there is no OCP setter for it, so the public cache API is the
	// supported route (Node::getStorage -> IStorage::getCache -> ICache::update).
	if (!interface_exists(IStorage::class, false)) {
		interface IStorage {
			public function getCache(string $path = '', ?IStorage $storage = null): \OCP\Files\Cache\ICache;
		}
	}
}

namespace OCP\Files\Cache {
	if (!interface_exists(ICache::class, false)) {
		interface ICache {
			public function update($id, array $data);
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

			/**
			 * {@see OCA\GrafanaSync\Service\TrashControl} sets the acting user around a
			 * restore, because the home trash restores whoever is LOGGED IN rather than
			 * whoever it was asked about — a pull has no session, so the call needs one.
			 */
			public function setUser(?IUser $user): void;
		}
	}
	// TrashControl resolves the trashed item's owner to restore as them.
	if (!interface_exists(IUserManager::class, false)) {
		interface IUserManager {
			public function get(string $uid): ?IUser;
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

namespace OCP\Exceptions {
	// Thrown by the copy, create, delete and move guards to abort a gesture before it
	// happens — the ONE exception `OC_Hook::emit()` does not swallow on its way through,
	// because `HookConnector` catches it by name. The message it carries does not survive
	// that catch; {@see OCA\GrafanaSync\DAV\LinkWriteGuardPlugin} is what says why.
	if (!class_exists(AbortedEventException::class, false)) {
		class AbortedEventException extends \Exception {
		}
	}
}

namespace OCP\Files\Cache {
	// DECLARED HERE, NOT WITH ICache ABOVE, because it extends `OCP\EventDispatcher\Event`
	// and this file is read top to bottom — the Cache block above runs before the
	// EventDispatcher one, so the parent would not exist yet.
	// The signal {@see OCA\GrafanaSync\Listener\TeamFolderPurgeListener} rides, because
	// dropping the cache entry is the one thing NO trash backend can skip — groupfolders
	// emits neither the legacy hook nor a typed event, so this is all there is.
	// Constructor mirrors the real `AbstractCacheEvent`: storage, path, fileId, storageId.
	if (!class_exists(CacheEntryRemovedEvent::class, false)) {
		class CacheEntryRemovedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\Storage\IStorage $storage,
				private string $path,
				private int $fileId,
				private int $storageId,
			) {
			}

			public function getPath(): string {
				return $this->path;
			}

			public function getFileId(): int {
				return $this->fileId;
			}
		}
	}
}

namespace OCP\Files\Events\Node {
	// The two node events the name-sync / writeback / move listeners key off. nextcloud/ocp
	// ships no event classes, so the standalone unit suite needs constructable stubs to exercise
	// a listener directly. Just enough surface for `instanceof` + the getters the app calls.
	if (!class_exists(NodeWrittenEvent::class, false)) {
		class NodeWrittenEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\Node $node,
			) {
			}

			public function getNode(): \OCP\Files\Node {
				return $this->node;
			}
		}
	}
	// The pre-delete gate both delete listeners key off. Nextcloud fires exactly ONE of
	// these for a folder delete and none for its contents, which is the whole reason
	// FolderDeleteListener + FolderCascade exist — so the folder case has to be
	// constructable here to be tested at all.
	if (!class_exists(BeforeNodeDeletedEvent::class, false)) {
		class BeforeNodeDeletedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\Node $node,
			) {
			}

			public function getNode(): \OCP\Files\Node {
				return $this->node;
			}
		}
	}
	// The pre-move gate MoveGuardListener throws from. Constructable like its
	// post-move sibling, so the guard can be driven directly.
	if (!class_exists(BeforeNodeRenamedEvent::class, false)) {
		class BeforeNodeRenamedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\Node $source,
				private \OCP\Files\Node $target,
			) {
			}

			public function getSource(): \OCP\Files\Node {
				return $this->source;
			}

			public function getTarget(): \OCP\Files\Node {
				return $this->target;
			}
		}
	}
	// The pre-copy gate {@see OCA\GrafanaSync\Listener\CopyGuardListener} throws from.
	// It carries the SOURCE node, which is the only place "was this a link?" can still
	// be answered — by the time the post-copy event runs the stamp has been stripped.
	if (!class_exists(BeforeNodeCopiedEvent::class, false)) {
		class BeforeNodeCopiedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\Node $source,
				private \OCP\Files\Node $target,
			) {
			}

			public function getSource(): \OCP\Files\Node {
				return $this->source;
			}

			public function getTarget(): \OCP\Files\Node {
				return $this->target;
			}
		}
	}
	if (!class_exists(NodeRenamedEvent::class, false)) {
		class NodeRenamedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\Node $source,
				private \OCP\Files\Node $target,
			) {
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
	// EVERY declarative form in this app implements the handler pair — not because
	// each one needs custom storage, but because a single form left on INTERNAL
	// answers the storage question for all the others. See InstanceSettings.
	if (!interface_exists(IDeclarativeSettingsFormWithHandlers::class, false)) {
		interface IDeclarativeSettingsFormWithHandlers extends IDeclarativeSettingsForm {
			public function getValue(string $fieldId, \OCP\IUser $user): mixed;

			public function setValue(string $fieldId, mixed $value, \OCP\IUser $user): void;
		}
	}
	if (!class_exists(DeclarativeSettingsTypes::class, false)) {
		final class DeclarativeSettingsTypes {
			public const SECTION_TYPE_ADMIN = 'admin';
			public const STORAGE_TYPE_INTERNAL = 'internal';
			public const STORAGE_TYPE_EXTERNAL = 'external';
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

namespace OCP\Security {
	// GrafanaClient decrypts the stored service-account token on every request; the
	// client test mocks this to hand back a known plaintext.
	if (!interface_exists(ICrypto::class, false)) {
		interface ICrypto {
			public function encrypt(string $input, string $password = ''): string;

			public function decrypt(string $input, string $password = ''): string;
		}
	}
}

namespace OCP\Http\Client {
	// The GrafanaClient folder-write tests mock this trio to drive request() without a
	// network: IClientService hands out an IClient, whose verb methods return an
	// IResponse the client decodes. CONTRIBUTING names IClientService as a collaborator
	// to MOCK rather than a boundary to skip, so these exist to make that possible.
	//
	// `getBody()` is left untyped because the real one returns string|resource, and the
	// tests only ever hand back a string.
	if (!interface_exists(IResponse::class, false)) {
		interface IResponse {
			public function getBody();

			public function getStatusCode(): int;
		}
	}
	if (!interface_exists(IClient::class, false)) {
		interface IClient {
			public function get(string $uri, array $options = []): IResponse;

			public function post(string $uri, array $options = []): IResponse;

			public function put(string $uri, array $options = []): IResponse;

			// The app-platform folder API's verb, for a merge patch of one annotation.
			public function patch(string $uri, array $options = []): IResponse;

			public function delete(string $uri, array $options = []): IResponse;
		}
	}
	if (!interface_exists(IClientService::class, false)) {
		interface IClientService {
			public function newClient(): IClient;
		}
	}
	// Thrown by the real client when NC's SSRF guard refuses a local address.
	// GrafanaClient catches it BY TYPE, so it must be a distinct class here.
	if (!class_exists(LocalServerException::class, false)) {
		class LocalServerException extends \Exception {
		}
	}
}

namespace OCP\SystemTag {
	// The systemtag surface the tag engine speaks to. nextcloud/ocp ships no bodies,
	// and the unit suite drives NextcloudTags directly against these — the catalog
	// (ISystemTagManager) and the assignment table (ISystemTagObjectMapper) are two
	// separate things in Nextcloud, which is the whole reason that class exists.
	if (!interface_exists(ISystemTag::class, false)) {
		interface ISystemTag {
			public function getId(): string;

			public function getName(): string;
		}
	}
	if (!class_exists(TagNotFoundException::class, false)) {
		class TagNotFoundException extends \Exception {
		}
	}
	if (!interface_exists(ISystemTagManager::class, false)) {
		interface ISystemTagManager {
			/** @param list<string> $tagIds @return list<ISystemTag> */
			public function getTagsByIds($tagIds, ?\OCP\IUser $user = null): array;

			public function getTag(string $tagName, bool $userVisible, bool $userAssignable): ISystemTag;

			public function createTag(string $tagName, bool $userVisible, bool $userAssignable): ISystemTag;
		}
	}
	if (!interface_exists(ISystemTagObjectMapper::class, false)) {
		interface ISystemTagObjectMapper {
			/** @param list<string> $objIds @return array<string,list<int|string>> */
			public function getTagIdsForObjects($objIds, string $objectType): array;

			/** @param list<string> $tagIds */
			public function assignTags(string $objId, string $objectType, $tagIds);

			/** @param list<string> $tagIds */
			public function unassignTags(string $objId, string $objectType, $tagIds);
		}
	}
	// Dispatched by the mapper under its STRING event names, not dispatchTyped — which
	// is why TagChangeListener registers on the constants rather than the class.
	if (!class_exists(MapperEvent::class, false)) {
		class MapperEvent extends \OCP\EventDispatcher\Event {
			public const EVENT_ASSIGN = 'OCP\SystemTag\ISystemTagObjectMapper::assignTags';
			public const EVENT_UNASSIGN = 'OCP\SystemTag\ISystemTagObjectMapper::unassignTags';

			/** @param list<int> $tags */
			public function __construct(
				private string $event,
				private string $objectType,
				private string $objectId,
				private array $tags = [],
			) {
			}

			public function getEvent(): string {
				return $this->event;
			}

			public function getObjectType(): string {
				return $this->objectType;
			}

			public function getObjectId(): string {
				return $this->objectId;
			}

			/** @return list<int> */
			public function getTags(): array {
				return $this->tags;
			}
		}
	}
}
