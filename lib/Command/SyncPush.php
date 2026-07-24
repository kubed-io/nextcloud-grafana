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
 * `occ grafana_sync:push [--mapping=<id>]`
 *
 * Headless equivalent of the admin "Sync to Grafana" button (saga Ch2 Course 3) — and
 * the way the integration suite drives the writeback. No logic of its own: it runs
 * {@see SyncService::pushAll} (every mapping) or {@see SyncService::pushOne} (one),
 * inline, so the exit code and printed JSON reflect the actual run.
 *
 * Nextcloud → Grafana: each mapping's `sync` files are sent up as an upsert on their
 * uid — same dashboard, not a new one. `link` mappings are a no-op.
 *
 * `--mapping` targets one mapping by id; omitting it pushes every mapping. Exit 0 when
 * the run reports `ok`, 1 otherwise.
 */
final class SyncPush extends Command {
	public function __construct(
		private SyncService $sync,
		private MappingService $mappings,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:push')
			->setDescription('Push Nextcloud dashboard edits up to Grafana (same as the admin “Sync to Grafana” button).')
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
				$result = $this->sync->pushOne($mapping);
				// pushOne returns raw counters; normalise to the pushAll shape for output.
				$result['status'] = ($result['failed'] ?? 0) === 0 ? 'ok' : 'error';
			} else {
				$result = $this->sync->pushAll();
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
	}
}
