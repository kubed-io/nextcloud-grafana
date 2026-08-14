<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Reading and writing a node's Nextcloud tags, as a {@see TagSet}.
 *
 * Nextcloud models tags in two halves — a CATALOG of tag objects with ids
 * ({@see ISystemTagManager}) and an ASSIGNMENT table from object id to tag id
 * ({@see ISystemTagObjectMapper}) — and every caller in this app wants neither. It
 * wants "what is this thing tagged" and "make it tagged that". This class is the
 * translation, and it owns the two decisions that go with it.
 *
 * **TAGS ARE CREATED ON DEMAND, user-visible and user-assignable.** A tag arriving
 * from Grafana usually does not exist in Nextcloud yet, and the alternative to
 * creating it is dropping it — a mirror that silently imports some tags and not
 * others depending on what happened to be there already. They are created
 * `userVisible` and `userAssignable` so they behave exactly like tags a person made;
 * this app is not entitled to a private tag namespace nobody can manage.
 *
 * **THE OBJECT TYPE IS `files` FOR BOTH FILES AND FOLDERS.** Nextcloud has one
 * object type for everything in the file tree, so a folder's tags and a dashboard
 * file's tags come from the same table with the same calls. That is why folder tag
 * sync and dashboard tag sync share this class rather than each growing their own.
 */
final class NextcloudTags {
	private const OBJECT_TYPE = 'files';

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $objectMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What this node is tagged. Answers an empty set rather than throwing when the
	 * node has no tags, which is the overwhelmingly common case.
	 */
	public function of(int $fileId): TagSet {
		$names = [];
		foreach ($this->currentIds($fileId) as $id) {
			try {
				foreach ($this->tagManager->getTagsByIds([$id]) as $tag) {
					$names[] = $tag->getName();
				}
			} catch (\Throwable) {
				// A tag id in the assignment table with no catalog row. Nothing to
				// name it, and one orphan must not blank out the others.
				continue;
			}
		}
		return TagSet::of($names);
	}

	/**
	 * Make this node carry exactly these tags — the set replaces whatever was there.
	 *
	 * Returns false when nothing needed doing, so callers can skip the work that
	 * follows a real change. **This check is load-bearing, not an optimisation:**
	 * every write here raises a tag event, and the listener that reacts to it will
	 * come straight back around. Writing an identical set is how a sync loop starts.
	 *
	 * ## WHY THIS IS A DIFF RATHER THAN ONE CALL
	 *
	 * There is no "set the tags on this object" in the public API. `ISystemTagObjectMapper`
	 * offers `assignTags` (additive), `unassignTags` (subtractive) and
	 * `setObjectIdsForTag` — which replaces the OBJECTS on one TAG, the opposite
	 * direction, and using it would rip a tag off every other file that carried it.
	 * So the replacement is computed here: add what is missing, remove what is extra,
	 * and touch nothing that was already right.
	 *
	 * A tag that cannot be resolved or created is logged and skipped rather than
	 * aborting the rest — importing four of five tags beats importing none.
	 */
	public function set(int $fileId, TagSet $wanted): bool {
		$have = $this->currentIds($fileId);

		$want = [];
		foreach ($wanted->toList() as $name) {
			$id = $this->resolveOrCreate($name);
			if ($id !== null && !in_array($id, $want, true)) {
				$want[] = $id;
			}
		}

		$toAdd = array_values(array_diff($want, $have));
		$toRemove = array_values(array_diff($have, $want));
		if ($toAdd === [] && $toRemove === []) {
			return false;
		}

		try {
			if ($toAdd !== []) {
				$this->objectMapper->assignTags((string)$fileId, self::OBJECT_TYPE, $toAdd);
			}
			if ($toRemove !== []) {
				$this->objectMapper->unassignTags((string)$fileId, self::OBJECT_TYPE, $toRemove);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not set a node\'s tags', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return false;
		}
		return true;
	}

	/**
	 * The tag ids currently assigned to this node, as strings.
	 *
	 * Nextcloud hands these back as INTS keyed by an int object id, while every API
	 * that consumes them wants strings — a mismatch that reads as "no tags" if you
	 * compare the two strictly, which is exactly how a first attempt at this class
	 * concluded that no folder anywhere could be tagged.
	 *
	 * @return list<string>
	 */
	private function currentIds(int $fileId): array {
		try {
			$byObject = $this->objectMapper->getTagIdsForObjects([(string)$fileId], self::OBJECT_TYPE);
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not read a node\'s tags', [
				'app' => Application::APP_ID,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return [];
		}

		$ids = [];
		foreach ($byObject as $tagIds) {
			foreach ((array)$tagIds as $id) {
				$ids[] = (string)$id;
			}
		}
		return array_values(array_unique($ids));
	}

	/** The catalog id for this tag name, creating the tag if Nextcloud has never seen it. */
	private function resolveOrCreate(string $name): ?string {
		try {
			return (string)$this->tagManager->getTag($name, true, true)->getId();
		} catch (TagNotFoundException) {
			// Not in the catalog yet — the common case for a tag arriving from Grafana.
		} catch (\Throwable $e) {
			$this->logger->warning('grafana_sync: could not look up a tag', [
				'app' => Application::APP_ID,
				'tag' => $name,
				'exception' => $e,
			]);
			return null;
		}

		try {
			return (string)$this->tagManager->createTag($name, true, true)->getId();
		} catch (\Throwable $e) {
			// A concurrent sync may have created it between the lookup and here, which
			// is a race this app can lose gracefully — ask once more before giving up.
			try {
				return (string)$this->tagManager->getTag($name, true, true)->getId();
			} catch (\Throwable) {
				$this->logger->warning('grafana_sync: could not create a tag, so it was not imported', [
					'app' => Application::APP_ID,
					'tag' => $name,
					'exception' => $e,
				]);
				return null;
			}
		}
	}
}
