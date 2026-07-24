<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * CRUD + resolver behaviour of the mapping store. Backed by an in-memory fake of
 * IAppConfig (a single string cell for the `mappings` key) so the JSON
 * encode/decode round-trip is exercised for real, not mocked away.
 */
final class MappingServiceTest extends TestCase {
	/** Shared backing store for the fake IAppConfig, keyed by config key. */
	private array $store = [];

	private function config(): IAppConfig {
		$store = &$this->store;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string
				=> $store[$key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			},
		);
		return $config;
	}

	private function service(): MappingService {
		return new MappingService($this->config());
	}

	private function mapping(string $uid, string $ncFolder, string $mode = 'sync', string $format = 'json'): Mapping {
		return Mapping::fromArray([
			'grafana_folder_uid' => $uid,
			'grafana_folder_title' => $uid,
			'nc_folder' => $ncFolder,
			'mode' => $mode,
			'format' => $format,
		]);
	}

	public function testAddThenListRoundTrips(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'alpha'));
		$svc->add($this->mapping('uid-b', 'bravo', 'link'));

		$all = $svc->list();
		self::assertCount(2, $all);
		self::assertSame('uid-a', $all[0]->grafanaFolderUid);
		self::assertSame('bravo', $all[1]->ncFolder);
		self::assertSame('link', $all[1]->mode);
	}

	public function testPersistsAcrossServiceInstances(): void {
		$this->service()->add($this->mapping('uid-a', 'alpha'));
		// A fresh service sharing the same backing store must decode what was stored.
		$all = $this->service()->list();
		self::assertCount(1, $all);
		self::assertSame('uid-a', $all[0]->grafanaFolderUid);
	}

	public function testGetByIdReturnsTheMappingOrNull(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha'));
		self::assertNotNull($svc->getById($saved->id));
		self::assertSame('uid-a', $svc->getById($saved->id)->grafanaFolderUid);
		self::assertNull($svc->getById('nope'));
	}

	public function testRejectsADuplicateFolderUid(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'alpha'));
		$this->expectException(\InvalidArgumentException::class);
		$svc->add($this->mapping('uid-a', 'different-folder'));
	}

	public function testRejectsADuplicateId(): void {
		$svc = $this->service();
		$a = Mapping::fromArray(['id' => 'fixed', 'grafana_folder_uid' => 'uid-a', 'nc_folder' => 'alpha', 'mode' => 'sync']);
		$b = Mapping::fromArray(['id' => 'fixed', 'grafana_folder_uid' => 'uid-b', 'nc_folder' => 'bravo', 'mode' => 'sync']);
		$svc->add($a);
		$this->expectException(\InvalidArgumentException::class);
		$svc->add($b);
	}

	public function testUpdateChangesMutableFieldsButKeepsIdAndFolders(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha', 'sync', 'json'));

		// The folder names are immutable, so an update keeps them and only changes the
		// mutable fields (mode/format/…).
		$svc->update($saved->id, $this->mapping('uid-a', 'alpha', 'link', 'yaml'));

		$got = $svc->getById($saved->id);
		self::assertNotNull($got);
		self::assertSame($saved->id, $got->id);
		self::assertSame('alpha', $got->ncFolder);
		self::assertSame('link', $got->mode);
		self::assertSame('yaml', $got->format);
	}

	public function testUpdateRejectsChangingTheNextcloudFolder(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha', 'sync', 'json'));

		$this->expectException(\InvalidArgumentException::class);
		$svc->update($saved->id, $this->mapping('uid-a', 'alpha-renamed', 'sync', 'json'));
	}

	public function testUpdateRejectsChangingTheGrafanaFolder(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha', 'sync', 'json'));

		$this->expectException(\InvalidArgumentException::class);
		$svc->update($saved->id, $this->mapping('uid-b', 'alpha', 'sync', 'json'));
	}

	public function testUpdateRejectsChangingTheTeamFolderFlag(): void {
		$svc = $this->service();
		// Default use_team_folder is true; the saved mapping is a Team Folder.
		$saved = $svc->add($this->mapping('uid-a', 'alpha', 'sync', 'json'));

		$flipped = Mapping::fromArray([
			'grafana_folder_uid' => 'uid-a', 'grafana_folder_title' => 'uid-a',
			'nc_folder' => 'alpha', 'mode' => 'sync', 'use_team_folder' => false,
		]);
		$this->expectException(\InvalidArgumentException::class);
		$svc->update($saved->id, $flipped);
	}

	public function testUpdateRejectsChangingSubfolderSync(): void {
		$svc = $this->service();
		// Default sync_subfolders is false.
		$saved = $svc->add($this->mapping('uid-a', 'alpha', 'sync', 'json'));

		$flipped = Mapping::fromArray([
			'grafana_folder_uid' => 'uid-a', 'grafana_folder_title' => 'uid-a',
			'nc_folder' => 'alpha', 'mode' => 'sync', 'sync_subfolders' => true,
		]);
		$this->expectException(\InvalidArgumentException::class);
		$svc->update($saved->id, $flipped);
	}

	public function testUpdateForcesTheIdFromThePathNotTheBody(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha'));
		// A body carrying a different id must not create a second row or move the id.
		// (Same folders — those are immutable.)
		$body = Mapping::fromArray([
			'id' => 'attacker-supplied',
			'grafana_folder_uid' => 'uid-a',
			'nc_folder' => 'alpha',
			'mode' => 'sync',
		]);
		$svc->update($saved->id, $body);
		self::assertCount(1, $svc->list());
		self::assertSame($saved->id, $svc->list()[0]->id);
	}

	public function testUpdateUnknownIdThrows(): void {
		$svc = $this->service();
		$this->expectException(\OutOfBoundsException::class);
		$svc->update('missing', $this->mapping('uid-a', 'alpha'));
	}

	public function testDeleteRemovesTheMapping(): void {
		$svc = $this->service();
		$saved = $svc->add($this->mapping('uid-a', 'alpha'));
		$svc->delete($saved->id);
		self::assertCount(0, $svc->list());
	}

	public function testDeleteUnknownIdThrows(): void {
		$svc = $this->service();
		$this->expectException(\OutOfBoundsException::class);
		$svc->delete('missing');
	}

	public function testListSkipsMalformedRows(): void {
		// A stored list with one good row and one invalid row (bad mode) must yield
		// only the good one, never a blank panel.
		$this->store['mappings'] = json_encode([
			['grafana_folder_uid' => 'uid-a', 'nc_folder' => 'alpha', 'mode' => 'sync', 'format' => 'json'],
			['grafana_folder_uid' => 'uid-b', 'nc_folder' => 'bravo', 'mode' => 'bogus'],
			'not-even-an-array',
		]);
		$all = $this->service()->list();
		self::assertCount(1, $all);
		self::assertSame('uid-a', $all[0]->grafanaFolderUid);
	}

	public function testResolveForPathPicksTheNearestEnclosingMapping(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-outer', 'dashboards'));
		$inner = $svc->add($this->mapping('uid-inner', 'dashboards/observe'));

		$hit = $svc->resolveForPath('/admin/files/dashboards/observe/cpu.grafana.json');
		self::assertNotNull($hit);
		self::assertSame($inner->id, $hit->id, 'the deepest (longest-prefix) mapping wins');
	}

	public function testResolveForPathRespectsSegmentBoundaries(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'observe'));
		// "observability" must NOT be swallowed by the "observe" mapping.
		self::assertNull($svc->resolveForPath('/admin/files/observability/x.grafana.json'));
	}

	public function testResolveForPathReturnsNullOutsideFilesRoot(): void {
		$svc = $this->service();
		$svc->add($this->mapping('uid-a', 'observe'));
		self::assertNull($svc->resolveForPath('/some/other/path'));
	}
}
