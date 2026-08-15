<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
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
use OCA\GrafanaSync\Service\SyncNotifier;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

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
		private SyncNotifier $notifier,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		// Run early (low priority number = higher precedence) so we refuse before any
		// bytes are streamed to the part file.
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
		// The other half of the same door: refusing an OVERWRITE is no use if a link
		// folder will happily accept a brand-new file beside the pointers.
		$server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 10);
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

	public function beforeWriteContent(string $path, INode $node, &$data, &$modified): bool {
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
