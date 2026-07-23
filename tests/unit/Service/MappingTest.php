<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
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
			'format' => 'yaml',
		]);

		self::assertSame('abc123', $m->id);
		self::assertSame('af397c9y8enswf', $m->grafanaFolderUid);
		self::assertSame('observe', $m->grafanaFolderTitle);
		self::assertSame('dashboards/observe', $m->ncFolder);
		self::assertSame('sync', $m->mode);
		self::assertSame('yaml', $m->format);
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

	public function testFormatDefaultsToJsonWhenAbsent(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
		]);
		self::assertSame('json', $m->format);
	}

	public function testFormatDefaultsToJsonWhenEmptyString(): void {
		$m = Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
			'format' => '',
		]);
		self::assertSame('json', $m->format);
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

	public function testRejectsAMissingNcFolder(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['grafana_folder_uid' => 'uid1', 'mode' => 'sync']);
	}

	public function testRejectsAnUnknownMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray(['grafana_folder_uid' => 'uid1', 'nc_folder' => 'observe', 'mode' => 'backup']);
	}

	public function testRejectsAnUnknownFormat(): void {
		$this->expectException(\InvalidArgumentException::class);
		Mapping::fromArray([
			'grafana_folder_uid' => 'uid1',
			'nc_folder' => 'observe',
			'mode' => 'sync',
			'format' => 'toml',
		]);
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
			'format' => 'json',
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
