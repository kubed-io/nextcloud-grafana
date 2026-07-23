<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * Folder-mapping steps: the "admin maps a Grafana folder to a Nextcloud folder"
 * use case, driven over occ (`grafana_sync:add-mapping` / `list-mappings`) — the
 * same MappingService the admin Settings panel writes through. Composed into
 * {@see \OCA\GrafanaSync\Tests\Integration\FeatureContext}.
 *
 * A mapping stores a Grafana folder **uid**; this config-only feature does not
 * validate the uid against a live Grafana, so the steps pass the folder name as
 * its own uid (and title). The mapping CRUD + validation is what's under test.
 */
trait MappingSteps {
	/**
	 * Add one or more mappings from a Gherkin table (columns: grafana folder,
	 * folder, mode). Each add must succeed.
	 *
	 * @When the admin adds these mappings:
	 */
	public function theAdminAddsTheseMappings(TableNode $table): void {
		foreach ($table->getHash() as $row) {
			$res = $this->addMapping(
				(string)($row['grafana folder'] ?? ''),
				(string)($row['folder'] ?? ''),
				(string)($row['mode'] ?? 'sync'),
				(string)($row['format'] ?? 'json'),
			);
			Assert::assertSame(0, $res['exit'], "adding a mapping failed:\n{$res['output']}");
		}
	}

	/**
	 * Add one mapping with an explicit format (used by the format + uniqueness
	 * scenarios). Does NOT assert the exit code — the caller's Then decides whether
	 * this add was meant to succeed or be rejected.
	 *
	 * @When the admin adds a :format mapping for grafana folder :uid in folder :folder
	 */
	public function theAdminAddsAFormatMapping(string $format, string $uid, string $folder): void {
		$this->addMapping($uid, $folder, 'sync', $format);
	}

	/**
	 * Attempt an add with a mode the model rejects. Records the (non-zero) exit for
	 * the following "the mapping is rejected" assertion.
	 *
	 * @When the admin adds a mapping with an unknown mode for grafana folder :uid
	 */
	public function theAdminAddsAMappingWithAnUnknownMode(string $uid): void {
		$this->addMapping($uid, $uid, 'backup', 'json');
	}

	/** @Then there are :count configured mappings */
	public function thereAreConfiguredMappings(int $count): void {
		Assert::assertCount($count, $this->listMappings(), 'unexpected number of mappings');
	}

	/** @Then the mapping for grafana folder :uid is in :mode mode */
	public function theMappingForFolderIsInMode(string $uid, string $mode): void {
		$m = $this->findMapping($uid);
		Assert::assertNotNull($m, "no mapping for grafana folder '$uid'");
		Assert::assertSame($mode, $m['mode'] ?? null, "mapping '$uid' mode mismatch");
	}

	/** @Then the mapping for grafana folder :uid is in :format format */
	public function theMappingForFolderIsInFormat(string $uid, string $format): void {
		$m = $this->findMapping($uid);
		Assert::assertNotNull($m, "no mapping for grafana folder '$uid'");
		Assert::assertSame($format, $m['format'] ?? null, "mapping '$uid' format mismatch");
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was accepted but should have been rejected:\n{$this->lastOutput}");
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * @return array{exit:int, output:string}
	 */
	private function addMapping(string $uid, string $ncFolder, string $mode, string $format): array {
		$json = json_encode([
			'grafana_folder_uid' => $uid,
			'grafana_folder_title' => $uid,
			'nc_folder' => $ncFolder,
			'mode' => $mode,
			'format' => $format,
		], JSON_THROW_ON_ERROR);
		return $this->occ('grafana_sync:add-mapping ' . escapeshellarg($json));
	}

	/**
	 * The configured mappings, decoded from `grafana_sync:list-mappings`.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function listMappings(): array {
		$res = $this->occ('grafana_sync:list-mappings');
		Assert::assertSame(0, $res['exit'], "list-mappings failed:\n{$res['output']}");
		$decoded = json_decode(trim($res['output']), true);
		Assert::assertIsArray($decoded, "list-mappings did not return a JSON array:\n{$res['output']}");
		return $decoded;
	}

	/** @return array<string,mixed>|null */
	private function findMapping(string $uid): ?array {
		foreach ($this->listMappings() as $m) {
			if (($m['grafana_folder_uid'] ?? null) === $uid) {
				return $m;
			}
		}
		return null;
	}
}
