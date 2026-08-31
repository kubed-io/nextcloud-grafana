<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Command;

use OCA\GrafanaSync\Service\MappingService;
use OCA\GrafanaSync\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ grafana_sync:sync <pull|push> [--mapping=<id>] [--all]`
 *
 * CLI surface for the manual sync controls (the admin's "Sync from Grafana" /
 * "Sync to Grafana" buttons) — and the way the integration suite drives them. One
 * command, a direction argument, mirroring the n8n master's `n8n_sync:sync` so the
 * two apps stay in parity (and reduce cleanly into a shared base). No logic of its
 * own: it resolves an optional mapping and runs {@see SyncService::dispatch} inline,
 * so the exit code and the printed JSON reflect the actual run.
 *
 *   pull : Grafana → Nextcloud — reconcile each mapping's folder against its Grafana
 *          folder (update in place by uid, prune the departed).
 *   push : Nextcloud → Grafana — send the mapping's sync files up as upserts.
 *
 * `--mapping` targets one mapping by id; `--all` (or omitting `--mapping`) runs every
 * mapping. Exit 0 when the run reports `ok`, 1 otherwise.
 */
final class Reconcile extends Command {
	public function __construct(
		private SyncService $sync,
		private MappingService $mappings,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:sync')
			->setDescription('Manually sync a mapping (or all) between Grafana and Nextcloud.')
			->addArgument('direction', InputArgument::REQUIRED, 'Sync direction: "pull" (Grafana → NC) or "push" (NC → Grafana).')
			->addOption('mapping', 'm', InputOption::VALUE_REQUIRED, 'Target one mapping by id.')
			->addOption('all', null, InputOption::VALUE_NONE, 'Sync every mapping (the default when --mapping is omitted).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$direction = (string)$input->getArgument('direction');
		if (!SyncService::isDirection($direction)) {
			$output->writeln('<error>direction must be "pull" or "push"</error>');
			return 1;
		}

		$selector = $input->getOption('mapping');
		$mappingId = null;
		if (is_string($selector) && $selector !== '') {
			if ($this->mappings->getById($selector) === null) {
				$output->writeln('<error>no mapping found for id "' . $selector . '"</error>');
				return 1;
			}
			$mappingId = $selector;
		}

		try {
			$result = $this->sync->dispatch($direction, $mappingId);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
	}
}
