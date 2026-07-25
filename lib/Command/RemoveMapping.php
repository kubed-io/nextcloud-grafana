<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Command;

use OCA\GrafanaSync\Service\MappingTeardownService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ grafana_sync:remove-mapping <id>`
 *
 * CLI binding over {@see MappingTeardownService::remove()} — removes a mapping by its id (as the
 * admin Settings panel's delete does). Exits non-zero if the id is unknown. Tearing down a
 * mapping trashes its connected files (their delete rides the recycle-bin setting) and leaves
 * standalone files alone; it never touches Grafana beyond those connected dashboards.
 */
final class RemoveMapping extends Command {
	public function __construct(
		private MappingTeardownService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:remove-mapping')
			->setDescription('Remove a folder mapping by id.')
			->addArgument('id', InputArgument::REQUIRED, 'The mapping id (from list-mappings).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('id');
		try {
			$this->service->remove($id);
		} catch (\OutOfBoundsException) {
			$output->writeln('<error>No mapping with id "' . $id . '".</error>');
			return 1;
		}
		$output->writeln('<info>Removed mapping ' . $id . '.</info>');
		return 0;
	}
}
