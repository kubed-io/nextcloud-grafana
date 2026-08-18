<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Listener;

use OCA\GrafanaSync\Listener\CopyGuardListener;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCopiedEvent;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see CopyGuardListener} — a link is not copyable, and a link mapping is not a
 * destination. The non-DAV backstop of the Sabre `method:COPY` guard; same rule,
 * every other route.
 */
#[CoversClass(CopyGuardListener::class)]
final class CopyGuardListenerTest extends TestCase {
	/** @var array<string,string> nc folder → mapping mode; absent = unmapped */
	private array $mapped = [];

	/** the source file's own mode, '' = unmanaged */
	private string $sourceMode = '';

	public function testCopyingALinkIsRefusedWhereverItIsGoing(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];
		$this->sourceMode = Mapping::MODE_LINK;

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/cannot be copied/');
		$this->guard('/alice/files/Pointers/Fleet.grafana', '/alice/files/Scratch/Fleet.grafana');
	}

	public function testCopyingIntoALinkMappingIsRefused(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Pointers' => Mapping::MODE_LINK];
		$this->sourceMode = Mapping::MODE_SYNC;

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessageMatches('/files cannot be added/');
		$this->guard('/alice/files/Demo/Fleet.grafana', '/alice/files/Pointers/Fleet.grafana');
	}

	/** The base case must stay free, or the guard is a regression wearing a safety's name. */
	public function testASyncCopyIntoASyncMappingIsAllowed(): void {
		$this->mapped = ['Demo' => Mapping::MODE_SYNC, 'Reports' => Mapping::MODE_SYNC];
		$this->sourceMode = Mapping::MODE_SYNC;

		$this->guard('/alice/files/Demo/Fleet.grafana', '/alice/files/Reports/Fleet.grafana');

		self::assertTrue(true, 'not refused');
	}

	public function testAnUnmanagedCopyToAnUnmappedFolderIsAllowed(): void {
		$this->mapped = [];
		$this->sourceMode = '';

		$this->guard('/alice/files/Scratch/Fleet.grafana', '/alice/files/Elsewhere/Fleet.grafana');

		self::assertTrue(true, 'not refused');
	}

	/** The pull re-shapes mirrors under the SyncGuard; it must never trip a user guard. */
	public function testTheAppsOwnWritesPassUnjudged(): void {
		$this->mapped = ['Pointers' => Mapping::MODE_LINK];
		$this->sourceMode = Mapping::MODE_LINK;

		$guard = new SyncGuard();
		$guard->run(function () use ($guard): void {
			$this->guard('/alice/files/Pointers/Fleet.grafana', '/alice/files/Scratch/Fleet.grafana', $guard);
		});

		self::assertTrue(true, 'not refused');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function guard(string $from, string $to, ?SyncGuard $syncGuard = null): void {
		$mappings = $this->createStub(MappingService::class);
		$mappings->method('resolveForPath')->willReturnCallback(
			function (string $path): ?Mapping {
				foreach ($this->mapped as $folder => $mode) {
					if (str_contains($path, '/files/' . $folder . '/')) {
						return Mapping::fromArray([
							'id' => 'm-' . $folder,
							'grafana_folder_uid' => 'gf-' . $folder,
							'nc_folder' => $folder,
							'mode' => $mode,
						]);
					}
				}
				return null;
			},
		);

		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturn(
			$this->sourceMode === ''
				? null
				: new ManagedFile('dash-1', $this->sourceMode, '1', 'h', 'm-src', ''),
		);

		$source = $this->createStub(File::class);
		$source->method('getPath')->willReturn($from);
		$source->method('getName')->willReturn(basename($from));
		$source->method('getId')->willReturn(42);

		$target = $this->createStub(File::class);
		$target->method('getPath')->willReturn($to);
		$target->method('getName')->willReturn(basename($to));

		$listener = new CopyGuardListener($mappings, $metadata, $syncGuard ?? new SyncGuard(), new NullLogger());
		$listener->handle(new BeforeNodeCopiedEvent($source, $target));
	}
}
