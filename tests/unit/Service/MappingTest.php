<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\Mapping;
use PHPUnit\Framework\TestCase;

/**
 * The folder-mapping value object — the only place mapping input is validated and
 * normalised (the controller and the occ command both funnel through
 * {@see Mapping::fromArray}). These assertions lock that contract: which shapes are
 * accepted, which are rejected, and how the accepted ones are cleaned.
 */
final class MappingTest extends TestCase {
	public function testBuildsAValidMappingFromAllFields(): void {
		$m = Mapping::fromArray([
			'id' => 'abc123',
			'grafana_folder_uid' => 'af397c9y8enswf',
			'grafana_folder_title' => 'observe',
			'nc_folder' => 'dashboards/observe',
			'mode' => 'sync',
			'use_team_folder' => false,
			'nc_folder_id' => 4242,
		]);

		self::assertSame('abc123', $m->id);
		self::assertSame('af397c9y8enswf', $m->grafanaFolderUid);
		self::assertSame('observe', $m->grafanaFolderTitle);
		self::assertSame('dashboards/observe', $m->ncFolder);
		self::assertSame('sync', $m->mode);
		self::assertFalse($m->useTeamFolder);
		self::assertSame(4242, $m->ncFolderId);
	}

	/**
	 * THE DEFAULT MUST BE THE BACKEND THAT ALWAYS EXISTS.
	 *
	 * A Team Folder needs groupfolders, an optional app absent from a stock
	 * Nextcloud, so defaulting to it made the default mapping the one that could
	 * not be provisioned. This asserts the OMITTED flag, not a passed `false` —
	 * the defect lived entirely in what happens when nobody says anything.
	 *
	 * This test used to assert the opposite, under the name
	 * `testTeamFolderDefaultsToTrue`. A test can pin a defect just as firmly as it
	 * pins a requirement; what it cannot do is tell you which one it is holding.
	 */
	public function testStorageDefaultsToAdminOwned(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
		]);
		self::assertFalse($m->useTeamFolder, 'an unset storage flag must mean an admin-owned folder');
	}

	public function testStorageIsOptedInto(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
			'use_team_folder' => true,
		]);
		self::assertTrue($m->useTeamFolder, 'a Team Folder must still be selectable');
	}

	/**
	 * A stored row from before groups moved to the folder still parses, and the key
	 * is simply not a field any more. Reading old data must not throw, and must not
	 * resurrect the value.
	 */
	public function testAStoredRowWithGroupsStillParsesAndIgnoresThem(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
			'nc_groups' => ['devs'],
		]);
		self::assertArrayNotHasKey('nc_groups', $m->toArray());
	}

	/**
	 * THE FOLDER ID IS THE NEXTCLOUD HALF OF THE PAIR, and it is allowed to be
	 * missing: a mapping can be saved before its folder is provisioned, and rows
	 * written before the field existed have none. 0 means "not resolved yet" and
	 * self-heals on the first resolve — it is not an error to store.
	 */
	public function testTheFolderIdIsZeroUntilItIsKnown(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
		]);
		self::assertSame(0, $m->ncFolderId);

		// A negative id is nonsense rather than a smaller number — it must not be
		// trusted through to a lookup, so it reads as "not resolved yet".
		$bogus = Mapping::fromArray([
			'grafana_folder_uid' => 'uid2',
			'nc_folder' => 'x',
			'mode' => 'sync',
			'nc_folder_id' => -7,
		]);
		self::assertSame(0, $bogus->ncFolderId);
	}

	public function testTheFolderIdSurvivesARoundTrip(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
			'nc_folder_id' => 99,
		]);
		self::assertSame(99, Mapping::fromArray($m->toArray())->ncFolderId);
	}

	/**
	 * A RENAME CHANGES THE LABEL, NOT THE MAPPING. Both withers keep the id that
	 * makes this the same mapping — that is the whole point of holding the pair.
	 */
	public function testRenamingTheFolderKeepsTheIdAndTheMapping(): void {
		$m = Mapping::fromArray([
			'id' => 'keepme',
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'Demo',
			'mode' => 'sync',
			'nc_folder_id' => 512,
		]);

		$renamed = $m->withNcFolder('Dashboards');
		self::assertSame('Dashboards', $renamed->ncFolder);
		self::assertSame(512, $renamed->ncFolderId);
		self::assertSame('keepme', $renamed->id);
		self::assertSame('uid1', $renamed->grafanaFolderUid);

		$stamped = $m->withNcFolderId(777);
		self::assertSame(777, $stamped->ncFolderId);
		self::assertSame('Demo', $stamped->ncFolder);
		self::assertSame('keepme', $stamped->id);
	}

	public function testGeneratesAnIdWhenNoneGiven(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'link',
		]);
		self::assertNotSame('', $m->id);
		// Two ids minted independently should differ.
		$other = Mapping::fromArray(['grafana_folder_uid' => 'uid2', 'nc_folder' => 'x', 'mode' => 'link']);
		self::assertNotSame($m->id, $other->id);
	}

	public function testTitleDefaultsToEmptyWhenAbsent(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
		]);
		self::assertSame('', $m->grafanaFolderTitle);
	}

	public function testRejectsAMissingFolderUid(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['nc_folder' => 'observe', 'mode' => 'sync']);
	}

	public function testRejectsAWhitespaceOnlyFolderUid(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['grafana_folder_uid' => '   ', 'nc_folder' => 'observe', 'mode' => 'sync']);
	}

	public function testNcFolderDefaultsToTheGrafanaFolderTitleWhenOmitted(): void {
		// Omitting nc_folder materialises it to the Grafana folder's name AT CREATE, so
		// the stored mapping carries both — and round-trips with both set.
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'af397c9y8enswf',
			'grafana_folder_title' => 'observability',
			'mode' => 'sync',
		]);
		self::assertSame('observability', $m->ncFolder);
		self::assertSame('observability', $m->toArray()['nc_folder']);
	}

	public function testAnExplicitNcFolderIsNotOverriddenByTheTitle(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'grafana_folder_title' => 'observability',
			'nc_folder' => 'my-dashboards',
			'mode' => 'sync',
		]);
		self::assertSame('my-dashboards', $m->ncFolder);
	}

	public function testRejectsAMissingNcFolderWhenThereIsNoTitleToDefaultFrom(): void {
		// No nc_folder AND no title → nothing to borrow → still rejected.
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['grafana_folder_uid' => 'uid1', 'mode' => 'sync']);
	}

	/**
	 * AN OMITTED MODE IS THE DEFAULT, NOT A REFUSAL.
	 *
	 * It used to be a refusal, which made the shortest useful call — a Grafana
	 * folder and nothing else — impossible to write, and forced every caller to
	 * name a mode it had no opinion about. `useTeamFolder` in this same method had always
	 * defaulted; mode was the odd one out.
	 *
	 * `link` is the conservative choice: it downloads nothing and pushes nothing
	 * back, so a mapping made without an opinion about mode cannot cost anything.
	 *
	 * This test exists because NOTHING ELSE LOCKS THE CONTRACT — unlike the n8n
	 * sibling, this repo never had a "missing mode is rejected" test to invert, so
	 * the change would otherwise have been free to regress silently.
	 */
	public function testAMissingModeDefaultsToLink(): void {
		$m = Mapping::fromArray(['grafana_folder_uid' => 'uid1', 'nc_folder' => 'observe']);
		$this->assertSame(Mapping::MODE_LINK, $m->mode);
	}

	/**
	 * An UNKNOWN mode is still refused. Saying nothing and saying nonsense are
	 * different inputs: one has no opinion, the other has one the app cannot
	 * honour, and collapsing them would let a typo become a silent `link`.
	 */
	public function testRejectsAnUnknownMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['grafana_folder_uid' => 'uid1', 'nc_folder' => 'observe', 'mode' => 'backup']);
	}

	public function testNormalisesTheNcFolder(): void {
		$cases = [
			'surrounding slashes stripped' => ['/observe/', 'observe'],
			'duplicate separators collapsed' => ['dashboards//observe', 'dashboards/observe'],
			'whitespace trimmed' => ['  observe  ', 'observe'],
			'nested kept' => ['a/b/c', 'a/b/c'],
		];
		foreach ($cases as $label => [$input, $expected]) {
			$m = Mapping::fromArray([
				'grafana_folder_uid' => 'uid1',
				'nc_folder' => $input,
				'mode' => 'sync',
			]);
			self::assertSame($expected, $m->ncFolder, $label);
		}
	}

	public function testToArrayRoundTripsThroughFromArray(): void {
		$original = Mapping::fromArray([
			'id' => 'keep-me',
			'grafana_folder_uid' => 'uid1',
			'grafana_folder_title' => 'observe',
			'nc_folder' => 'observe',
			'mode' => 'link',
			'use_team_folder' => false,
		]);
		$round = Mapping::fromArray($original->toArray());
		self::assertEquals($original, $round);
	}

	public function testJsonSerializeMatchesToArray(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
		]);
		self::assertSame($m->toArray(), $m->jsonSerialize());
	}
}
