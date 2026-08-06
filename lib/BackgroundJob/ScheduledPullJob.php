<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\BackgroundJob;

use OCA\GrafanaSync\AppInfo\Application;
use OCA\GrafanaSync\Service\ScheduleInterval;
use OCA\GrafanaSync\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Scheduled Grafana → Nextcloud pull. A TimedJob, because Nextcloud schedules by
 * INTERVAL rather than by cron expression; registered once in
 * {@see Application::boot()} and run by the cron worker.
 *
 * ## THE SETTINGS EXISTED AND NOTHING READ THEM
 *
 * `schedule_enabled` and `schedule_interval` have been in the Sync Settings card
 * since it was written, and until this job there was NO reader for either
 * anywhere in the app. An admin could turn "Grafana → Nextcloud: scheduled sync"
 * on, save it, see it stay on across reloads — and nothing whatsoever would
 * happen, forever. A form that stores a value nobody acts on is worse than an
 * absent feature: the absent one is obvious.
 *
 * (The sibling n8n app has had this job all along, which is how the gap was
 * found: writing one spec for "the ways a sync starts" across both apps made the
 * missing row impossible to keep quiet about.)
 *
 * Two knobs, both from that same card:
 *   - `schedule_enabled`  — master on/off; {@see run()} no-ops when off
 *   - `schedule_interval` — how often, read in the constructor
 *
 * The interval is re-read every time the job is instantiated, so changing it in
 * settings takes effect on the next tick rather than needing a restart.
 */
final class ScheduledPullJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private SyncService $sync,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(ScheduleInterval::seconds($this->safeString('schedule_interval', '1h')));
	}

	/**
	 * PUBLIC, where TimedJob declares it protected. Widening on override is legal
	 * PHP and costs nothing: the cron worker reaches it either way, and the unit
	 * suite can then invoke the job directly instead of subclassing a final class
	 * or prising it open with reflection — which would bind the test to a
	 * visibility the framework is free to change.
	 */
	#[\Override]
	public function run(mixed $argument): void {
		if (!$this->isEnabled()) {
			// Disabled. The interval still gates how often we re-check, so turning
			// it on takes effect within one tick rather than needing a restart.
			return;
		}

		try {
			$result = $this->sync->runInline(SyncService::DIR_PULL, null);
			$this->logger->info('grafana_sync scheduled pull finished', [
				'app' => Application::APP_ID,
				'result' => $result,
			]);
		} catch (\Throwable $e) {
			// NEVER let this escape. A throwing TimedJob is retried by the cron
			// worker and can wedge the whole job queue behind it; a Grafana that is
			// merely unreachable must not stop every other job on the instance.
			$this->logger->error('grafana_sync scheduled pull failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	/**
	 * DEFENSIVE ON PURPOSE, and the reason is in AutoSyncSettings.
	 *
	 * The declarative-settings INTERNAL storage path records a checkbox as a
	 * bool-typed value and a text field as a string-typed one, so the "wrong"
	 * typed getter raises an AppConfigTypeConflict rather than coercing. A value
	 * written by the form, by `occ config:app:set`, or by a test can therefore be
	 * either type. Reading it one way only means the schedule silently never runs
	 * for whichever half wrote it the other way — which is exactly the class of
	 * silent failure this job exists to end.
	 */
	private function isEnabled(): bool {
		try {
			return $this->appConfig->getValueBool(Application::APP_ID, 'schedule_enabled', false);
		} catch (\Throwable) {
			$raw = strtolower($this->safeString('schedule_enabled', ''));

			return in_array($raw, ['1', 'true', 'yes', 'on'], true);
		}
	}


	private function safeString(string $key, string $default): string {
		try {
			return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		} catch (\Throwable) {
			return $default;
		}
	}
}
