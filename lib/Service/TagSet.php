<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * A set of tags, and the one place that decides what "the same tags" means.
 *
 * Tags arrive in three shapes — a JSON array in a dashboard body, a comma-joined
 * string in a Grafana folder annotation, and a list of Nextcloud systemtag names —
 * and every comparison between two of them is a chance to loop. If "dns, linux"
 * and "linux,dns" compare unequal, a sync writes, the write raises an event, the
 * event compares unequal again, and the two sides trade updates forever.
 *
 * So the rules are here and nowhere else:
 *
 *  - **A SET, not a list.** Order carries no meaning on any of the three surfaces;
 *    Grafana returns whatever order it stored and Nextcloud returns whatever order
 *    the join produced. {@see equals()} is the only correct way to ask.
 *  - **Trimmed, and empties dropped.** `"dns, , linux"` is two tags. A user typing
 *    a trailing comma must not create a tag whose name is the empty string —
 *    Nextcloud would refuse it and the whole assignment would fail.
 *  - **Case is preserved but not collapsed.** `DNS` and `dns` are two different
 *    tags in Grafana, and Nextcloud's systemtags are likewise case-sensitive, so
 *    folding them here would silently merge two tags into one on the way across.
 *  - **De-duplicated**, because a set cannot hold a value twice and Grafana will
 *    happily store `["dns","dns"]` if asked.
 */
final class TagSet {
	/**
	 * The annotation key a Grafana FOLDER carries its tags in.
	 *
	 * An annotation rather than a label, decided by measurement: k8s validation
	 * rejects a label key or value containing anything a real tag is likely to have
	 * (`Q3 Review` → 422, `café` → 422). Annotations take arbitrary text, so a
	 * Nextcloud tag crosses with no escaping and no reverse lookup table. The price
	 * is that Grafana cannot search them — annotations are not selectable — which is
	 * the right trade against a mangled `Q3-Review` colliding with a real one.
	 */
	public const FOLDER_ANNOTATION = 'nextcloud.kubed.io/tags';

	/**
	 * THE RESERVED ROOT MAPPING (`/`) HAS NO FOLDER TO TAG.
	 *
	 * A mapping may name `/` instead of a folder uid, meaning the whole Grafana
	 * instance. That is not an object: there is no folder resource behind it, so
	 * there is nothing to hang an annotation on and nothing to read one back from.
	 * Folder tag sync is therefore a **plain-folder** feature on both sides — a
	 * mapped folder or a subfolder under one — and a root mapping simply declines,
	 * in both directions, rather than inventing a place to put the value.
	 *
	 * The dashboards inside a root mapping are unaffected: their tags are in their
	 * own bodies and travel exactly as they do anywhere else.
	 */
	public const ROOT_FOLDER = '/';

	/** @var list<string> normalised: trimmed, non-empty, unique, in first-seen order */
	private array $tags;

	/** @param iterable<mixed> $tags */
	private function __construct(iterable $tags) {
		$clean = [];
		foreach ($tags as $tag) {
			if (!is_string($tag) && !is_int($tag) && !is_float($tag)) {
				continue; // a nested array or object is not a tag
			}
			$trimmed = trim((string)$tag);
			if ($trimmed !== '' && !in_array($trimmed, $clean, true)) {
				$clean[] = $trimmed;
			}
		}
		$this->tags = $clean;
	}

	/** @param iterable<mixed> $tags */
	public static function of(iterable $tags): self {
		return new self($tags);
	}

	public static function empty(): self {
		return new self([]);
	}

	/**
	 * Parse the comma-joined form a Grafana folder annotation holds.
	 *
	 * Comma is the separator because it is the one character a Nextcloud tag cannot
	 * usefully contain in this app's own UI copy, and because the annotation has no
	 * charset limit to work around — the split is the whole codec.
	 */
	public static function fromAnnotation(?string $joined): self {
		return new self(explode(',', $joined ?? ''));
	}

	/** The form written back into the annotation. */
	public function toAnnotation(): string {
		return implode(', ', $this->tags);
	}

	/**
	 * The form a dashboard body carries. A LIST, always — `json_encode` turns an
	 * array with gaps into an object, and a dashboard whose `tags` is `{"0":"dns"}`
	 * is a dashboard Grafana will not read back the same way.
	 *
	 * @return list<string>
	 */
	public function toList(): array {
		return $this->tags;
	}

	public function isEmpty(): bool {
		return $this->tags === [];
	}

	/**
	 * Set equality — the guard every direction of the sync leans on to decide it has
	 * nothing to do. Order-insensitive by construction.
	 */
	public function equals(self $other): bool {
		if (count($this->tags) !== count($other->tags)) {
			return false;
		}
		$mine = $this->tags;
		$theirs = $other->tags;
		sort($mine);
		sort($theirs);
		return $mine === $theirs;
	}
}
