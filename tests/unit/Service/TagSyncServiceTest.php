<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FolderMetadata;
use OCA\GrafanaSync\Service\GrafanaClient;
use OCA\GrafanaSync\Service\ManagedFile;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\NextcloudTags;
use OCA\GrafanaSync\Service\SyncGuard;
use OCA\GrafanaSync\Service\TagSet;
use OCA\GrafanaSync\Service\TagSyncService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see TagSyncService} — which direction a tag change travels, and when it doesn't.
 *
 * The two halves work differently on purpose. A dashboard's tags are body-native, so
 * pushing them is a FILE WRITE and the ordinary push path carries it; a folder's live
 * in a Grafana annotation, so that half is a direct call. Both are asserted here
 * because "it wrote the file" and "it called Grafana" are the two things that must
 * not be swapped.
 */
#[CoversClass(TagSyncService::class)]
final class TagSyncServiceTest extends TestCase {
	private GrafanaClient $grafana;
	private NextcloudTags $ncTags;
	private ?ManagedFile $managed = null;
	private string $folderUid = 'gf-team';
	private string $mode = Mapping::MODE_SYNC;
	private bool $rootMapping = false;
	private ?string $written = null;

	protected function setUp(): void {
		$this->grafana = $this->createMock(GrafanaClient::class);
		$this->ncTags = $this->createMock(NextcloudTags::class);
		$this->managed = new ManagedFile('d1', Mapping::MODE_SYNC, '', '', 'm-demo', '', '');
	}

	// ── dashboards ─────────────────────────────────────────────────────────────

	public function testTaggingASyncDashboardRewritesItsBody(): void {
		$file = $this->file('{"title":"Alpha","tags":["dns"]}');

		self::assertTrue($this->service()->pushDashboard($file, TagSet::of(['dns', 'prod'])));

		self::assertNotNull($this->written);
		self::assertSame(['dns', 'prod'], json_decode($this->written, true)['tags']);
	}

	/**
	 * NOT AN UPSERT. Tags are a top-level key in the spec, so the write IS the push —
	 * calling Grafana here too would send the dashboard twice for one gesture.
	 */
	public function testTaggingADashboardDoesNotCallGrafanaItself(): void {
		$this->grafana->expects(self::never())->method('upsertDashboard');

		$this->service()->pushDashboard($this->file('{"tags":[]}'), TagSet::of(['dns']));
	}

	/** THE LOOP GUARD. The body already agrees, so writing would only raise another event. */
	public function testABodyThatAlreadyAgreesIsNotRewritten(): void {
		$file = $this->file('{"tags":["linux","dns"]}');

		self::assertFalse($this->service()->pushDashboard($file, TagSet::of(['dns', 'linux'])));
		self::assertNull($this->written);
	}

	/** A link's tags are Grafana's; the next pull puts its set back. */
	public function testALinkIsNotPushed(): void {
		$this->managed = new ManagedFile('d1', Mapping::MODE_LINK, '', '', 'm-demo', '', '');

		self::assertFalse($this->service()->pushDashboard($this->file('{"tags":[]}'), TagSet::of(['mine'])));
		self::assertNull($this->written);
	}

	/**
	 * AN UNMAPPED FILE STILL GETS ITS BODY UPDATED. Tag sync is a mapped-file feature
	 * in that nothing reaches Grafana — but the file's own two surfaces keep tracking
	 * each other, which is what lets a tag applied out here survive being moved back
	 * into a mapping. `features/AGENTS.md` states it as a positive for that reason.
	 */
	public function testAnUnmappedFilesTwoSurfacesStillTrackEachOther(): void {
		$this->managed = null;

		self::assertTrue($this->service()->pushDashboard($this->file('{"tags":["dns"]}'), TagSet::of(['mine'])));
		self::assertSame(['mine'], json_decode((string)$this->written, true)['tags']);
	}

	/** ...and nothing about that reaches Grafana. */
	public function testAnUnmappedFileNeverReachesGrafana(): void {
		$this->managed = null;
		$this->grafana->expects(self::never())->method('upsertDashboard');

		$this->service()->pushDashboard($this->file('{"tags":["dns"]}'), TagSet::of(['mine']));
	}

	public function testAFileThatIsNotJsonIsLeftAlone(): void {
		self::assertFalse($this->service()->pushDashboard($this->file('not json'), TagSet::of(['dns'])));
		self::assertNull($this->written);
	}

	/** Encoding matters: a JSON object here is a dashboard Grafana will not read back. */
	public function testTagsAreWrittenAsAJsonArray(): void {
		$this->service()->pushDashboard($this->file('{"tags":["old"]}'), TagSet::of(['a', 'b']));

		self::assertStringContainsString('"tags": [', (string)$this->written);
	}

	public function testTheInboundApplyGoesThroughTheGuard(): void {
		$guard = new SyncGuard();
		$seen = false;
		$this->ncTags->method('set')->willReturnCallback(
			function () use ($guard, &$seen): bool {
				$seen = $guard->active();
				return true;
			},
		);

		$this->service($guard)->applyToDashboard($this->file('{}'), TagSet::of(['dns']));

		self::assertTrue($seen, 'the import must not read as a user tag change');
	}

	// ── folders ────────────────────────────────────────────────────────────────

	public function testTaggingASyncFolderWritesTheAnnotation(): void {
		$this->grafana->method('readFolderTags')->willReturn(TagSet::of(['old']));
		$this->grafana->expects(self::once())->method('writeFolderTags')->with(
			'gf-team',
			self::callback(fn (TagSet $t): bool => $t->equals(TagSet::of(['quarterly']))),
		);

		self::assertTrue($this->service()->pushFolder($this->folder(), TagSet::of(['quarterly'])));
	}

	public function testAFolderWhoseTagsAlreadyAgreeIsNotWritten(): void {
		$this->grafana->method('readFolderTags')->willReturn(TagSet::of(['ops', 'quarterly']));
		$this->grafana->expects(self::never())->method('writeFolderTags');

		self::assertFalse($this->service()->pushFolder($this->folder(), TagSet::of(['quarterly', 'ops'])));
	}

	/** A folder under a mapping is a plain folder until a dashboard lands beneath it. */
	public function testAnUnstampedFolderIsNotPushed(): void {
		$this->folderUid = '';
		$this->grafana->expects(self::never())->method('writeFolderTags');

		self::assertFalse($this->service()->pushFolder($this->folder(), TagSet::of(['mine'])));
	}

	/**
	 * THE MAPPED FOLDER IS NEVER STAMPED, so asking FolderMetadata for it answers ''
	 * — and reading that as "not ours" would refuse to tag the most obvious folder in
	 * the mapping. Its uid is the mapping's, which is what the pull uses for it too.
	 */
	public function testTheMappedFolderItselfPushesToTheMappingsGrafanaFolder(): void {
		$this->folderUid = ''; // nothing stamps the mapping's own folder
		$this->grafana->method('readFolderTags')->willReturn(TagSet::empty());
		$this->grafana->expects(self::once())->method('writeFolderTags')->with(
			'gf-demo',
			self::callback(fn (TagSet $t): bool => $t->equals(TagSet::of(['quarterly']))),
		);

		self::assertTrue($this->service()->pushFolder($this->folder(id: 30), TagSet::of(['quarterly'])));
	}

	/** A root mapping is the whole instance, not a folder that can carry an annotation. */
	public function testARootMappingHasNoFolderToTag(): void {
		$this->folderUid = '';
		$this->rootMapping = true;
		$this->grafana->expects(self::never())->method('writeFolderTags');

		self::assertFalse($this->service()->pushFolder($this->folder(id: 30), TagSet::of(['quarterly'])));
	}

	public function testAFolderInALinkMappingIsNotPushed(): void {
		$this->mode = Mapping::MODE_LINK;
		$this->grafana->expects(self::never())->method('writeFolderTags');

		self::assertFalse($this->service()->pushFolder($this->folder(), TagSet::of(['mine'])));
	}

	/** A tag click must never surface as an error; the next sync settles it. */
	public function testAGrafanaFailureDoesNotEscape(): void {
		$this->grafana->method('readFolderTags')->willThrowException(new \RuntimeException('unreachable'));

		self::assertFalse($this->service()->pushFolder($this->folder(), TagSet::of(['quarterly'])));
	}

	public function testApplyToFolderImportsWhatGrafanaHolds(): void {
		$this->grafana->method('readFolderTags')->willReturn(TagSet::of(['quarterly']));
		$this->ncTags->expects(self::once())->method('set')->with(
			20,
			self::callback(fn (TagSet $t): bool => $t->equals(TagSet::of(['quarterly']))),
		);

		$this->service()->applyToFolder($this->folder(), 'gf-team');
	}

	public function testApplyToFolderWithNoUidDoesNothing(): void {
		$this->ncTags->expects(self::never())->method('set');

		$this->service()->applyToFolder($this->folder(), '');
	}

	/** A pull that cannot read one folder's tags has still mirrored the dashboards. */
	public function testApplyToFolderSwallowsAGrafanaFailure(): void {
		$this->grafana->method('readFolderTags')->willThrowException(new \RuntimeException('unreachable'));
		$this->ncTags->expects(self::never())->method('set');

		$this->service()->applyToFolder($this->folder(), 'gf-team');
	}

	// ── harness ────────────────────────────────────────────────────────────────

	private function service(?SyncGuard $guard = null): TagSyncService {
		$metadata = $this->createStub(DashboardMetadata::class);
		$metadata->method('read')->willReturnCallback(fn (): ?ManagedFile => $this->managed);

		$folders = $this->createStub(FolderMetadata::class);
		$folders->method('uidOf')->willReturnCallback(fn (): string => $this->folderUid);

		$mappings = $this->createStub(MappingService::class);
		$mappings->method('resolveForPath')->willReturnCallback(
			fn (): Mapping => Mapping::fromArray([
				'id' => 'm-demo',
				'grafana_folder_uid' => $this->rootMapping ? '/' : 'gf-demo',
				'nc_folder' => 'Demo',
				'nc_folder_id' => 30,
				'mode' => $this->mode,
			]),
		);

		return new TagSyncService(
			$this->ncTags,
			$this->grafana,
			$metadata,
			$folders,
			$mappings,
			$guard ?? new SyncGuard(),
			new NullLogger(),
		);
	}

	private function file(string $content): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn(11);
		$file->method('getName')->willReturn('Alpha.grafana');
		$file->method('getContent')->willReturn($content);
		$file->method('putContent')->willReturnCallback(
			function ($data): void {
				$this->written = (string)$data;
			},
		);
		return $file;
	}

	private function folder(int $id = 20): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn('/alice/files/Demo/Team');
		return $folder;
	}
}
