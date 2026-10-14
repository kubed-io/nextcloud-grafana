<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardYaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DashboardYaml} — a version-2 file's YAML, held to the standard
 * the JSON is: what goes in comes back out with every shape intact.
 *
 * The fixture is a real v2 dashboard from a live Grafana 13.2.1, trimmed to two panels
 * that carry overrides and value mappings, laid out as a tab over an auto grid.
 */
#[CoversClass(DashboardYaml::class)]
final class DashboardYamlTest extends TestCase {
	private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	private static function resource(): \stdClass {
		$spec = json_decode((string)file_get_contents(__DIR__ . '/../Fixtures/v2-dashboard-spec.json'), false, 512, JSON_THROW_ON_ERROR);
		return (object)[
			'apiVersion' => 'dashboard.grafana.app/v2',
			'kind' => 'Dashboard',
			'metadata' => (object)['name' => 'fixture'],
			'spec' => $spec,
		];
	}

	public function testRoundTripIsJsonIdentical(): void {
		$doc = self::resource();

		$back = DashboardYaml::parse(DashboardYaml::dump($doc));

		self::assertSame(json_encode($doc, self::JSON), json_encode($back, self::JSON));
	}

	/** `bodyDiffers()` compares bytes, so the same dashboard must always be the same file. */
	public function testDumpIsStable(): void {
		$yaml = DashboardYaml::dump(self::resource());

		$again = DashboardYaml::parse($yaml);
		self::assertInstanceOf(\stdClass::class, $again);
		self::assertSame($yaml, DashboardYaml::dump($again));
	}

	/**
	 * Every shape JSON can hold and YAML could get wrong. An object keyed `"0"`, `"1"`
	 * is the dangerous one: PHP reads those keys as a list, and a list dumps as a
	 * sequence — so a value mapping would come back as an array of its own entries.
	 */
	public function testEdgeShapesSurvive(): void {
		$json = '{"m":{"0":"a","1":"b"},"e":{},"l":[],"n":null,"f":1.0,"s":"012","t":"true",'
			. '"md":"line1\nline2","nested":[{"a":1,"b":{}},[1,2]]}';
		$doc = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

		$back = DashboardYaml::parse(DashboardYaml::dump($doc));

		self::assertSame(json_encode($doc, self::JSON), json_encode($back, self::JSON));
		self::assertInstanceOf(\stdClass::class, $back->m);
		self::assertInstanceOf(\stdClass::class, $back->e);
		self::assertSame([], $back->l);
	}

	public function testListsOfMapsAreCompact(): void {
		$yaml = DashboardYaml::dump((object)['a' => [(object)['k' => 1, 'j' => 2]]]);

		self::assertSame("a:\n  - k: 1\n    j: 2\n", $yaml);
	}

	/** A markdown panel's text reads as text, not as one escaped line. */
	public function testMultiLineStringsAreLiteralBlocks(): void {
		$yaml = DashboardYaml::dump((object)['md' => "line1\nline2"]);

		self::assertSame("md: |-\n  line1\n  line2\n", $yaml);
	}

	public function testInvalidYamlSaysSo(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('The dashboard file is not valid YAML');

		DashboardYaml::parse("a: [1, 2\nb: c");
	}
}
