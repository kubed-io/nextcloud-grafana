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
 *
 * ## ONE VOCABULARY FOR THE PRE-STATE AND THE ACTION
 *
 * `a mapping with the following values:` and `the admin maps the Grafana folder
 * :uid with:` take the SAME table, because they describe the same object — one as
 * something that is already true, one as something being done. That is what lets
 * a uniqueness scenario put a mapping in the pre-state and then perform the very
 * action that created it, with the difference visible in the table rather than
 * hidden in two differently-worded steps.
 *
 * A blank cell means "the admin left this field alone", so it is dropped from the
 * payload entirely and the app applies its own default. That is the only way an
 * Examples row can say "unset" — an empty string is a value, and here it would
 * test the validator rather than the default.
 */
trait MappingSteps {
	/** @var array<string,string> the last form submitted, for the post-condition */
	private array $lastMappingForm = [];

	/** @var array<string,string> what an unset field is expected to become */
	private array $mappingDefaults = [];

	/** @Given no Grafana folders are mapped */
	public function noGrafanaFoldersAreMapped(): void {
		foreach ($this->listMappings() as $m) {
			$id = (string)($m['id'] ?? '');
			if ($id !== '') {
				$this->occ('grafana_sync:remove-mapping ' . escapeshellarg($id));
			}
		}
		Assert::assertSame([], $this->listMappings(), 'the mapping store did not empty');
	}

	/** @Given an unset field on the mapping form defaults to: */
	public function anUnsetFieldDefaultsTo(TableNode $table): void {
		$this->mappingDefaults = $this->formValues($table);
	}

	/**
	 * @Given a mapping with the following values:
	 *
	 * The pre-state twin of `the admin maps the Grafana folder :uid with:`. It
	 * resets the store first, so a scenario opening with it starts from a known
	 * count rather than inheriting whatever the previous scenario left behind.
	 */
	public function aMappingWithTheFollowingValues(TableNode $table): void {
		$this->noGrafanaFoldersAreMapped();
		$form = $this->formValues($table);
		$uid = $form['grafana folder'] ?? '';
		unset($form['grafana folder']);
		$res = $this->addMappingFromForm($uid, $form);
		Assert::assertSame(0, $res['exit'], "the pre-state mapping could not be created:\n{$res['output']}");
	}

	/** @When the admin maps the Grafana folder :uid with: */
	public function theAdminMapsTheGrafanaFolderWith(string $uid, TableNode $table): void {
		$form = $this->formValues($table);
		$this->lastMappingForm = ['grafana folder' => $uid] + $form;
		$this->addMappingFromForm($uid, $form);
	}

	/**
	 * @Then the mapping matches the form, unset fields at their defaults
	 *
	 * Reads back what was stored and compares it against the submitted form,
	 * substituting the declared default for every field the form left blank. One
	 * assertion for the whole object, so a scenario says "it saved what I typed"
	 * rather than listing the fields one at a time.
	 */
	public function theMappingMatchesTheForm(): void {
		$uid = $this->lastMappingForm['grafana folder'] ?? '';
		$m = $this->findMapping($uid);
		Assert::assertNotNull($m, "no mapping was stored for Grafana folder $uid");

		$expected = $this->lastMappingForm + $this->mappingDefaults;

		// The Nextcloud folder defaults to the Grafana folder's TITLE, which these
		// steps set to the uid. The declared default is prose rather than a value,
		// so it is resolved here instead of being written into every Examples row.
		$ncFolder = $expected['nc folder'] ?? '';
		if ($ncFolder === '' || $ncFolder === "the Grafana folder's name") {
			$ncFolder = $uid;
		}
		Assert::assertSame($ncFolder, (string)($m['nc_folder'] ?? ''), 'nc folder');

		Assert::assertSame($expected['mode'] ?? '', (string)($m['mode'] ?? ''), 'mode');
		Assert::assertSame($expected['format'] ?? 'json', (string)($m['format'] ?? ''), 'format');
		Assert::assertSame(
			$this->storageToModel($expected['storage'] ?? ''),
			(bool)($m['use_team_folder'] ?? false),
			'storage',
		);
		Assert::assertSame(
			($expected['subfolders'] ?? 'off') === 'on',
			(bool)($m['sync_subfolders'] ?? false),
			'subfolder sync',
		);

		$wanted = $this->groupList($expected['groups'] ?? '');
		$stored = array_values(array_map('strval', (array)($m['nc_groups'] ?? [])));
		sort($wanted);
		sort($stored);
		Assert::assertSame($wanted, $stored, 'groups');
	}

	/** @Then the mapping is rejected */
	public function theMappingIsRejected(): void {
		Assert::assertNotSame(0, $this->lastExit, "the mapping was unexpectedly accepted:\n{$this->lastOutput}");
	}

	/**
	 * @Then the refusal explains :fragment
	 *
	 * A FRAGMENT, NOT THE WHOLE MESSAGE. The scenario's job is to prove the refusal
	 * names the field at fault so an admin knows what to change; pinning the exact
	 * sentence would make every wording improvement a test failure.
	 */
	public function theRefusalExplains(string $fragment): void {
		Assert::assertStringContainsString(
			$fragment,
			$this->lastOutput,
			"the refusal did not mention '$fragment':\n{$this->lastOutput}",
		);
	}

	/**
	 * @Then there are exactly :count configured mappings
	 * @Then there is exactly :count configured mapping
	 */
	public function thereAreExactlyNConfiguredMappings(int $count): void {
		Assert::assertCount($count, $this->listMappings(), "expected $count mappings");
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/** "team folder" → true, "admin folder" → false. */
	private function storageToModel(string $storage): bool {
		return str_contains($storage, 'team');
	}

	/**
	 * A table of `| field | value |` rows as an array, with BLANK VALUES DROPPED.
	 *
	 * A blank cell in an Examples row means the admin left the field alone, which
	 * is not the same as submitting an empty string — so it must not reach the
	 * payload at all, or the app would validate the empty value instead of
	 * applying its default.
	 *
	 * @return array<string,string>
	 */
	private function formValues(TableNode $table): array {
		$out = [];
		foreach ($table->getRowsHash() as $field => $value) {
			$value = trim((string)$value);
			if ($value !== '') {
				$out[(string)$field] = $value;
			}
		}
		return $out;
	}

	/**
	 * Group ids from a comma-separated cell.
	 *
	 * @return list<string>
	 */
	private function groupList(string $value): array {
		$out = [];
		foreach (explode(',', $value) as $g) {
			$g = trim($g);
			if ($g !== '' && !in_array($g, $out, true)) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/**
	 * Submit a mapping form over occ.
	 *
	 * Only the keys the form actually supplied are sent, so the app's own defaults
	 * apply to the rest — which is the whole point of the blank cell. The title is
	 * always sent alongside the uid: this suite uses the folder name as its own
	 * uid, and the title is what a blank `nc_folder` defaults from.
	 *
	 * @param array<string,string> $form
	 * @return array{exit:int, output:string}
	 */
	private function addMappingFromForm(string $uid, array $form): array {
		$data = ['grafana_folder_uid' => $uid, 'grafana_folder_title' => $uid];
		if (array_key_exists('nc folder', $form)) {
			$data['nc_folder'] = $form['nc folder'];
		}
		if (array_key_exists('mode', $form)) {
			$data['mode'] = $form['mode'];
		}
		if (array_key_exists('format', $form)) {
			$data['format'] = $form['format'];
		}
		if (array_key_exists('groups', $form)) {
			$data['nc_groups'] = $this->groupList($form['groups']);
		}
		if (array_key_exists('storage', $form)) {
			$data['use_team_folder'] = $this->storageToModel($form['storage']);
		}
		if (array_key_exists('subfolders', $form)) {
			$data['sync_subfolders'] = $form['subfolders'] === 'on';
		}

		return $this->occ('grafana_sync:add-mapping ' . escapeshellarg(json_encode($data, JSON_THROW_ON_ERROR)));
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
