<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\DashboardBody;
use OCA\GrafanaSync\Service\DashboardDocument;
use OCA\GrafanaSync\Service\GrafanaClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see DashboardBody} is pure logic, but load-bearing: stripping the volatile
 * `id`/`version` from the stored spec is what keeps the loop-guard hash stable across
 * Grafana's per-save version bumps (saga Ch1 risk #6), and the upsert body's
 * `id:null` + `folderUid` placement is what makes a writeback land as the same
 * dashboard. These pin those behaviours so later pull/push work can't quietly break
 * them.
 */
#[CoversClass(DashboardBody::class)]
final class DashboardBodyTest extends TestCase {
	/** @return array<string,mixed> */
	private function decode(string $json): array {
		return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	}

	/** Decode a literal spec the way the pull does — objects, not assoc arrays. */
	private function spec(string $json): \stdClass {
		$o = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	public function testEncodeSyncStripsVolatileFieldsButKeepsUid(): void {
		$body = DashboardBody::encodeSync($this->spec(
			'{"id":42,"version":7,"uid":"kel4vkt","title":"CPU","panels":[]}',
		));
		$decoded = $this->decode($body);
		self::assertArrayNotHasKey('id', $decoded, 'internal numeric id stripped');
		self::assertArrayNotHasKey('version', $decoded, 'per-save version stripped (never hashed)');
		self::assertSame('kel4vkt', $decoded['uid'], 'stable uid identity kept');
		self::assertSame('CPU', $decoded['title']);
	}

	public function testEncodeSyncIsStableAcrossVersionBumps(): void {
		// The whole point: the same dashboard at version 7 vs 8 hashes identically.
		$v7 = DashboardBody::encodeSync($this->spec('{"uid":"u1","title":"T","version":7}'));
		$v8 = DashboardBody::encodeSync($this->spec('{"uid":"u1","title":"T","version":8}'));
		self::assertSame(sha1($v7), sha1($v8));
	}

	/**
	 * The regression this signature change exists for. A real dashboard is full of
	 * empty JSON objects — `timepicker: {}`, a panel's `options: {}`,
	 * `fieldConfig.defaults: {}`. Under the previous `array` signature these arrived
	 * as empty PHP arrays and were written to the file as `[]`, so the mirrored file
	 * no longer matched the dashboard, and the push (which decodes the file as
	 * objects, carefully) sent the `[]` on to Grafana.
	 *
	 * Asserting on the raw JSON text, not on a decoded structure: `{}` and `[]` both
	 * decode to `[]` under assoc, which is exactly the blindness that let this ship.
	 */
	public function testEncodeSyncPreservesEmptyObjectsAsObjects(): void {
		$body = DashboardBody::encodeSync($this->spec(
			'{"uid":"u1","title":"T","timepicker":{},"templating":{"list":[]},'
			. '"panels":[{"id":1,"options":{},"fieldConfig":{"defaults":{},"overrides":[]}}]}',
		));

		self::assertStringNotContainsString('"timepicker": []', $body, 'an empty object must not flatten to an array');
		self::assertStringNotContainsString('"options": []', $body);
		self::assertStringNotContainsString('"defaults": []', $body);
		self::assertStringContainsString('"timepicker": {}', $body);
		self::assertStringContainsString('"options": {}', $body);
		self::assertStringContainsString('"defaults": {}', $body);
		// …and the genuinely-empty ARRAYS stay arrays. A blanket JSON_FORCE_OBJECT
		// "fix" would pass every assertion above and corrupt these instead.
		self::assertStringContainsString('"list": []', $body);
		self::assertStringContainsString('"overrides": []', $body);
	}

	/** The caller reads `version` off the spec after encoding, so encodeSync must not mutate it. */
	public function testEncodeSyncDoesNotMutateTheCallersSpec(): void {
		$spec = $this->spec('{"uid":"u1","title":"T","id":42,"version":9}');
		DashboardBody::encodeSync($spec);
		self::assertSame(9, $spec->version, 'version still readable for the metadata stamp');
		self::assertSame(42, $spec->id);
	}

	public function testEncodeReferenceBuildsAPointer(): void {
		$ref = $this->decode(DashboardBody::encodeReference(
			['uid' => 'u1', 'title' => 'CPU', 'tags' => ['prod', '', 42, 'team']],
			'https://grafana.example.com/d/u1/cpu',
			'nc-alpha',
		));
		self::assertSame(DashboardBody::REFERENCE_SCHEMA, $ref['$schema']);
		self::assertSame('u1', $ref['uid']);
		self::assertSame('CPU', $ref['title']);
		self::assertSame('https://grafana.example.com/d/u1/cpu', $ref['url']);
		self::assertSame('nc-alpha', $ref['folderUid']);
		self::assertSame(['prod', 'team'], $ref['tags'], 'non-string / empty tags filtered out');
	}

	public function testEncodeReferenceNullUrlWhenEmpty(): void {
		$ref = $this->decode(DashboardBody::encodeReference(['uid' => 'u1'], '', ''));
		self::assertNull($ref['url']);
		self::assertSame('u1', $ref['title'], 'title falls back to uid');
	}

	public function testToUpsertBodyForcesIdNullAndDropsVersion(): void {
		$dash = (object)['uid' => 'u1', 'title' => 'CPU', 'id' => 99, 'version' => 5];
		$body = DashboardBody::toUpsertBody($dash, 'nc-alpha');
		self::assertNull($body['dashboard']->id, 'id forced null so Grafana keys on uid');
		self::assertObjectNotHasProperty('version', $body['dashboard'], 'version dropped, overwrite instead');
		self::assertTrue($body['overwrite']);
		self::assertSame('nc-alpha', $body['folderUid']);
	}

	public function testToUpsertBodyOmitsFolderUidForRoot(): void {
		$dash = (object)['uid' => 'u1', 'title' => 'CPU'];
		foreach ([null, '', GrafanaClient::FOLDER_GENERAL] as $root) {
			$body = DashboardBody::toUpsertBody($dash, $root);
			self::assertArrayNotHasKey('folderUid', $body, "root ($root) omits folderUid");
		}
	}

	public function testToUpsertBodyTitleFallsBackToFilenameStem(): void {
		$dash = (object)['uid' => 'u1']; // no title
		$body = DashboardBody::toUpsertBody($dash, null, 'My Board (2).grafana');
		self::assertSame('My Board', $body['dashboard']->title, 'title derived from filename, collision suffix stripped');
	}

	// ── version 2 ──────────────────────────────────────────────────────────────

	public function testEncodeResourceStripsVolatileMetadataAndStatus(): void {
		$read = json_decode('{"apiVersion":"dashboard.grafana.app/v2","kind":"Dashboard","metadata":{"name":"fleet",'
			. '"namespace":"default","uid":"k8s-uid","resourceVersion":"17","generation":4,"creationTimestamp":"2026-10-14T18:17:46Z",'
			. '"labels":{"grafana.app/deprecatedInternalID":"1"},"annotations":{"grafana.app/folder":"f1"},"managedFields":[]},'
			. '"spec":{"title":"Fleet","elements":{}},"status":{"conversion":{"failed":false}}}', false);

		self::assertSame(
			"apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: fleet\nspec:\n  title: Fleet\n  elements: {  }\n",
			DashboardBody::encodeResource($read),
		);
	}

	public function testResourceBodySetsFolderAndMessage(): void {
		$doc = DashboardDocument::decode("apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: fleet\n  labels:\n    a: b\nspec:\n  title: Fleet\n");

		$body = DashboardBody::toResourceBody($doc, 'f1', 'Fleet.grafana', 'saved');

		self::assertSame('dashboard.grafana.app/v2', $body['apiVersion']);
		self::assertSame('Dashboard', $body['kind']);
		self::assertSame(
			['name' => 'fleet', 'annotations' => ['grafana.app/folder' => 'f1', 'grafana.app/message' => 'saved']],
			$body['metadata'],
		);
		self::assertSame('Fleet', $body['spec']->title);
	}

	/** Without the annotation Grafana moves the dashboard to General — only the root may omit it. */
	public function testResourceBodyOmitsTheFolderOnlyForTheRoot(): void {
		$doc = DashboardDocument::decode("apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: fleet\nspec: {}\n");

		foreach ([null, '', GrafanaClient::FOLDER_GENERAL] as $root) {
			$annotations = DashboardBody::toResourceBody($doc, $root)['metadata']['annotations'];
			self::assertArrayNotHasKey('grafana.app/folder', $annotations);
			self::assertSame('Updated by Nextcloud (grafana_sync)', $annotations['grafana.app/message']);
		}
	}

	public function testResourceBodyTitlesAnUntitledDashboardFromTheFile(): void {
		$doc = DashboardDocument::decode("apiVersion: dashboard.grafana.app/v2\nkind: Dashboard\nmetadata:\n  name: fleet\nspec: {}\n");

		self::assertSame('Fleet Health', DashboardBody::toResourceBody($doc, 'f1', 'Fleet Health (2).grafana')['spec']->title);
	}

	public function testReferenceAsYaml(): void {
		$row = ['uid' => 'fleet', 'title' => 'Fleet', 'tags' => ['ops']];

		self::assertSame(
			"\$schema: grafana.reference/v1\nuid: fleet\ntitle: Fleet\nurl: 'https://grafana.test/d/fleet'\nfolderUid: f1\ntags:\n  - ops\n",
			DashboardBody::encodeReference($row, 'https://grafana.test/d/fleet', 'f1', 2),
		);
		// Version 1 is untouched.
		self::assertStringStartsWith('{', DashboardBody::encodeReference($row, 'https://grafana.test/d/fleet', 'f1'));
	}

	public function testMintUidShape(): void {
		self::assertMatchesRegularExpression('/^[a-z0-9]{14}$/', DashboardBody::mintUid());
		self::assertNotSame(DashboardBody::mintUid(), DashboardBody::mintUid());
	}
}
