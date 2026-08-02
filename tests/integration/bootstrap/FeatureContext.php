<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration;

use Behat\Behat\Context\Context;
use GuzzleHttp\Client;
use OCA\GrafanaSync\Tests\Integration\Steps\AdminSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\AppLifecycleSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\LifecycleSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\MappingSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\RenameSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\SyncSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\TrashSteps;
use OCA\GrafanaSync\Tests\Integration\Support\GrafanaApiTrait;
use OCA\GrafanaSync\Tests\Integration\Support\OccTrait;
use OCA\GrafanaSync\Tests\Integration\Support\SetupTrait;
use OCA\GrafanaSync\Tests\Integration\Support\WebDavTrait;

/**
 * Behat context for the grafana_sync integration suite.
 *
 * Thin by design: it owns the shared per-scenario state, the constructor that
 * wires transports from the environment, and teardown. Every step definition
 * lives in a per-concern trait composed in below (mirrors how nextcloud/server
 * composes its Behat context from traits).
 *
 * Wired so far: the connection + lifecycle concerns (the admin appetizer) and the
 * folder-mapping concern. As the sync chapters land, the remaining `*Steps` traits
 * (Create, Rename, Delete, Move, …) return here, and their features flip off
 * `@todo`.
 *
 * Transport channels:
 *  - **occ** (the $OCC env var) drives admin setup the way our CLI commands do. → OccTrait
 *  - **WebDAV** (Guzzle, basic-auth) writes/reads/PROPFINDs files the way the
 *    desktop client / web UI would. → WebDavTrait
 *  - **Grafana REST** (Guzzle, Bearer) is the assertion side: did the app create /
 *    update / delete the dashboard in Grafana? → GrafanaApiTrait
 */
final class FeatureContext implements Context {
	use OccTrait;
	use WebDavTrait;
	use GrafanaApiTrait;
	use SetupTrait;
	use AppLifecycleSteps;
	use AdminSteps;
	use MappingSteps;
	use SyncSteps;
	use LifecycleSteps;
	use TrashSteps;
	use RenameSteps;

	private const APP_ID = 'grafana_sync';

	/**
	 * The DAV-exposed metadata key for the dashboard uid — the stable file↔dashboard
	 * link. Redeclared here as a literal because the integration suite autoloads only
	 * its own bootstrap/, not the app's lib/.
	 */
	private const META_UID = 'grafana_uid';
	private const META_MODE = 'grafana_mode';
	private const META_MAPPING = 'grafana_mapping';

	/** The occ invocation prefix, e.g. "php occ". */
	private string $occ;

	/** Result of the most recent occ command. */
	private int $lastExit = 0;
	private string $lastOutput = '';

	// ── HTTP channels (lazily built so occ-only scenarios pay nothing) ────────
	private ?Client $dav = null;
	private ?Client $grafana = null;

	private string $ncBaseUrl;
	private string $ncUser;
	private string $ncPass;
	private string $grafanaUrl;
	private string $grafanaToken;

	/**
	 * NC folders this scenario created (relative to the user's files root), torn
	 * down after the scenario so re-runs start clean.
	 *
	 * @var list<string>
	 */
	private array $createdFolders = [];

	/** State carried between steps within a scenario. */
	private string $currentFolder = '';
	private string $currentFilePath = '';

	/**
	 * Lifecycle state. `$lastUid` is the thread the whole app is built on — the
	 * dashboard uid a scenario arranged — so most assertions are really "is this uid
	 * still where it should be".
	 *
	 * @var array<string,string> spec-friendly mapping name → Nextcloud folder
	 */
	private array $mappedFolders = [];
	/** @var array<string,string> mapping name → sync|link */
	private array $mappingModes = [];
	private string $unmappedFolder = '';
	private string $lastUid = '';
	private string $newUid = '';
	private string $copyTarget = '';
	private string $trashedFrom = '';
	private int $lastMoveStatus = 0;

	public function __construct() {
		$this->occ = getenv('OCC') ?: 'php occ';
		$this->ncBaseUrl = rtrim(getenv('NC_BASE_URL') ?: 'http://localhost:8080', '/');
		$this->ncUser = getenv('NC_ADMIN_USER') ?: 'admin';
		$this->ncPass = getenv('NC_ADMIN_PASS') ?: 'admin';
		$this->grafanaUrl = rtrim(getenv('GRAFANA_URL') ?: 'http://localhost:3000', '/');
		$this->grafanaToken = getenv('GRAFANA_TOKEN') ?: '';
	}

	// ── per-scenario lifecycle (teardown) ─────────────────────────────────────

	/**
	 * After every scenario, delete the NC folders we made and clear the mappings
	 * list. Keeps re-runs isolated on the shared CI NC instance. Best-effort:
	 * failures here never fail a test.
	 *
	 * @AfterScenario
	 */
	public function tearDown(): void {
		foreach ($this->createdFolders as $folder) {
			try {
				$this->davClient()->request('DELETE', rawurlencode($folder));
			} catch (\Throwable) {
				// best-effort cleanup
			}
		}
		// Reset the mapping list so the next scenario starts from zero mappings.
		$this->occ('config:app:delete ' . self::APP_ID . ' mappings');
		$this->createdFolders = [];
		$this->currentFolder = '';
		$this->currentFilePath = '';
	}
}
