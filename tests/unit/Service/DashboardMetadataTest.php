<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\Mapping;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** In-memory {@see IFilesMetadata} — a plain key→value bag. */
final class FakeFilesMetadata implements IFilesMetadata {
	/** @var array<string,string> */
	public array $data = [];

	#[\Override]
	public function hasKey(string $needle): bool {
		return array_key_exists($needle, $this->data);
	}

	#[\Override]
	public function getString(string $key): string {
		return $this->data[$key] ?? '';
	}

	#[\Override]
	public function setString(string $key, string $value, bool $index = false): self {
		$this->data[$key] = $value;
		return $this;
	}
}

/** In-memory {@see IFilesMetadataManager} — honours the `generate` flag like core. */
final class FakeFilesMetadataManager implements IFilesMetadataManager {
	/** @var array<int,FakeFilesMetadata> */
	public array $records = [];
	/** @var list<string> */
	public array $initialised = [];

	#[\Override]
	public function getMetadata(int $fileId, bool $generate = false): IFilesMetadata {
		if (!isset($this->records[$fileId])) {
			if (!$generate) {
				throw new FilesMetadataNotFoundException();
			}
			$this->records[$fileId] = new FakeFilesMetadata();
		}
		return $this->records[$fileId];
	}

	#[\Override]
	public function saveMetadata(IFilesMetadata $filesMetadata): void {
		// no-op: the fake mutates in place
	}

	#[\Override]
	public function deleteMetadata(int $fileId): void {
		unset($this->records[$fileId]);
	}

	#[\Override]
	public function initMetadata(string $key, string $type, bool $indexed, int $editPermission): void {
		$this->initialised[] = $key;
	}
}

/**
 * {@see DashboardMetadata} is the loop-prevention + DAV-stability contract. These pin
 * the two invariants the whole sync engine's correctness rests on: the syncedHash is
 * `sha1` of the *spec we sent* (never Grafana's echoed object, whose `version` moves —
 * risk #6), and `link` mode round-trips through the `reference` wire value (so it never
 * crashes core PROPFIND). Driven through an in-memory fake of the Files-Metadata API.
 */
#[CoversClass(DashboardMetadata::class)]
final class DashboardMetadataTest extends TestCase {
	private FakeFilesMetadataManager $manager;
	private DashboardMetadata $meta;

	protected function setUp(): void {
		$this->manager = new FakeFilesMetadataManager();
		$this->meta = new DashboardMetadata($this->manager);
	}

	public function testStampSyncedHashesTheProvidedSpecNotTheEchoedObject(): void {
		$this->meta->stampSynced(1, 'kel4vkt', Mapping::MODE_SYNC, '7', 'THE-BODY-BYTES', 'map-a');
		self::assertSame(sha1('THE-BODY-BYTES'), $this->manager->records[1]->data[DashboardMetadata::KEY_SYNCED_HASH]);
		// version is stored verbatim (and, per the contract, never hashed).
		self::assertSame('7', $this->manager->records[1]->data[DashboardMetadata::KEY_VERSION]);
	}

	public function testStampSyncedWritesTheFiveCoreKeysAndLeavesBankedOnesUnset(): void {
		$this->meta->stampSynced(1, 'kel4vkt', Mapping::MODE_SYNC, '7', 'body', 'map-a');
		$mf = $this->meta->read(1);
		self::assertNotNull($mf);
		self::assertSame('kel4vkt', $mf->uid);
		self::assertSame('map-a', $mf->mappingId);
		// Banked keys are registered but not stamped by the pull yet → read back as ''.
		self::assertSame('', $mf->folderUid);
		self::assertSame('', $mf->apiVersion);
	}

	public function testLinkModeIsStoredAsReferenceAndReadsBackAsLink(): void {
		$this->meta->write(1, [DashboardMetadata::KEY_MODE => Mapping::MODE_LINK]);
		// On the wire it is the safe `reference` value (bare `link` crashes core PROPFIND)…
		self::assertSame('reference', $this->manager->records[1]->data[DashboardMetadata::KEY_MODE]);
		// …but the canonical vocabulary comes back as `link`.
		self::assertSame(Mapping::MODE_LINK, $this->meta->read(1)?->mode);
	}

	public function testSyncModeStoresAndReadsVerbatim(): void {
		$this->meta->write(1, [DashboardMetadata::KEY_MODE => Mapping::MODE_SYNC]);
		self::assertSame('sync', $this->manager->records[1]->data[DashboardMetadata::KEY_MODE]);
		self::assertSame(Mapping::MODE_SYNC, $this->meta->read(1)?->mode);
	}

	public function testReadReturnsNullWhenTheFileHasNoRecord(): void {
		self::assertNull($this->meta->read(999));
	}

	public function testWriteOnlyTouchesTheGivenKeys(): void {
		$this->meta->stampSynced(1, 'kel4vkt', Mapping::MODE_SYNC, '7', 'body', 'map-a');
		$this->meta->write(1, [DashboardMetadata::KEY_MODE => Mapping::MODE_LINK]);
		$mf = $this->meta->read(1);
		self::assertSame('kel4vkt', $mf?->uid, 'uid untouched by the mode-only write');
		self::assertSame(Mapping::MODE_LINK, $mf?->mode);
	}

	public function testClearDropsTheWholeRecord(): void {
		$this->meta->stampSynced(1, 'kel4vkt', Mapping::MODE_SYNC, '7', 'body', 'map-a');
		$this->meta->clear(1);
		self::assertNull($this->meta->read(1));
	}

	public function testRegisterInitialisesEveryManagedKey(): void {
		$this->meta->register();
		self::assertSame(DashboardMetadata::KEYS, $this->manager->initialised);
	}
}
