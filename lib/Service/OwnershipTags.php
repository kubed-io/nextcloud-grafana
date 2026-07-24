<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Owns the NC system tags this app puts on the dashboard files it manages — one per
 * mode (a coloured pill the user sees in the Files app), the master's
 * {@see \OCA\N8nSync\Service\OwnershipTags} re-cut for Grafana:
 *
 *   grafana:sync      — full dashboard spec; edits push back to Grafana.
 *   grafana:link      — a small pointer / deep link to a dashboard owned in Grafana.
 *   grafana:unmapped  — a sync file moved out of its mapping; the spec is kept and is
 *                       restorable on move-back-in (the move course wires this — the
 *                       pull only ever stamps sync/link).
 *
 * On the Nextcloud side these pills are **authoritative**: the app keeps exactly one
 * on each managed file, matching the file's mode metadata. They are the human-visible
 * companion to the authoritative {@see DashboardMetadata} store — a pill survives a
 * metadata wipe and lets a user recognise a managed file at a glance.
 *
 * NB: the user-set exclude marker (`grafana:ignore`) is a **separate** two-origin
 * concern handled elsewhere; it is deliberately NOT one of these auto-managed pills
 * and is never written or stripped here.
 */
final class OwnershipTags {
	public const TAG_SYNC = 'grafana:sync';
	public const TAG_LINK = 'grafana:link';
	public const TAG_UNMAPPED = 'grafana:unmapped';

	/** All pills this app manages — used to scrub competing assignments on re-tag. */
	public const ALL = [self::TAG_SYNC, self::TAG_LINK, self::TAG_UNMAPPED];

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Pick the pill for a mode. Throws on a mode that has no file pill (an unknown
	 * mode is a programming error).
	 */
	public static function tagFor(string $mode): string {
		return match ($mode) {
			Mapping::MODE_SYNC => self::TAG_SYNC,
			Mapping::MODE_LINK => self::TAG_LINK,
			DashboardMetadata::MODE_UNMAPPED => self::TAG_UNMAPPED,
			default => throw new \InvalidArgumentException('Unknown mode for ownership tag: ' . $mode),
		};
	}

	/**
	 * Stamp the right ownership pill on a file id and strip any of our other pills.
	 * Idempotent — safe to call on every sync run.
	 */
	public function apply(int $fileId, string $mode): void {
		$desiredName = self::tagFor($mode);
		$desiredTag = $this->ensureTag($desiredName);
		$objId = (string)$fileId;

		$this->tagMapper->assignTags($objId, 'files', [$desiredTag->getId()]);

		foreach (self::ALL as $other) {
			if ($other === $desiredName) {
				continue;
			}
			try {
				$otherTag = $this->tagManager->getTag($other, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], 'files', $otherTag->getId())) {
				$this->tagMapper->unassignTags($objId, 'files', [$otherTag->getId()]);
			}
		}
	}

	/**
	 * Strip every ownership pill this app manages from a file. Used when a COPY lands:
	 * the copy must start with no Grafana identity, so it carries none of our pills.
	 * Idempotent.
	 */
	public function clear(int $fileId): void {
		$objId = (string)$fileId;
		foreach (self::ALL as $name) {
			try {
				$tag = $this->tagManager->getTag($name, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], 'files', $tag->getId())) {
				$this->tagMapper->unassignTags($objId, 'files', [$tag->getId()]);
			}
		}
	}

	/** True if the file carries any of our ownership pills (cheap second signal). */
	public function isOwned(int $fileId): bool {
		$objId = (string)$fileId;
		foreach (self::ALL as $name) {
			try {
				$tag = $this->tagManager->getTag($name, true, true);
			} catch (TagNotFoundException) {
				continue;
			}
			if ($this->tagMapper->haveTag([$objId], 'files', $tag->getId())) {
				return true;
			}
		}
		return false;
	}

	/** Look up (or first-time create) the system tag. */
	private function ensureTag(string $name): ISystemTag {
		try {
			return $this->tagManager->createTag($name, true, true);
		} catch (TagAlreadyExistsException) {
			return $this->tagManager->getTag($name, true, true);
		}
	}
}
