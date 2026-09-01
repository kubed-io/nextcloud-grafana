<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\DAV;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\MoveRules;
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Refuses to let a `link`-mode dashboard file be overwritten over WebDAV.
 *
 * A link is only a tiny pointer to a dashboard that lives in Grafana — there is no full
 * JSON on the Nextcloud side to change, and any byte written over it would just corrupt
 * the pointer. The Files UI already routes a link's row-click to "Open in Grafana" rather
 * than the editor, but a desktop client, a raw WebDAV PUT, or `curl` would otherwise
 * overwrite the pointer blindly. This plugin closes that door at the only reliable choke.
 *
 * Why a Sabre plugin and not a `BeforeNodeWrittenEvent` listener: that event is produced
 * from the legacy `write` filesystem hook, and {@see \OCA\DAV\Connector\Sabre\File::put()}
 * only emits that pre-write hook on the non-part-file branch. Almost every storage uploads
 * through a `.part` file first, so the pre-write event never fires for a normal PUT and the
 * write slips through. Sabre's `beforeWriteContent` is emitted by the Sabre server *before*
 * `File::put()` runs, so it fires for every PUT regardless of the part-file dance.
 *
 * Throwing {@see Forbidden} is the native deny: Sabre answers the client with a clean
 * **403 Forbidden** and the bytes are never written. We also log a warning and raise a
 * Nextcloud notification so the user sees *why* the edit bounced and what to do.
 *
 * No loop guard is needed: the app's own link writes (the pull reconcile) go through the
 * View/Node API, not Sabre, so they never reach this plugin.
 */
final class LinkWriteGuardPlugin extends ServerPlugin {
	public function __construct(
		private DashboardMetadata $metadata,
		private MappingService $mappings,
		private MoveRules $rules,
		private IRootFolder $rootFolder,
		private SyncNotifier $notifier,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/** Kept from {@see initialize} so {@see onDelete} and {@see onCopy} can resolve paths. */
	private ?Server $server = null;

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		// Run early (low priority number = higher precedence) so we refuse before any
		// bytes are streamed to the part file.
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
		// The other half of the same door: refusing an OVERWRITE is no use if a link
		// folder will happily accept a brand-new file beside the pointers.
		$server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 10);
		// COPY IS NEITHER A WRITE NOR AN UNBIND, so neither hook above sees it, and the
		// typed `BeforeNodeCopiedEvent` is no help on its own: aborting it stops the copy
		// but Sabre still answers 201, so the user is told it worked and no file appears.
		// Measured in a pod on the n8n sibling — the mechanism is core's, not the app's.
		// {@see \OCA\GrafanaSync\Listener\CopyGuardListener} carries that event for the
		// non-DAV routes; this is the one a person sees.
		//
		// `method:COPY` rather than `beforeBind`: it fires only for a copy, and it HANDS
		// OVER the request, so the source path needs no reaching into `Server::$httpRequest`
		// — an untyped public property psalm will not resolve. The priority runs it ahead
		// of Sabre's own `httpCopy` (100).
		$server->on('method:COPY', [$this, 'onCopy'], 10);
		// AND `method:PUT`, FOR THE SAME REASON `method:COPY` IS HERE. `beforeCreateFile`
		// is the hook that ought to catch a new dashboard file landing in a link folder,
		// and three CI runs measured a live PUT through the Files "New" menu answering
		// 201 with that handler in place — it is never emitted on this route. Measured,
		// not deduced: the app's own listener on `BeforeNodeWrittenEvent` DID fire on the
		// same request and DID abort, and Sabre still answered 201, because the storage
		// layer swallows AbortedEventException exactly as `View::copy()` does. Refusing
		// from the method handler is the one place a 403 actually reaches the client.
		$server->on('method:PUT', [$this, 'onPut'], 10);
		// AND `method:MOVE`, WHICH IS THE THIRD TIME THIS LESSON HAS BEEN LEARNED.
		// {@see \OCA\GrafanaSync\Listener\MoveGuardListener} stops a refused move on
		// every route there is — but `HookConnector::rename()` catches the abort by name,
		// logs the message and sets `run = false`, and `Directory::moveInto()` then
		// answers `throw new Forbidden('')` with an empty string. So a person dragging a
		// link into another folder got a failure dialog with nothing in it.
		//
		// Nothing else can be thrown from that listener instead: `OC_Hook::emit()` wraps
		// each slot in `catch (Throwable)` and carries on, so any other exception is
		// logged and the move SUCCEEDS. Measured, both halves — see MoveGuardListener.
		// Refusing here is what makes the reason reach the client.
		$server->on('method:MOVE', [$this, 'onMove'], 10);
		// AND `method:DELETE`, WHICH IS THE FOURTH TIME, AND THE ONE THE NOTES SAID
		// COULD NOT BE DONE. {@see \OCA\GrafanaSync\Listener\FolderDeleteListener}
		// already refuses to trash a link-mapped folder and says why — but
		// `Directory::delete()` goes through `View::rmdir()`, whose hook layer swallows
		// the abort and answers a bare **403 with no body**. Measured in CI: the log held
		// the whole sentence and the client was told nothing at all.
		//
		// A guard for this once lived in `beforeUnbind` and broke every link MOVE, because
		// sabre routes a MOVE through the source's unbind as well as a DELETE. That is the
		// lesson `method:COPY` above already encodes: a `method:*` hook fires for ONE verb,
		// so refusing here cannot touch a move. Same fix, same shape, fourth verb.
		$server->on('method:DELETE', [$this, 'onDelete'], 10);
	}

	/**
	 * Refuse a DELETE of a link-mapped FOLDER, in words the user can read.
	 *
	 * THE LISTENER IS STILL THE GUARD; this is the voice. `FolderDeleteListener` stops the
	 * delete on every route there is, including the ones that never touch Sabre — and it
	 * stays exactly as it is, because a plugin cannot cover `occ`, a background job or the
	 * Files app's own internals. What it cannot do is get its sentence to the client, so
	 * this refuses first, with the same sentence, and the listener never runs.
	 *
	 * FILES ARE REFUSED HERE TOO, in the branch above the folder one. That refusal used
	 * to live in `beforeUnbind`, which Sabre also emits for the source of a MOVE — so it
	 * refused every link move as though it were a delete. One verb per handler is what
	 * keeps the two gestures apart.
	 *
	 * FAILING OPEN IS THE RULE HERE, AS EVERYWHERE IN THIS PLUGIN: a path that cannot be
	 * resolved is left to the listener, which is behind this and refuses anyway.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 * @return bool always true — this handler either throws or hands the request on
	 */
	public function onDelete(RequestInterface $request, ResponseInterface $response): bool {
		if ($this->server === null) {
			return true;
		}
		$path = $request->getPath();
		try {
			$node = $this->server->tree->getNodeForPath($path);
		} catch (\Throwable) {
			return true;
		}
		// THE FILE BRANCH, WHICH USED TO LIVE IN `beforeUnbind` AND COULD NOT STAY THERE.
		//
		// Sabre routes a MOVE through the source's unbind as well as a DELETE, so a
		// refusal in that hook answered both — and answered the move in the voice of a
		// delete, telling the user a link "can't be deleted here" for a gesture that
		// deletes nothing. A link move IS refused, by {@see MoveRules}; being refused
		// for the wrong reason is its own defect, because the sentence is the only part
		// of a refusal the user can act on.
		//
		// This is the lesson `method:COPY` and the folder branch below already encode,
		// now applied to the last hook that had not learned it: a `method:*` handler
		// fires for ONE verb, so each gesture is answered in its own words. `onMove` has
		// adjudicated every move by the time one gets this far.
		//
		// Nothing is lost by leaving `beforeUnbind` behind. Every move of a link is
		// refused by `onMove`, and a COPY landing on an existing link is refused by
		// {@see onCopy} at both ends.
		if ($this->isLinkFile($node)) {
			$name = $node->getName();
			$this->logger->warning('grafana_sync: refused a WebDAV delete of a link-mode dashboard file', [
				'app' => Application::APP_ID,
				'fileId' => $node->getId(),
				'file' => $name,
			]);
			throw new Forbidden(
				'“' . $name . '” is a linked Grafana dashboard — only a pointer to a dashboard that lives in Grafana, '
				. 'so it can’t be deleted here. Delete the dashboard in Grafana, '
				. 'or remove the mapping itself.',
			);
		}
		if (!$this->isLinkMappedFolder($path, $node)) {
			return true;
		}
		$this->logger->warning('grafana_sync: refused a WebDAV delete of a link-mapped folder', [
			'app' => Application::APP_ID,
			'path' => $path,
		]);
		// THE SAME SENTENCE THE LISTENER THROWS, deliberately duplicated rather than
		// shared: the listener's copy has to survive whether or not this plugin is even
		// loaded, and a constant shared between a DAV plugin and an event listener would
		// tie the two lifetimes together for one string.
		throw new Forbidden(
			'This folder mirrors Grafana, which owns the folders under a link mapping. '
			. 'Delete it in Grafana and the mirror will follow.',
		);
	}

	/**
	 * Refuse a MOVE the rules refuse, in words the user can read.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 * @return bool always true — this handler either throws or hands the request on
	 *
	 * The rules are {@see MoveRules}, shared with the listener rather than restated, so
	 * the two answers cannot drift apart. Everything this adds is the translation: Sabre
	 * works in `files/<uid>/<relative>` and the rest of the app in `/<uid>/files/<relative>`.
	 *
	 * FAILING OPEN IS THE RULE HERE, AS EVERYWHERE IN THIS PLUGIN. A source that cannot be
	 * resolved or a destination Sabre cannot place leaves the move alone — the listener is
	 * still behind it, and a guard that blocks on doubt is worse than the thing guarded.
	 */
	public function onMove(RequestInterface $request, ResponseInterface $response): bool {
		$destination = $request->getHeader('Destination');
		if ($destination === null || $destination === '' || $this->server === null) {
			return true;
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return true;
		}
		try {
			$targetDav = $this->server->calculateUri($destination);
			$source = $this->rootFolder->getUserFolder($uid)->get($this->relativeTo($request->getPath()));
		} catch (\Throwable) {
			return true;
		}
		$targetRelative = $this->relativeTo($targetDav);
		if ($targetRelative === '') {
			return true;
		}

		$refusal = $this->rules->refusalFor($source, '/' . $uid . '/files/' . $targetRelative, basename($targetRelative));
		if ($refusal === null) {
			return true;
		}
		$this->logger->warning('grafana_sync: refused a WebDAV move', [
			'app' => Application::APP_ID,
			'from' => $request->getPath(),
			'to' => $targetDav,
		]);
		throw new Forbidden($refusal);
	}

	/** `files/<uid>/<relative>` as Sabre spells it → the `<relative>` the app works in. */
	private function relativeTo(string $davPath): string {
		$relative = preg_replace('#^files/[^/]+/#', '', ltrim($davPath, '/'));
		return is_string($relative) ? $relative : '';
	}

	/**
	 * Refuse a COPY that involves a link, in either direction, with a message.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 *
	 * ## TWO REFUSALS, ONE HOOK, BECAUSE A COPY HAS TWO ENDS
	 *
	 * **A link is not copyable.** It is a read-only projection of a dashboard that lives
	 * in Grafana; duplicating the pointer does not duplicate anything, it just makes a
	 * second file claiming the same dashboard. The same reasoning already refuses editing
	 * one ({@see beforeWriteContent}) and deleting one ({@see onDelete}) — copy was
	 * the hole left in a rule the other two state.
	 *
	 * **A link mapping is not a destination.** Its folder is filled from the Grafana
	 * folder it mirrors and from nothing else, so a file put there by hand is at best
	 * ignored and at worst pruned by the next pull.
	 *
	 * ## FAILING OPEN IS THE RULE HERE, AS EVERYWHERE IN THIS PLUGIN
	 *
	 * Every lookup that cannot answer leaves the copy alone. A guard that blocks on doubt
	 * turns a missing mapping or an unreadable node into a user who cannot copy their own
	 * files, which is worse than the thing being guarded against.
	 */
	public function onCopy(RequestInterface $request, ResponseInterface $response): bool {
		$this->refuseIfSourceIsALink($request->getPath());

		$destination = $request->getHeader('Destination');
		if ($destination !== null && $destination !== '' && $this->server !== null) {
			try {
				$path = $this->server->calculateUri($destination);
			} catch (\Throwable) {
				return true; // a destination Sabre cannot place is not ours to judge
			}
			$this->refuseIfDestinationIsALinkMapping($path);
		}
		return true;
	}

	/**
	 * Refuse a PUT that would author a dashboard file into a link mapping.
	 *
	 * @param ResponseInterface $response unused; part of Sabre's `method:*` signature
	 * @return bool always true — this handler either throws or hands the request on
	 *
	 * ONLY THE DESTINATION IS JUDGED, because a PUT has one end. Overwriting an EXISTING
	 * link file is refused too, by {@see beforeWriteContent}, which does fire on that
	 * route — the node exists there, so Sabre takes its other branch. This handler is the
	 * missing half: the branch where the file does not exist yet.
	 */
	public function onPut(RequestInterface $request, ResponseInterface $response): bool {
		$path = $request->getPath();
		// DASHBOARD FILES ONLY, unlike the COPY end of this plugin, which refuses anything
		// bound into a link mapping. A link mapping's one concession is that other file
		// types may live alongside the mirrored dashboards, and a PUT is how they get
		// there — every upload, every editor save, every desktop-client sync of an
		// unrelated file passes through here. Refusing on the folder alone would turn a
		// link mapping into a read-only folder, which is not the rule.
		if (!FilenameCodec::isDashboardName(basename($path))) {
			return true;
		}
		$this->refuseIfDestinationIsALinkMapping($path);
		return true;
	}

	/** The source of the COPY — the path the request was made against. */
	private function refuseIfSourceIsALink(string $source): void {
		try {
			$node = $this->server?->tree->getNodeForPath($source);
		} catch (\Throwable) {
			return;
		}
		if ($this->isLinkMappedFolder($source, $node)) {
			$name = $node->getName();
			$this->logger->warning('grafana_sync: refused a WebDAV copy of a folder holding linked dashboards', [
				'app' => Application::APP_ID,
				'folder' => $name,
			]);
			throw new Forbidden(
				'“' . $name . '” holds linked Grafana dashboards — pointers to dashboards that live in Grafana, '
				. 'so there is nothing here to copy. Duplicate them in Grafana instead, and they will appear here '
				. 'on the next sync.',
			);
		}
		if (!$this->isLinkFile($node)) {
			return;
		}

		$name = $node->getName();
		$this->logger->warning('grafana_sync: refused a WebDAV copy of a link-mode dashboard file', [
			'app' => Application::APP_ID,
			'fileId' => $node->getId(),
			'file' => $name,
		]);
		throw new Forbidden(
			'“' . $name . '” is a linked Grafana dashboard — only a pointer to a dashboard that lives in Grafana, '
			. 'so there is nothing here to copy. Duplicate the dashboard in Grafana instead, and it will '
			. 'appear here on the next sync.',
		);
	}

	/**
	 * The destination a COPY or a PUT is binding to. The node does not exist yet, so the
	 * mapping is resolved from the PATH — built the way the rest of the app spells an
	 * internal path (`/<uid>/files/<relative>`), which is what
	 * {@see MappingService::resolveForPath} is given everywhere else.
	 */
	private function refuseIfDestinationIsALinkMapping(string $path): void {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return;
		}
		$relative = preg_replace('#^files/[^/]+/#', '', ltrim($path, '/'));
		if (!is_string($relative) || $relative === '') {
			return;
		}
		try {
			$mapping = $this->mappings->resolveForPath('/' . $uid . '/files/' . $relative);
		} catch (\Throwable) {
			return;
		}
		if ($mapping === null || $mapping->mode !== Mapping::MODE_LINK) {
			return;
		}

		// NEUTRAL WORDING: shared by the COPY and PUT ends, which are the same refusal
		// about the same destination.
		$this->logger->warning('grafana_sync: refused a WebDAV write into a link mapping', [
			'app' => Application::APP_ID,
			'path' => $relative,
			'mapping' => $mapping->id,
		]);
		throw new Forbidden(
			'“' . $mapping->ncFolder . '” mirrors a Grafana folder in link mode, so its contents come from Grafana '
			. 'and files can’t be added here. Create the dashboard in Grafana instead, or switch the mapping '
			. 'to sync mode to author dashboards in Nextcloud.',
		);
	}

	/**
	 * Is this a folder that a LINK MAPPING still covers, holding linked dashboards?
	 *
	 * ## THE MAPPING IS HALF THE QUESTION, AND IT WAS MISSING
	 *
	 * {@see isLinkFile} reads a FILE's own stamp, which is right for a file: a
	 * pointer is a pointer wherever it sits. Asking the same of a folder was not,
	 * because a folder is only "a link folder" while a link mapping says so — and a
	 * mapping can be removed.
	 *
	 * Without this the refusal outlived the mapping. Remove a link mapping and its
	 * folder became undeletable: the files inside were still stamped `reference`, so
	 * the guard kept refusing on behalf of a mapping that no longer existed, and the
	 * only way out was to delete the files one at a time from the inside. The
	 * integration suite hit it as a teardown that could never clean up after a link
	 * mapping, which left `Pointers` standing between scenarios.
	 *
	 * Unmapped, those files are nobody's pointers any more — they are the user's, and
	 * so is the folder holding them.
	 */
	private function isLinkMappedFolder(string $davPath, ?INode $node): bool {
		if ($node === null || !$this->holdsLinkedDashboards($node)) {
			return false;
		}
		return $this->linkMappingAt($davPath) !== null;
	}

	/**
	 * The link mapping covering a Sabre path, or null.
	 *
	 * Shares its spelling with {@see refuseIfDestinationIsALinkMapping}: Sabre works
	 * in `files/<uid>/<relative>` and the rest of the app in `/<uid>/files/<relative>`.
	 */
	private function linkMappingAt(string $davPath): ?Mapping {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return null;
		}
		$relative = preg_replace('#^files/[^/]+/#', '', ltrim($davPath, '/'));
		if (!is_string($relative) || $relative === '') {
			return null;
		}
		try {
			$mapping = $this->mappings->resolveForPath('/' . $uid . '/files/' . $relative);
		} catch (\Throwable) {
			return null;
		}
		return $mapping !== null && $mapping->mode === Mapping::MODE_LINK ? $mapping : null;
	}

	/**
	 * Is this a FOLDER whose dashboards are links?
	 *
	 * ## THE SOURCE GUARD ONLY EVER LOOKED AT FILES
	 *
	 * `refuseIfSourceIsALink` asked `isLinkFile`, so copying a link-mapped FOLDER out
	 * of its mapping was waved through — the destination half caught a copy INTO a
	 * link mapping, and nothing caught a copy out of one. A WebDAV COPY of a
	 * collection is one request that Sabre satisfies recursively on the server, so
	 * the per-file guard never fires for the files inside it either. Three pointers
	 * would have landed in a sync mapping as if they were authored there.
	 *
	 * ## AND A FOLDER WITH NO DASHBOARDS IS NOT OURS TO REFUSE
	 *
	 * A folder is mirrored into Grafana only when something in it is, so a
	 * dashboard-less folder under a link mapping is not in Grafana at all — it merely
	 * sits beneath a mapped one. Nextcloud owns it outright and copying it is an
	 * ordinary file-manager gesture. Refusing that would be this app taking over a
	 * folder it has never touched, which is the same line
	 * {@see \OCA\GrafanaSync\Service\SyncService::isManagedFolder} draws on the
	 * pull side.
	 */
	private function holdsLinkedDashboards(INode $node): bool {
		// DUCK-TYPED, because `Sabre\DAV\ICollection` is not in `tests/external-stubs.php`
		// and Psalm reports it as an undefined class — the stubs carry only the handful of
		// Sabre types this app touches. Asking whether the node can list children is the
		// same question `instanceof` would ask, and it needs no stub. Same shape as
		// {@see \OCA\GrafanaSync\DAV\TrashRestorePlugin}, where a trash node's
		// `getFileId()` is reached the same way.
		if (!method_exists($node, 'getChildren')) {
			return false;
		}
		try {
			foreach ($node->getChildren() as $child) {
				if (!$child instanceof INode) {
					continue;
				}
				if ($this->isLinkFile($child) || $this->holdsLinkedDashboards($child)) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			// CANNOT LOOK → DO NOT REFUSE. A guard that blocks a copy because a
			// listing failed would take ordinary folders down with it.
			$this->logger->debug('grafana_sync: could not inspect a folder being copied; allowing', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
		return false;
	}

	/**
	 * Is this DAV node one of our link-mode dashboard files? Classified from the
	 * file's own metadata, and ANY doubt — wrong node type, foreign name, an
	 * unreadable stamp — answers no: everything this plugin does is a refusal,
	 * so failing open is what keeps a metadata hiccup from blocking a user.
	 *
	 * `@psalm-assert-if-true` narrows $node for the caller's true branch, which
	 * is what lets the refusal sites call getId()/getName() without re-checking.
	 *
	 * @psalm-assert-if-true DavFile $node
	 */
	private function isLinkFile(?INode $node): bool {
		if (!$node instanceof DavFile || !FilenameCodec::isDashboardName($node->getName())) {
			return false;
		}
		try {
			return $this->metadata->read($node->getId())?->isLink() ?? false;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Refuses a NEW dashboard file authored into a `link`-mapped folder.
	 *
	 * A link mapping is a read-only projection of Grafana: the tree, the dashboards
	 * and the shape of both belong over there, and Nextcloud mirrors them. A file
	 * created here can never become the dashboard it looks like — the app will not
	 * author into a link folder — so accepting it leaves a `.grafana` that looks
	 * managed, is not, and never will be. Refusing at the door is the only honest
	 * answer.
	 *
	 * NOT a blanket lock on the folder. Anything that is not a dashboard file is
	 * waved through, because a link mapping's one concession is exactly that: other
	 * file types may live alongside the mirrored dashboards.
	 *
	 * ## THE PARENT NODE IS ASKED WHERE IT IS, NOT THE DAV PATH
	 *
	 * `$path` here is Sabre's request path (`files/<uid>/<relative>`), which is NOT
	 * the shape {@see MappingService::resolveForPath()} reads — that wants a node
	 * path, `/<uid>/files/<relative>`. Building one by string-munging produced
	 * `/files/files/<uid>/…`, which matched no mapping at all, so the guard was inert
	 * and every link folder happily accepted new dashboard files.
	 *
	 * The parent collection knows its own node path, so we ask it. Anything that is
	 * not a Nextcloud DAV directory is waved through — we cannot classify it, and a
	 * guard that cannot classify must never block.
	 *
	 * @param mixed $data
	 * @param mixed $parent
	 * @param bool|null $modified
	 */
	public function beforeCreateFile(string $path, &$data, $parent, &$modified): bool {
		$name = basename($path);
		if (!FilenameCodec::isDashboardName($name)) {
			return true; // a spreadsheet in a link folder is entirely welcome
		}
		if (!$parent instanceof DavDirectory) {
			return true; // cannot classify → never block
		}

		try {
			$mapping = $this->mappings->resolveForPath(rtrim($parent->getPath(), '/') . '/' . $name);
		} catch (\Throwable) {
			return true; // cannot classify → never block
		}
		if ($mapping === null || $mapping->mode !== Mapping::MODE_LINK) {
			return true;
		}

		$this->logger->warning('grafana_sync: refused a new dashboard file in a link-mapped folder', [
			'app' => Application::APP_ID,
			'path' => $path,
			'mapping' => $mapping->id,
		]);

		throw new Forbidden(
			'“' . $name . '” cannot be created here: “' . $mapping->ncFolder . '” mirrors a Grafana folder in link mode, '
			. 'so its dashboards are Grafana\'s to create. Make the dashboard in Grafana and it will appear here, '
			. 'or switch the folder mapping to sync mode to author dashboards from Nextcloud.',
		);
	}

	/**
	 * @param resource|string|null $data the body Sabre is about to write, untouched here
	 * @param bool|null $modified Sabre's out-parameter; this guard never sets it
	 *
	 * TYPED BECAUSE PSALM ASKED, and the types are Sabre's rather than ours: `httpPut`
	 * hands a stream for a normal request and a string for a small one, and `$modified`
	 * is an out-parameter a plugin sets only when it rewrites the body. Widening either
	 * would be wrong; narrowing would break the signature Sabre calls.
	 */
	public function beforeWriteContent(string $path, INode $node, mixed &$data, mixed &$modified): bool {
		if (!$node instanceof DavFile) {
			return true; // not a file node we care about
		}
		$name = $node->getName();
		if (!FilenameCodec::isDashboardName($name)) {
			return true; // only our dashboard files are constrained
		}

		// Classify the file from its own metadata. Anything we can't read is NOT a link,
		// so we must never block it — fail open on any doubt.
		try {
			$fileId = $node->getId();
			$managed = $this->metadata->read($fileId);
		} catch (\Throwable) {
			return true;
		}
		if (!$managed?->isLink()) {
			return true; // not a link — sync/unmapped hold full JSON and may be edited
		}

		$this->logger->warning('grafana_sync: refused a WebDAV edit to a link-mode dashboard file', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'file' => $name,
		]);

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->notifier->linkEditBlocked($uid, $fileId, $name);

		throw new Forbidden(
			'“' . $name . '” is a linked Grafana dashboard — only a pointer to a dashboard that lives in Grafana, '
			. 'so its file can’t be edited here. Switch its folder mapping to sync mode to edit the JSON locally, '
			. 'or open it in Grafana to make changes.',
		);
	}
}
