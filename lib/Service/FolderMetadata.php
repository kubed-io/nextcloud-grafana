<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;

/**
 * The one key this app writes on **folders**: which Grafana folder a Nextcloud
 * folder mirrors.
 *
 * `grafana_folder_uid` is the folder's half of the identity model that
 * {@see DashboardMetadata} provides for files. It is what makes a folder rename or a
 * folder move a rename or a move rather than a delete plus a create — read by NAME,
 * a re-parented folder is one folder vanishing and another appearing, and every
 * dashboard inside it would be destroyed and re-minted under new uids. Read by uid,
 * it is the same folder somewhere else.
 *
 * ## WHY A SECOND CLASS RATHER THAN A KEY ON DashboardMetadata
 *
 * That class models a managed DASHBOARD FILE — its whole read path returns a
 * {@see ManagedFile}, and every key on it describes a dashboard. A folder is not a
 * half-populated dashboard, and threading one through that shape would mean a
 * ManagedFile whose uid, mode, version and hash are all permanently empty.
 *
 * ## NOT TO BE CONFUSED WITH `grafana_folderUid`
 *
 * `DashboardMetadata::KEY_FOLDER_UID` (`grafana_folderUid`, camelCase) is a **file**
 * key recording the Grafana folder a dashboard sits in. It is a denormalisation:
 * with folders carrying their own uid, a dashboard's Grafana folder is simply its
 * parent's, and a second copy on every file can go stale. It is on its way out —
 * see {@see FolderMirror} for the resolution order that replaces it.
 *
 * INDEXED, so "which folders does this app mirror?" is a query rather than a walk of
 * the tree. The pull needs exactly that question answered cheaply on every run.
 */
final class FolderMetadata {
	/**
	 * Snake_case deliberately, and NOT one character away from the file key it
	 * replaces — see the class docblock. A reader who sees both in a diff must be
	 * able to tell which is which at a glance.
	 */
	public const KEY_FOLDER_UID = 'grafana_folder_uid';

	public function __construct(
		private IFilesMetadataManager $manager,
	) {
	}

	/**
	 * Idempotently register the key with the Files Metadata system, so it is surfaced
	 * over DAV as `{nc:}metadata-grafana_folder_uid` and is searchable.
	 *
	 * EDIT_FORBIDDEN: this is the app's record of an identity Grafana owns. A user
	 * editing it by hand could point a folder at someone else's Grafana folder and
	 * silently redirect every dashboard created underneath.
	 */
	public function register(): void {
		$this->manager->initMetadata(
			self::KEY_FOLDER_UID,
			IMetadataValueWrapper::TYPE_STRING,
			true, // indexed → searchable
			IMetadataValueWrapper::EDIT_FORBIDDEN,
		);
	}

	/**
	 * The Grafana folder uid this Nextcloud folder mirrors, or `''` when it mirrors
	 * nothing — which is the ordinary case. A folder under a mapping is a plain
	 * folder until a dashboard lands beneath it.
	 */
	public function uidOf(int $folderId): string {
		try {
			$metadata = $this->manager->getMetadata($folderId, false);
		} catch (FilesMetadataNotFoundException) {
			return '';
		}
		return $metadata->hasKey(self::KEY_FOLDER_UID)
			? $metadata->getString(self::KEY_FOLDER_UID)
			: '';
	}

	/**
	 * Record that this Nextcloud folder mirrors that Grafana folder.
	 *
	 * An empty uid is refused rather than stored: empty does not read as "unknown"
	 * downstream, it reads as *this folder is not a Grafana folder*, and writing one
	 * would quietly un-mirror the folder while looking like a successful stamp.
	 */
	public function stamp(int $folderId, string $uid): void {
		if ($uid === '') {
			throw new \InvalidArgumentException('Refusing to stamp an empty Grafana folder uid.');
		}
		$metadata = $this->manager->getMetadata($folderId, true);
		$metadata->setString(self::KEY_FOLDER_UID, $uid, true);
		$this->manager->saveMetadata($metadata);
	}

	/**
	 * Forget the mirror. Used when the Grafana folder is gone and the Nextcloud one
	 * survives as an ordinary folder — `folders/delete.feature`'s "holds other files
	 * too" case, where the mirror is stripped rather than the folder destroyed.
	 * Idempotent.
	 */
	public function clear(int $folderId): void {
		$this->manager->deleteMetadata($folderId);
	}
}
