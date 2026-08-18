<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a writeback runs INLINE in the request or is QUEUED for the
 * background worker — one decision, in one place, derived rather than configured.
 *
 * ## THIS USED TO BE AN ADMIN RADIO, AND IT GOVERNED ONE OF FOUR THINGS
 *
 * "Sync Settings" offered *push in the background* vs *push immediately during the
 * save*, which read as an instance-wide mode. It was nothing of the sort. The app
 * hands work to the worker in four places, and `timing` was consulted in exactly
 * one of them ({@see \OCA\GrafanaSync\Listener\NodeWrittenListener}):
 *
 *   - a copy's rename ({@see \OCA\GrafanaSync\Service\CopyService})   — always queued
 *   - a name reconcile ({@see \OCA\GrafanaSync\Listener\NameSyncListener}) — always queued
 *   - the scheduled pull ({@see \OCA\GrafanaSync\BackgroundJob\ScheduledPullJob}) — always background
 *   - the save push — the only one that asked
 *
 * The two rename jobs are not queued by preference and never could be: they write a
 * file's own bytes during that file's own event, which Nextcloud's lock forbids. So
 * the radio promised an instance-wide behaviour and delivered a single-site one.
 *
 * ## QUEUED IS THE PREFERENCE; INLINE IS THE FALLBACK
 *
 * They are not two equally good modes, which is what a radio listing them side by
 * side implied. Queueing is what we want: a save returns immediately, and a desktop
 * client uploading a folder does not serialise a Grafana round trip into every PUT.
 * Inline is what we do when queueing would not actually work — and both conditions
 * that make it not work are facts about the instance, not preferences:
 *
 *   1. **NO ACTING USER.** {@see \OCA\GrafanaSync\BackgroundJob\PushDashboardJob}
 *      re-opens a Files view to find the node again, so it needs a uid. Without one
 *      the job logs and gives up — the work would simply never happen.
 *
 *   2. **NOBODY DRAINS THE QUEUE.** `backgroundjobs_mode` defaults to `ajax`, which
 *      Nextcloud's own admin manual calls "the least reliable": one job per page
 *      visit, and only when somebody visits. On such an instance a queued push may
 *      not run for hours, or ever. Enqueueing always SUCCEEDS there — `IJobList::add()`
 *      is a row insert and cannot fail for want of infrastructure — which is exactly
 *      why the failure is silent, and why this must be checked rather than assumed.
 *
 * `webcron` is deliberately treated as drained. It is slow (one job per call) but
 * somebody is actually calling it, so work does not vanish. Only `ajax` has the
 * property that nothing may ever run.
 *
 * Ported from the n8n sibling, which removed the same toggle for the same reasons
 * (n8n saga Ch5 — *The toggle that governs two of fifteen things*).
 */
final class WritebackStrategy {
	/**
	 * The one background-jobs mode where queued work may never run at all.
	 * `ajax` executes a single job per page visit — no visitors, no execution.
	 */
	private const MODE_UNDRAINED = 'ajax';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Can this work be handed to the background worker, or must it run now?
	 *
	 * @param string $uid the acting user the job would re-resolve the node through;
	 *                    an empty string means there is nobody for it to act as
	 */
	public function canQueue(string $uid): bool {
		if ($uid === '') {
			$this->logger->debug('grafana_sync writeback: no acting user, so a job could not find the file; running inline', [
				'app' => Application::APP_ID,
			]);
			return false;
		}
		$mode = $this->config->getValueString('core', 'backgroundjobs_mode', self::MODE_UNDRAINED);
		if ($mode === self::MODE_UNDRAINED) {
			$this->logger->debug('grafana_sync writeback: background jobs run on page visits only; running inline', [
				'app' => Application::APP_ID,
				'backgroundjobs_mode' => $mode,
			]);
			return false;
		}
		return true;
	}
}
