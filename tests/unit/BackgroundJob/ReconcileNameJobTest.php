<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\BackgroundJob;

use OCA\GrafanaSync\BackgroundJob\ReconcileNameJob;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\PushService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see ReconcileNameJob} — the deferred half of a rename, which writes the file's new
 * name into its title. A version-2 file keeps its YAML when it does.
 */
#[CoversClass(ReconcileNameJob::class)]
final class ReconcileNameJobTest extends TestCase {
	private ?string $written = null;

	private function runJob(ReconcileNameJob $job, string $action): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, ['fileId' => 42, 'userId' => 'alice', 'action' => $action]);
	}

	private function job(File $node, PushService $push): ReconcileNameJob {
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('getById')->willReturn([$node]);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturn(new ManagedFile('dash-1', Mapping::MODE_SYNC, '1', 'h', 'm-1', ''));

		return new ReconcileNameJob($this->createStub(ITimeFactory::class), $root, $metadata, $push, new SyncGuard(), new NullLogger());
	}

	private function file(string $name, string $content): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn($name);
		$node->method('getContent')->willReturn($content);
		$node->method('putContent')->willReturnCallback(function (string $c): void {
			$this->written = $c;
		});
		return $node;
	}

	public function testTitleFromFilenameRewritesAV2FileAsYaml(): void {
		$yaml = "apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: dash-1\nspec:\n  title: Old\n  elements: {  }\n";
		$push = $this->createMock(PushService::class);
		$push->expects(self::once())->method('push');

		$this->runJob($this->job($this->file('New Name.grafana', $yaml), $push), 'title_from_filename');

		self::assertSame(
			"apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: dash-1\nspec:\n  title: 'New Name'\n  elements: {  }\n",
			$this->written,
		);
	}

	public function testTitleFromFilenameKeepsAClassicFileJson(): void {
		$push = $this->createMock(PushService::class);
		$push->expects(self::once())->method('push');

		$this->runJob($this->job($this->file('New Name.grafana', '{"title":"Old","panels":[]}'), $push), 'title_from_filename');

		self::assertSame("{\n    \"title\": \"New Name\",\n    \"panels\": []\n}", $this->written);
	}
}
