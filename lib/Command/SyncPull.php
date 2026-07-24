<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Command;

use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ grafana_sync:pull [--mapping=<id>]`
 *
 * Headless equivalent of the admin "Sync from Grafana" button (saga Ch2 Course 2)
 * — and the way the integration suite drives the pull. No logic of its own: it runs
 * {@see SyncService::pullAll} (every mapping) or {@see SyncService::pullOne} (one),
 * inline, so the exit code and printed JSON reflect the actual run.
 *
 * Grafana → Nextcloud: each mapping's folder is provisioned and reconciled against
 * the dashboards in its Grafana folder — files matched by dashboard uid, updated in
 * place, and stale mirrors pruned. Grafana itself is never written by a pull.
 *
 * `--mapping` targets one mapping by id; omitting it runs every mapping (the bulk
 * button's behaviour). Exit 0 when the run reports `ok`, 1 otherwise.
 */
final class SyncPull extends Command {
	public function __construct(
		private SyncService $sync,
		private MappingService $mappings,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:pull')
			->setDescription('Pull dashboards from Grafana into the mapped Nextcloud folders (same as the admin “Sync from Grafana” button).')
			->addOption('mapping', 'm', InputOption::VALUE_REQUIRED, 'Target one mapping by id (default: every mapping).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$selector = $input->getOption('mapping');

		try {
			if (is_string($selector) && $selector !== '') {
				$mapping = $this->mappings->getById($selector);
				if ($mapping === null) {
					$output->writeln('<error>no mapping found for id "' . $selector . '"</error>');
					return 1;
				}
				$result = $this->sync->pullOne($mapping);
				// pullOne returns raw counters; normalise to the pullAll shape for output.
				$result['status'] = ($result['failed'] ?? 0) === 0 ? 'ok' : 'error';
				$result['message'] = null;
			} else {
				$result = $this->sync->pullAll();
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
	}
}
