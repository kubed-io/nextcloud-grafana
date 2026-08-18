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
use OCA\GrafanaSync\Tests\Integration\Steps\MirrorSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\RenameSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\SyncSteps;
use OCA\GrafanaSync\Tests\Integration\Steps\TagSteps;
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
	use MirrorSteps;
	use TagSteps;

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
	/** The Grafana folder uid a tag scenario is acting on. */
	private string $currentGrafanaFolder = '';
	/** The Grafana folder a `still under` assertion just located, for the clause after it. */
	private string $lastGrafanaFolderUid = '';
	/** @var list<string> uids this scenario put in the bin that the app does not manage */
	private array $unmanagedInBin = [];
	/** @var array<string,string> Grafana folders this scenario created: title → uid */
	private array $createdGrafanaFolders = [];
	/** The folder uid the arrange captured, for "the uid it had before the delete". */
	private string $lastFolderUid = '';
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
	/** The uid Grafana minted when a scenario copied a dashboard there. */
	private string $grafanaCopyUid = '';
	/**
	 * The folder a scenario last named the whole contents of, and the names it found.
	 *
	 * Held so a following step can re-read the SAME set — "these names survive another
	 * sync" is a before/after claim, and re-deriving the "before" after the sync would
	 * compare the folder with itself and pass no matter what happened.
	 */
	private string $namedFolder = '';
	/** @var list<string> */
	private array $namedFiles = [];
	/**
	 * THE FILE THE SCENARIO CALLS "the original" — which is NOT $currentFilePath.
	 *
	 * $currentFilePath is a cursor: it follows whatever the last gesture touched, so
	 * a move or a rename repoints it. "The original" is a role, and it has to stay
	 * put while the gesture happens somewhere else, or a post-condition about the
	 * original silently reads the thing that just moved.
	 */
	private string $originalPath = '';
	/** The original's bytes, read before the gesture — the byte-for-byte baseline. */
	private string $originalBody = '';
	/**
	 * Whether the dashboard existed, and where, immediately before a purge.
	 *
	 * @var array{exists:bool,folder:string}
	 */
	private array $grafanaBeforePurge = ['exists' => false, 'folder' => ''];
	/**
	 * The dashboard's folder and title as they were BEFORE the gesture.
	 *
	 * "Nothing changed in Grafana" is a pre/post comparison and cannot be asserted
	 * any other way: re-deriving the expected folder at assert time only proves the
	 * derivation agrees with itself.
	 *
	 * @var array{folder:string,title:string}
	 */
	private array $grafanaBefore = ['folder' => '', 'title' => ''];
	private string $trashedFrom = '';
	/**
	 * The managed keys the file carried as it was trashed — the only moment they can
	 * be read, because a trashed file's original path no longer resolves.
	 *
	 * @var array<string,string>
	 */
	private array $trashedMetadata = [];
	private int $lastMoveStatus = 0;

	/** Raw status of the last deliberately-refused COPY / DELETE, for the guard scenarios. */
	private int $lastCopyStatus = 0;
	private int $lastDeleteStatus = 0;

	/** Where a refused copy WOULD have landed, so the refusal can prove nothing did. */
	private string $attemptedCopyPath = '';

	/** Same, for a refused create. */
	private int $lastCreateStatus = 0;
	private string $attemptedCreatePath = '';

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
		// Grafana folders this scenario minted would be re-mirrored into every LATER
		// scenario that maps the same parent and pulls — so they go too, best-effort.
		foreach ($this->createdGrafanaFolders as $uid) {
			try {
				$this->grafanaDeleteFolder($uid);
			} catch (\Throwable) {
				// best-effort cleanup
			}
		}
		$this->createdGrafanaFolders = [];
		// Reset the mapping list so the next scenario starts from zero mappings.
		$this->occ('config:app:delete ' . self::APP_ID . ' mappings');
		$this->createdFolders = [];
		$this->currentFolder = '';
		$this->currentFilePath = '';
	}
}
