<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The **Grafana recycle-bin folder** — the one optional setting that changes how deleting a
 * dashboard file behaves (`delete.feature`). Grafana has no native trash, so this is it:
 *
 *  - **OFF** (default): trashing a synced file is a *true* Grafana delete right then, and a
 *    restore re-creates the dashboard with a new id (its full JSON is safe in the file).
 *  - **ON**: trashing instead **moves the dashboard into the named folder** (id kept), a
 *    restore moves it back, and only *emptying the Nextcloud trash* deletes it for good.
 *
 * The admin names the folder by its human **title** (e.g. `nextcloud-trash`); this resolves it
 * to a uid at use-time via {@see GrafanaClient::resolveFolderUidByTitle}. Kept tiny and separate
 * so the branch ("are we in bin mode, and where is the bin?") reads in one place — {@see
 * DeleteService} asks; the two settings live on the "Sync Settings" declarative card.
 */
final class RecycleBin {
	/** Request-scoped memo of the resolved bin folder uid, so a bulk trash (e.g. a mapping
	 *  tear-down of many files) resolves `/api/folders` once, not once per file. */
	private ?string $memoUid = null;
	private bool $memoed = false;

	public function __construct(
		private IAppConfig $config,
		private GrafanaClient $grafana,
	) {
	}

	/** Whether the admin turned on id-preserving deletes (the bin folder is in use). */
	public function isEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, 'bin_enabled', false);
	}

	/** The configured bin folder's human title (empty when unset). */
	public function folderTitle(): string {
		return trim($this->config->getValueString(Application::APP_ID, 'bin_folder', ''));
	}

	/**
	 * Resolve the active bin folder's uid, or null when bin mode is off. When bin mode is ON
	 * but the folder is unset or not found in Grafana, this **throws** — the delete engine
	 * treats that as a hard failure and aborts the delete rather than silently doing a true
	 * delete the admin didn't ask for (never lose the id preservation they opted into).
	 *
	 * @throws \RuntimeException when enabled but the folder is unusable
	 */
	public function activeFolderUid(): ?string {
		if ($this->memoed) {
			return $this->memoUid;
		}
		if (!$this->isEnabled()) {
			return $this->remember(null);
		}
		$title = $this->folderTitle();
		if ($title === '') {
			throw new \RuntimeException('The Grafana recycle-bin folder is enabled but no folder name is set.');
		}
		$uid = $this->grafana->resolveFolderUidByTitle($title);
		if ($uid === null) {
			throw new \RuntimeException('The configured Grafana recycle-bin folder "' . $title . '" was not found in Grafana.');
		}
		return $this->remember($uid);
	}

	/** Memoise a successfully resolved value (a throw is never cached, so a fixed config retries). */
	private function remember(?string $uid): ?string {
		$this->memoUid = $uid;
		$this->memoed = true;
		return $uid;
	}
}
