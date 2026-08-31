<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Settings;

use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Settings\MappingSettings;
use OCA\GrafanaSync\Settings\SyncSettings;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The interface standard (shared with the n8n master): all action buttons live in
 * one "Sync Actions" panel rendered **below** the Folder mappings. Locking the
 * priority ordering here means a future reshuffle can't silently float the buttons
 * back above the data.
 */
final class SyncSettingsTest extends TestCase {
	public function testSyncActionsRenderBelowFolderMappings(): void {
		$sync = new SyncSettings();
		$mapping = new MappingSettings(
			$this->createMock(MappingService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppManager::class),
		);
		self::assertGreaterThan(
			$mapping->getPriority(),
			$sync->getPriority(),
			'Sync Actions must render below Folder mappings',
		);
	}

	public function testSyncActionsSitInTheAppSection(): void {
		self::assertSame('grafana_sync', (new SyncSettings())->getSection());
	}
}
