<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * The authoring guard: a link mapping is filled from Grafana, so nothing may be written
 * into one from Nextcloud.
 *
 * The third of the family — {@see MoveGuardListener} refuses a move, {@see CopyGuardListener}
 * a copy, and this one a write. Same shape, same reason: refuse BEFORE the gesture rather
 * than tidy up after it.
 *
 * ## WHY THIS EXISTS ALONGSIDE THE SABRE PLUGIN
 *
 * {@see \OCA\GrafanaSync\DAV\LinkWriteGuardPlugin} refuses the same thing over WebDAV and
 * answers **403 with a reason**, which is what a person needs. It cannot be the whole
 * rule, because it only sees WebDAV: an `occ` command, another app, or a script using the
 * Files API never touches Sabre.
 *
 * AND THE PLUGIN'S CREATE HALF IS NOT CARRYING ITS WEIGHT. `beforeCreateFile` is the hook
 * that should catch a brand-new dashboard file landing in a link folder, and a live PUT
 * through the Files "New" menu answered **201** with the guard in place — measured in CI,
 * twice. Whatever the reason (the connector's create path, or the hook not being emitted
 * for that route), the observable fact is that the refusal did not happen, and a rule that
 * only holds on some routes is not a rule. This listener is the seam that does not depend
 * on which door the write came through.
 *
 * ## THE SYNC GUARD IS LOAD-BEARING HERE, NOT DEFENSIVE
 *
 * The PULL writes mirrors into link folders — that is the entire point of a link mapping —
 * and those writes fire this event too. Refusing them would not merely be over-strict, it
 * would break link mappings completely: no mirror could ever be written. The pull runs
 * inside {@see SyncGuard} ({@see \OCA\GrafanaSync\Service\SyncService} enters it around
 * every `putContent`/`newFile`), so the guard check below is what separates "Grafana filled
 * this folder" from "a person tried to".
 *
 * ## EVERY WRITE, NOT ONLY THE FIRST
 *
 * The event does not distinguish creating a file from editing one, and it does not need
 * to: `edit.feature` says a link's body is Grafana's too, so both answers are the same
 * refusal. Non-dashboard files are waved through — a link mapping's one concession is
 * that other file types may live alongside the mirrored dashboards.
 *
 * @implements IEventListener<BeforeNodeWrittenEvent>
 */
final class CreateGuardListener implements IEventListener {
	public function __construct(
		private MappingService $mappings,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeWrittenEvent) {
			return;
		}
		// The pull's own writes — see the class docblock. This is not defence in depth;
		// without it a link mapping could never be filled.
		if ($this->guard->active()) {
			return;
		}
		$node = $event->getNode();
		if (!FilenameCodec::isDashboardName($node->getName())) {
			return; // a spreadsheet in a link folder is entirely welcome
		}

		try {
			$mapping = $this->mappings->resolveForPath($node->getPath());
		} catch (\Throwable $e) {
			// CANNOT CLASSIFY → NEVER BLOCK. A guard that refuses writes whenever the
			// mapping lookup is unhappy would take the whole instance's dashboard files
			// down with it.
			$this->logger->debug('grafana_sync: could not classify a written dashboard file; allowing', [
				'app' => Application::APP_ID,
				'path' => $node->getPath(),
				'exception' => $e,
			]);
			return;
		}
		if ($mapping === null || $mapping->mode !== Mapping::MODE_LINK) {
			return;
		}

		$this->logger->warning('grafana_sync: refused a write to a dashboard file in a link-mapped folder', [
			'app' => Application::APP_ID,
			'path' => $node->getPath(),
			'mapping' => $mapping->id,
		]);

		throw new AbortedEventException(
			'“' . $node->getName() . '” cannot be written here: “' . $mapping->ncFolder . '” mirrors a Grafana '
			. 'folder in link mode, so its dashboards are Grafana\'s to create. Make the dashboard in Grafana and '
			. 'it will appear here, or switch the folder mapping to sync mode to author dashboards from Nextcloud.',
		);
	}
}
