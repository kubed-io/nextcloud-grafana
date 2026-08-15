<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Migration;

use OC\Core\Command\Maintenance\Mimetype\GenerateMimetypeFileBuilder;
use OCA\GrafanaSync\AppInfo\Application;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Registers the `application/grafana+json` mimetype + its icon so `.grafana`
 * files render with our SVG in the Files row, not the generic "code" glyph.
 *
 * Runs on every install/upgrade. All three steps are idempotent:
 *   1. Merge our extension/alias mappings into the live config files
 *      (`config/mimetypemapping.json`, `config/mimetypealiases.json`) so the
 *      Detection layer + frontend resolver see them.
 *   2. Copy the SVG to `core/img/filetypes/grafana.svg` — that's where
 *      GenerateMimetypeFileBuilder enumerates icon basenames from.
 *   3. Insert the mimetype into `oc_mimetypes`, rewrite filecache rows whose
 *      name ends in `.grafana` to that id, and regenerate
 *      `core/js/mimetypelist.js` so the frontend map carries the alias.
 *
 * Equivalent to running `occ maintenance:mimetype:update-db` +
 * `update-js`, but inline with the app's lifecycle: no human step.
 *
 * STEP 1 IS WHAT MAKES STEP 3 A ONE-OFF. `Detection::detectPath()` reads the LAST
 * extension only, so once `grafana` is a key in `mimetypemapping.json`, core detects
 * every dashboard file correctly at write time and the filecache never needs
 * correcting again. Under the old compound `.grafana.json` extension the detector saw
 * `.json`, and this same UPDATE had to be re-run from the pull, the create, and every
 * single file write to undo it.
 */
final class RegisterMimetype implements IRepairStep {
	private const APP_MIMETYPE = 'application/grafana+json';
	private const APP_ALIAS_KEY = self::APP_MIMETYPE;
	private const APP_ICON_NAME = 'grafana';
	private const FILE_EXT = 'grafana';

	/**
	 * The compound extension this app used to register, swept out of the config on
	 * upgrade. It was always a dead key — `detectPath()` matches on the last extension
	 * segment, so `grafana.json` could never be looked up — but leaving it behind puts a
	 * mapping in the admin's config file that claims a shape this app no longer writes.
	 */
	private const LEGACY_FILE_EXT = 'grafana.json';

	public function __construct(
		private IMimeTypeDetector $detector,
		private IMimeTypeLoader $loader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Register the grafana_sync mimetype + icon';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$serverRoot = \OC::$SERVERROOT;
		$appRoot = $serverRoot . '/custom_apps/' . Application::APP_ID;
		// Custom config dir — Server::getServerRoot() . '/config' is the standard
		// location, but kubernetes mounts may place it elsewhere; resolve via OC.
		$configDir = \OC::$configDir;

		try {
			$this->mergeJson(
				$configDir . 'mimetypemapping.json',
				[self::FILE_EXT => [self::APP_MIMETYPE]],
				[self::LEGACY_FILE_EXT],
			);
			$this->mergeJson(
				$configDir . 'mimetypealiases.json',
				[self::APP_ALIAS_KEY => self::APP_ICON_NAME],
			);
		} catch (\Throwable $e) {
			$this->logger->error('grafana_sync: failed to merge mimetype config', ['exception' => $e]);
			$output->warning('grafana_sync: could not update config/mimetype*.json (' . $e->getMessage() . ')');
		}

		// Copy SVG into core/img/filetypes/. GenerateMimetypeFileBuilder scans
		// that directory verbatim, so the icon name MUST match the alias value
		// from above ("grafana.svg" for alias "grafana").
		$src = $appRoot . '/img/' . self::APP_ICON_NAME . '.svg';
		$dst = $serverRoot . '/core/img/filetypes/' . self::APP_ICON_NAME . '.svg';
		if (file_exists($src)) {
			$existing = is_file($dst) ? @file_get_contents($dst) : null;
			$incoming = @file_get_contents($src);
			if ($incoming !== false && $existing !== $incoming) {
				if (@file_put_contents($dst, $incoming) === false) {
					$output->warning('grafana_sync: could not write ' . $dst);
				}
			}
		} else {
			$output->warning('grafana_sync: icon source missing at ' . $src);
		}

		// update-db: insert the mimetype row, then rewrite filecache rows
		// whose extension matches. The detector cache is rebuilt because we
		// just touched the on-disk config files.
		$this->detector->getAllMappings(); // primes lazy load (no public reset)
		$id = $this->loader->getId(self::APP_MIMETYPE);
		$touched = $this->loader->updateFilecache(self::FILE_EXT, $id);
		$output->info(sprintf(
			'grafana_sync: mimetype id=%d, %d filecache row(s) updated',
			$id,
			$touched,
		));

		// update-js: regenerate core/js/mimetypelist.js so the frontend map
		// includes our alias. This is the same code path as
		// `occ maintenance:mimetype:update-js`.
		try {
			$gen = new GenerateMimetypeFileBuilder();
			$js = $gen->generateFile(
				$this->detector->getAllAliases(),
				$this->detector->getAllNamings(),
			);
			@file_put_contents($serverRoot . '/core/js/mimetypelist.js', $js);
		} catch (\Throwable $e) {
			$this->logger->error('grafana_sync: failed to regenerate mimetypelist.js', ['exception' => $e]);
		}
	}

	/**
	 * Read a JSON file (creating it if missing), merge `$additions` on top, drop
	 * `$removals`, and write it back. Atomic via tempfile + rename.
	 *
	 * @param array<string,mixed> $additions
	 * @param list<string> $removals keys this app used to write and no longer owns
	 */
	private function mergeJson(string $path, array $additions, array $removals = []): void {
		$existing = [];
		if (is_file($path)) {
			$raw = file_get_contents($path);
			if ($raw !== false && trim($raw) !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$existing = $decoded;
				}
			}
		}
		$changed = false;
		foreach ($additions as $key => $value) {
			if (!array_key_exists($key, $existing) || $existing[$key] !== $value) {
				$existing[$key] = $value;
				$changed = true;
			}
		}
		foreach ($removals as $key) {
			if (array_key_exists($key, $existing)) {
				unset($existing[$key]);
				$changed = true;
			}
		}
		if (!$changed && is_file($path)) {
			return;
		}
		$encoded = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			throw new \RuntimeException('json_encode failed for ' . $path);
		}
		$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $encoded) === false) {
			throw new \RuntimeException('write failed: ' . $tmp);
		}
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			throw new \RuntimeException('rename failed: ' . $path);
		}
	}
}
