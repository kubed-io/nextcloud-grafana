<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Unit\Service;

use OCA\GrafanaSync\Service\TagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see TagSet} — the one definition of "the same tags".
 *
 * Most of these are loop tests wearing other clothes. Tags cross three surfaces with
 * three orderings, and every comparison that answers "different" when it should
 * answer "same" is a sync that writes, raises an event, compares again and writes
 * forever.
 */
#[CoversClass(TagSet::class)]
final class TagSetTest extends TestCase {
	/** THE LOOP TEST. Grafana returns its order, Nextcloud returns the join's. */
	public function testOrderDoesNotMakeTwoSetsDifferent(): void {
		self::assertTrue(
			TagSet::of(['dns', 'linux'])->equals(TagSet::of(['linux', 'dns'])),
		);
	}

	public function testADifferentMemberMakesThemDifferent(): void {
		self::assertFalse(TagSet::of(['dns', 'linux'])->equals(TagSet::of(['dns', 'prod'])));
	}

	public function testASubsetIsNotEqual(): void {
		self::assertFalse(TagSet::of(['dns', 'linux'])->equals(TagSet::of(['dns'])));
	}

	public function testTwoEmptySetsAreEqual(): void {
		self::assertTrue(TagSet::empty()->equals(TagSet::of([])));
	}

	/**
	 * A trailing comma is the most ordinary thing a person types, and an empty tag
	 * name is one Nextcloud refuses — which would fail the whole assignment, not just
	 * that tag.
	 */
	public function testEmptiesAndWhitespaceAreDropped(): void {
		self::assertSame(['dns', 'linux'], TagSet::fromAnnotation('dns, , linux,  ')->toList());
	}

	public function testDuplicatesCollapse(): void {
		self::assertSame(['dns'], TagSet::of(['dns', 'dns', ' dns '])->toList());
	}

	/** Grafana treats these as two tags, and so does Nextcloud. Folding them merges two into one. */
	public function testCaseIsNotCollapsed(): void {
		self::assertFalse(TagSet::of(['DNS'])->equals(TagSet::of(['dns'])));
	}

	public function testAnnotationRoundTrip(): void {
		$tags = TagSet::of(['Q3 Review', 'café', 'ops/urgent', '日本語']);

		self::assertTrue($tags->equals(TagSet::fromAnnotation($tags->toAnnotation())));
	}

	/** Exactly the text the live probe proved an annotation accepts unescaped. */
	public function testTagsGrafanaLabelsWouldRejectSurviveTheAnnotation(): void {
		self::assertSame(
			'Q3 Review, café, ops/urgent, 日本語',
			TagSet::fromAnnotation('Q3 Review, café, ops/urgent, 日本語')->toAnnotation(),
		);
	}

	public function testAnEmptyAnnotationIsAnEmptySet(): void {
		self::assertTrue(TagSet::fromAnnotation('')->isEmpty());
		self::assertTrue(TagSet::fromAnnotation(null)->isEmpty());
	}

	/**
	 * A dashboard body's `tags` must encode as a JSON ARRAY. A gappy PHP array becomes
	 * `{"0":"dns"}`, which Grafana does not read back as tags.
	 */
	public function testToListIsAListEvenAfterFiltering(): void {
		$list = TagSet::of(['', 'dns', '', 'linux'])->toList();

		self::assertSame([0, 1], array_keys($list));
		self::assertSame('["dns","linux"]', json_encode($list));
	}

	/** A nested array in a hand-edited body is not a tag, and must not become "Array". */
	public function testNonScalarMembersAreIgnored(): void {
		self::assertSame(['dns'], TagSet::of(['dns', ['nested'], new \stdClass()])->toList());
	}
}
