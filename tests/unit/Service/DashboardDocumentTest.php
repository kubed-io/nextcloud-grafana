<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardBody;
use OCA\GrafanaSync\Service\DashboardDocument;
use OCA\GrafanaSync\Service\TagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DashboardDocument} — any `.grafana` file, classic or version 2,
 * read through one door, with uid, title and tags answered for both shapes.
 */
#[CoversClass(DashboardDocument::class)]
final class DashboardDocumentTest extends TestCase {
	private const YAML = "apiVersion: dashboard.grafana.app/v2\n"
		. "kind: Dashboard\n"
		. "metadata:\n  name: fleet\n"
		. "spec:\n  title: Fleet Health\n  tags:\n    - ops\n    - dns\n  elements: {  }\n";

	public function testDecodesJsonAsClassic(): void {
		$doc = DashboardDocument::decode('{"uid":"fleet","title":"Fleet Health","tags":["ops"]}');

		self::assertFalse($doc->isResource());
		self::assertSame('', $doc->apiVersion());
		self::assertSame('fleet', $doc->uid());
	}

	public function testDecodesYamlResource(): void {
		$doc = DashboardDocument::decode(self::YAML);

		self::assertTrue($doc->isResource());
		self::assertSame('dashboard.grafana.app/v2', $doc->apiVersion());
		self::assertEquals(new \stdClass(), $doc->body()->spec->elements);
	}

	/** The shape decides what a body is, not the syntax it happened to be written in. */
	public function testDecodesAJsonResource(): void {
		$doc = DashboardDocument::decode('{"apiVersion":"dashboard.grafana.app/v2beta1","kind":"Dashboard","metadata":{"name":"x"},"spec":{}}');

		self::assertTrue($doc->isResource());
		self::assertSame('dashboard.grafana.app/v2beta1', $doc->apiVersion());
	}

	/** The + New menu writes an empty file before anything else; it is a dashboard-to-be. */
	public function testEmptyFileIsAnEmptyClassicDashboard(): void {
		$doc = DashboardDocument::decode("  \n");

		self::assertFalse($doc->isResource());
		self::assertEquals(new \stdClass(), $doc->body());
	}

	/** A file that already IS a dashboard must never read as an empty one. */
	public function testDecodeNonEmptyRefusesAnEmptiedFile(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('The dashboard file is empty.');

		DashboardDocument::decodeNonEmpty(" \n");
	}

	/** @return iterable<string, array{string, string}> */
	public static function notDashboards(): iterable {
		yield 'a JSON list' => ['[1,2]', 'JSON'];
		yield 'broken JSON' => ['{"title": ', 'JSON'];
		yield 'plain text' => ['just text', 'YAML'];
		yield 'a YAML list' => ["- a\n- b\n", 'YAML'];
		yield 'tab-indented YAML' => ["a:\n\tb: c\n", 'YAML'];
	}

	#[DataProvider('notDashboards')]
	public function testRefusesNonDashboards(string $content, string $named): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/' . $named . '/');

		DashboardDocument::decode($content);
	}

	/** @return iterable<string, array{string}> */
	public static function malformedResources(): iterable {
		yield 'a lower-case kind' => ["apiVersion: dashboard.grafana.app/v2\nkind: dashboard\nmetadata:\n  name: d1\nspec: {}\n"];
		yield 'a path in apiVersion' => ["apiVersion: dashboard.grafana.app/../../api/x?\nkind: Dashboard\nmetadata:\n  name: d1\nspec: {}\n"];
		yield 'a query in apiVersion' => ["apiVersion: 'dashboard.grafana.app/v2?x=1'\nkind: Dashboard\nmetadata:\n  name: d1\nspec: {}\n"];
		yield 'another group' => ["apiVersion: folder.grafana.app/v1\nkind: Dashboard\nspec: {}\n"];
		yield 'no spec' => ["apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: d1\n"];
		yield 'a JSON body that names a kind' => ['{"kind":"Dashboard","title":"x"}'];
		yield 'a newline after the version' => ['{"apiVersion":"dashboard.grafana.app/v2\\n","kind":"Dashboard","metadata":{"name":"d"},"spec":{}}'];
	}

	/**
	 * A body that reaches for the resource shape and misses is refused, never sent as a
	 * classic dashboard: that would replace the real dashboard with an empty one, and an
	 * `apiVersion` is part of the request URL.
	 */
	#[DataProvider('malformedResources')]
	public function testRefusesAnAlmostResource(string $content): void {
		$this->expectException(\RuntimeException::class);

		DashboardDocument::decode($content);
	}

	/**
	 * Nothing this app writes is YAML except a resource and a pointer, so other YAML is a
	 * mistake. `TODO: fix this` is a YAML map — and in a version-1 folder it used to be
	 * refused as invalid JSON, which is the behaviour it keeps.
	 */
	public function testRefusesYamlThatIsNeitherAResourceNorAPointer(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('neither valid JSON nor a version 2 dashboard');

		DashboardDocument::decode("TODO: fix this\n");
	}

	/** A version-2 link's pointer is YAML too, and its tags are read from it. */
	public function testAcceptsAYamlPointer(): void {
		$doc = DashboardDocument::decode("\$schema: grafana.reference/v1\nuid: d1\ntitle: Fleet\ntags:\n  - ops\n");

		self::assertFalse($doc->isResource());
		self::assertTrue($doc->tags()->equals(TagSet::of(['ops'])));
	}

	public function testAcceptsEveryDashboardApiVersionGrafanaNames(): void {
		foreach (['v1', 'v2', 'v2beta1', 'v2alpha1', 'v0alpha1'] as $v) {
			self::assertTrue(DashboardDocument::decode("apiVersion: dashboard.grafana.app/$v\nkind: Dashboard\nmetadata:\n  name: d\nspec: {}\n")->isResource(), $v);
		}
	}

	public function testUidTitleTagsReadBothShapes(): void {
		$classic = DashboardDocument::decode('{"uid":"fleet","title":" Fleet Health ","tags":["ops","dns"]}');
		$resource = DashboardDocument::decode(self::YAML);

		foreach ([$classic, $resource] as $doc) {
			self::assertSame('fleet', $doc->uid());
			self::assertSame('Fleet Health', $doc->title());
			self::assertTrue($doc->tags()->equals(TagSet::of(['ops', 'dns'])));
		}
	}

	public function testWithUidSetsMetadataNameOnAResource(): void {
		$doc = DashboardDocument::decode(self::YAML)->withUid('other');

		self::assertSame('other', $doc->body()->metadata->name);
		self::assertFalse(isset($doc->body()->uid));
	}

	public function testWithersNeverTouchTheOriginal(): void {
		$doc = DashboardDocument::decode(self::YAML);

		$doc->withUid('other')->withTitle('Renamed')->withTags(TagSet::of(['x']));

		self::assertSame('fleet', $doc->uid());
		self::assertSame('Fleet Health', $doc->title());
	}

	public function testWithoutUidDropsIt(): void {
		self::assertSame('', DashboardDocument::decode(self::YAML)->withoutUid()->uid());
		self::assertSame('', DashboardDocument::decode('{"uid":"fleet"}')->withoutUid()->uid());
	}

	/** A gappy set must still reach the file as a list; `{"0": …}` is not tags to Grafana. */
	public function testWithTagsWritesAList(): void {
		$doc = DashboardDocument::decode('{"tags":[]}')->withTags(TagSet::of([2 => 'b', 5 => 'a']));

		self::assertTrue(array_is_list($doc->body()->tags));
	}

	public function testEncodeKeepsTheShapesFormat(): void {
		$classic = DashboardDocument::decode('{"uid":"fleet","title":"Café","options":{}}');
		self::assertSame(json_encode($classic->body(), DashboardBody::JSON_PRETTY), $classic->encode());

		$resource = DashboardDocument::decode(self::YAML);
		self::assertStringStartsWith("apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\n", $resource->encode());
		self::assertEquals($resource->body(), DashboardDocument::decode($resource->encode())->body());
	}
}
