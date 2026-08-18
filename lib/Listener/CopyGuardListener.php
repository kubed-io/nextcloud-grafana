<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\DashboardMetadata;
use OCA\GrafanaSync\Service\FilenameCodec;
use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCopiedEvent;
use Psr\Log\LoggerInterface;

/**
 * The copy guard: a link is not copyable, and a link mapping is not a destination.
 *
 * The sibling of {@see MoveGuardListener}, and the same shape — refuse BEFORE the
 * gesture happens rather than tidy up after it. Ported from the n8n master, where
 * both halves are stated in `features/AGENTS.md#a-link-is-not-copyable-and-a-link-mapping-is-not-a-destination`.
 *
 * ## WHY THIS EXISTS ALONGSIDE THE SABRE PLUGIN
 *
 * {@see \OCA\GrafanaSync\DAV\LinkWriteGuardPlugin} refuses the same two things over
 * WebDAV and answers **403 with a reason**, which is what a person needs. It cannot be
 * the whole rule, because it only sees WebDAV: an `occ` command, another app, or a
 * script using the Files API never touches Sabre. This listener is where the rule is
 * actually universal.
 *
 * It also sees something the plugin's service-level counterpart cannot.
 * {@see \OCA\GrafanaSync\Service\CopyService} runs on `NodeCopiedEvent` — after the
 * copy — and by then the copy's inherited metadata has been stripped, so nothing left
 * on disk says the source was a link. `BeforeNodeCopiedEvent` carries the SOURCE node,
 * which is the only place that question can still be answered.
 *
 * ## ABORTING IS ENOUGH HERE, AND IT IS NOT ENOUGH OVER DAV
 *
 * Throwing {@see AbortedEventException} genuinely stops the copy — core's
 * `HookConnector::copy()` catches it and clears the hook's `run` flag, and the target
 * never appears (measured in a pod on the n8n sibling; the mechanism is core's, not the
 * app's). But `View::copy()` swallows it, so Sabre answers **201** and a WebDAV client
 * is told the copy succeeded when no file exists. That is why the plugin exists and why
 * it runs first: over DAV the user gets a 403 and never reaches this listener;
 * everywhere else this listener is the one that holds.
 *
 * ## A LINK IS READ-ONLY, WHEREVER IT IS GOING
 *
 * There is no destination that makes copying a link meaningful — not another mapping,
 * not an unmapped folder, not the folder it is already in. The file is a pointer to a
 * dashboard that lives in Grafana; a second copy of the pointer is a second file
 * claiming one dashboard, and it duplicates nothing. Editing, deleting and moving a
 * link out are all already refused; this closes the last way to make one.
 *
 * @implements IEventListener<BeforeNodeCopiedEvent>
 */
final class CopyGuardListener implements IEventListener {
	public function __construct(
		private MappingService $mappings,
		private DashboardMetadata $metadata,
		private SyncGuard $guard,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeCopiedEvent) {
			return;
		}
		// The app's own writes never reach here, but a pull that re-shapes a mirror
		// should never be able to trip a user-facing guard either.
		if ($this->guard->active()) {
			return;
		}

		$source = $event->getSource();
		$target = $event->getTarget();
		if (!FilenameCodec::isDashboardName($source->getName())) {
			return; // not one of ours; not ours to police
		}

		$this->refuseIfSourceIsALink($source->getName(), $source->getId());
		$this->refuseIfTargetIsInALinkMapping($target->getPath());
	}

	private function refuseIfSourceIsALink(string $name, ?int $fileId): void {
		if ($fileId === null) {
			return; // no id, no metadata, no way to tell — never block on doubt
		}
		try {
			$managed = $this->metadata->read($fileId);
		} catch (\Throwable) {
			return;
		}
		if (!$managed?->isLink()) {
			return;
		}

		$this->logger->warning('grafana_sync copy: refused — a link is a pointer, so there is nothing to copy', [
			'app' => Application::APP_ID,
			'fileId' => $fileId,
			'file' => $name,
		]);
		throw new AbortedEventException(
			'“' . $name . '” is a linked Grafana dashboard and cannot be copied. '
			. 'Duplicate the dashboard in Grafana instead.',
		);
	}

	private function refuseIfTargetIsInALinkMapping(string $path): void {
		try {
			$mapping = $this->mappings->resolveForPath($path);
		} catch (\Throwable) {
			return;
		}
		if ($mapping === null || $mapping->mode !== Mapping::MODE_LINK) {
			return;
		}

		$this->logger->warning('grafana_sync copy: refused — a link mapping is filled from Grafana', [
			'app' => Application::APP_ID,
			'path' => $path,
			'mapping' => $mapping->id,
		]);
		throw new AbortedEventException(
			'“' . $mapping->ncFolder . '” mirrors a Grafana folder in link mode, so files cannot be added to it. '
			. 'Create the dashboard in Grafana instead.',
		);
	}
}
