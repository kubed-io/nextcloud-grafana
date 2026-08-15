<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\CreateService;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\DashboardSpec;
use OCA\GrafanaSync\Service\FolderMirror;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MirrorTimes;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\Files\File;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see CreateService} — create-on-land (Course 4 · Slice 1). These pin
 * the decisions that make "make a file → it's a dashboard" safe:
 *
 *  - the file body is upserted with the mapping's folder placement and id forced null;
 *  - the returned uid is stamped (with mode + version), the pill applied, all under the
 *    SyncGuard so the stamp doesn't echo into the writeback listener;
 *  - the loop-guard hash is the ORIGINAL file bytes (so a re-save is a no-op);
 *  - an empty body is tolerated (Grafana mints a minimal dashboard); malformed JSON and
 *    a uid-less response both fail loudly (the listener notifies).
 */
#[CoversClass(CreateService::class)]
final class CreateServiceTest extends TestCase {
	private GrafanaClient $grafana;
	private DashboardMetadata $metadata;
	private CreateService $service;

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->metadata = $this->createMock(DashboardMetadata::class);
		// A real SyncGuard: run() executes the callback (brackets it in enter/leave).
		$folderMirror = $this->createStub(FolderMirror::class);
		$folderMirror->method('folderUidFor')->willReturnCallback(
			static fn (Node $n, Mapping $m): ?string
				=> $m->grafanaFolderUid === '/' ? null : $m->grafanaFolderUid,
		);
		$this->service = new CreateService($this->grafana, $this->metadata, $folderMirror, new MirrorTimes(new NullLogger()), new SyncGuard(), new NullLogger());
	}

	private function mapping(string $folderUid = 'gf-demo', string $id = 'map-demo'): Mapping {
		return Mapping::fromArray(['id' => $id, 'grafana_folder_uid' => $folderUid, 'nc_folder' => 'demo', 'mode' => 'sync']);
	}

	private function file(int $id, string $content, string $name = 'My Dash.grafana'): File {
		$node = $this->createStub(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getContent')->willReturn($content);
		$node->method('getName')->willReturn($name);
		return $node;
	}

	public function testCreatesUpsertsWithFolderPlacementAndStampsTheReturnedUid(): void {
		$content = '{"title":"My Dash","panels":[]}';

		$captured = null;
		$this->grafana->expects(self::once())->method('upsertDashboard')
			->willReturnCallback(function (array $body) use (&$captured): array {
				$captured = $body;
				return ['uid' => 'new-uid', 'version' => 1, 'status' => 'success'];
			});

		// Loop-guard hash = sha1 of the ORIGINAL bytes; mode + version stamped.
		$this->metadata->expects(self::once())->method('stampSynced')
			->with(1, 'new-uid', Mapping::MODE_SYNC, '1', $content, 'map-demo');

		$uid = $this->service->createForFile($this->file(1, $content), $this->mapping());

		self::assertSame('new-uid', $uid);
		self::assertSame('gf-demo', $captured['folderUid'] ?? null, 'placed in the mapping folder');
		self::assertTrue($captured['overwrite'] ?? null);
		self::assertNull($captured['dashboard']->id, 'id forced null so Grafana keys on uid');
		self::assertSame('My Dash', $captured['dashboard']->title);
	}

	public function testARootMappingCreatesInGeneralWithoutAFolderUid(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'u', 'version' => 1];
		});

		$this->service->createForFile($this->file(1, '{"title":"X"}'), $this->mapping('/', 'map-root'));

		self::assertArrayNotHasKey('folderUid', $captured, 'General placement omits folderUid');
	}

	public function testAnEmptyBodyIsToleratedAndMintsAMinimalDashboard(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'u', 'version' => 1];
		});

		$uid = $this->service->createForFile($this->file(1, '   '), $this->mapping());

		self::assertSame('u', $uid);
		// title falls back to the filename stem (via toUpsertBody).
		self::assertSame('My Dash', $captured['dashboard']->title);
	}

	public function testMalformedJsonThrowsAndNeverUpserts(): void {
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('stampSynced');

		$this->expectException(\RuntimeException::class);
		$this->service->createForFile($this->file(1, '{not json'), $this->mapping());
	}

	public function testANonObjectJsonBodyThrowsRatherThanCoercingToEmpty(): void {
		// A JSON array or scalar is a malformed dashboard — fail loudly like PushService,
		// never silently mint an empty dashboard from it.
		$this->grafana->expects(self::never())->method('upsertDashboard');
		$this->metadata->expects(self::never())->method('stampSynced');

		$this->expectException(\RuntimeException::class);
		$this->service->createForFile($this->file(1, '["not","an","object"]'), $this->mapping());
	}

	public function testAUidLessResponseThrowsAndNeverStamps(): void {
		$this->grafana->method('upsertDashboard')->willReturn(['version' => 1]); // no uid
		$this->metadata->expects(self::never())->method('stampSynced');

		$this->expectException(\RuntimeException::class);
		$this->service->createForFile($this->file(1, '{"title":"X"}'), $this->mapping());
	}

	/**
	 * THE WINDOW THE COPY SCENARIOS FOUND. Read a dashboard back the instant after
	 * creating it and Grafana answers with `meta.created` and no `meta.updated` — so
	 * the file was given a creation date and kept whatever mtime it arrived with.
	 *
	 * On a new file that is merely untidy. On a COPY it is wrong and looks right: the
	 * copy inherits the source file's mtime, a real timestamp belonging to a different
	 * dashboard.
	 */
	public function testACreatedDashboardIsDatedByItsCreationWhenGrafanaHasNoUpdateYet(): void {
		$this->grafana->method('upsertDashboard')->willReturn(['uid' => 'new-uid', 'version' => 1]);
		$this->grafana->method('readDashboardSpec')
			->willReturn(new DashboardSpec((object)['uid' => 'new-uid'], null, 1771000000));

		$node = $this->fileExpectingTouch(1771000000);
		$this->service->createForFile($node, $this->mapping());
	}

	public function testAnUpdateTimeWinsOverTheCreationTimeWhenGrafanaHasBoth(): void {
		$this->grafana->method('upsertDashboard')->willReturn(['uid' => 'new-uid', 'version' => 1]);
		$this->grafana->method('readDashboardSpec')
			->willReturn(new DashboardSpec((object)['uid' => 'new-uid'], 1771000900, 1771000000));

		$node = $this->fileExpectingTouch(1771000900);
		$this->service->createForFile($node, $this->mapping());
	}

	/**
	 * A COPY NEVER RE-ADOPTS. Create-on-land deliberately upserts on a uid the file
	 * carries — that is how a file re-attaches to its dashboard. For a copy that same
	 * behaviour writes the copy over the dashboard it was copied FROM, because a synced
	 * mirror's body always names its own uid. Measured live: the source gained a v2 and
	 * no second dashboard existed.
	 */
	public function testACopyDropsTheBodysUidSoGrafanaMintsAFreshOne(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'minted-uid', 'version' => 1];
		});

		$this->service->createForFile($this->file(1, '{"title":"Board","uid":"original-uid"}'), $this->mapping(), true);

		self::assertFalse(isset($captured['dashboard']->uid), 'the original uid never reaches Grafana');
	}

	/**
	 * A COPY IS NAMED BY NEXTCLOUD, and the bytes cannot know it. The body is the
	 * original's, so its `title` still says the original's name; the file it landed in
	 * is called whatever Nextcloud picked to dodge the collision. Left alone, Grafana
	 * received a second dashboard titled identically to the first while the file said
	 * `(1)` — one name, three places, two answers.
	 *
	 * The name is the one the FILES CLIENT picks — `getUniqueName()` puts the counter
	 * immediately before the last extension — which since the single-segment cut is the
	 * same name `FilenameCodec::format()` builds. The title has to be read back out of
	 * THAT, counter included.
	 */
	public function testACopyIsTitledAfterTheNameNextcloudGaveTheFile(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'minted-uid', 'version' => 1];
		});

		$this->service->createForFile(
			$this->file(1, '{"title":"Fleet Health","uid":"original-uid"}', 'Fleet Health (1).grafana'),
			$this->mapping(),
			true,
		);

		self::assertSame('Fleet Health (1)', $captured['dashboard']->title ?? null);
	}

	/**
	 * The same rule with nothing to do: a copy that landed somewhere without a collision
	 * keeps the name it already had, so all three places agree without being touched.
	 */
	public function testACopyThatCollidedWithNothingKeepsItsTitle(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'minted-uid', 'version' => 1];
		});

		$this->service->createForFile(
			$this->file(1, '{"title":"Fleet Health","uid":"original-uid"}', 'Fleet Health.grafana'),
			$this->mapping(),
			true,
		);

		self::assertSame('Fleet Health', $captured['dashboard']->title ?? null);
	}

	/**
	 * CREATE-ON-LAND IS NOT A NAMING. A hand-made file dropped into a mapping keeps the
	 * title its author wrote, even where that disagrees with the filename — resolving
	 * that disagreement is the rename path's job, and doing it here would silently
	 * retitle every dashboard someone imported under a tidier filename.
	 */
	public function testCreateOnLandLeavesTheBodysTitleAlone(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'minted-uid', 'version' => 1];
		});

		$this->service->createForFile(
			$this->file(1, '{"title":"Fleet Health"}', 'Something Else.grafana'),
			$this->mapping(),
		);

		self::assertSame('Fleet Health', $captured['dashboard']->title ?? null);
	}

	public function testCreateOnLandStillReAdoptsTheUidTheFileCarries(): void {
		$captured = null;
		$this->grafana->method('upsertDashboard')->willReturnCallback(function (array $body) use (&$captured): array {
			$captured = $body;
			return ['uid' => 'original-uid', 'version' => 2];
		});

		$this->service->createForFile($this->file(1, '{"title":"Board","uid":"original-uid"}'), $this->mapping());

		self::assertSame('original-uid', $captured['dashboard']->uid ?? null, 're-adoption is the default and stays');
	}

	/**
	 * A file that asserts which second it is stamped with. The real {@see MirrorTimes}
	 * is wired into the service under test, so this reaches all the way through it.
	 */
	private function fileExpectingTouch(int $expected): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(1);
		$node->method('getContent')->willReturn('{"title":"X"}');
		$node->method('getName')->willReturn('X.grafana');
		$node->method('getMTime')->willReturn(0);
		$node->expects(self::once())->method('touch')->with($expected);
		return $node;
	}
}
