<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Command;

use OCA\GrafanaSync\Service\Mapping;
use OCA\GrafanaSync\Service\MappingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ grafana_sync:add-mapping '<json>'`
 *
 * CLI binding for adding a folder mapping — the same `Mapping::fromArray()` +
 * `MappingService::add()` the admin Settings panel's create endpoint runs, just
 * over the CLI for occ/helm/k8s automation. No new logic: validation and
 * persistence live in the service; this only parses the argument and maps an
 * invalid mapping to a non-zero exit.
 *
 * The JSON is the mapping shape, e.g.:
 *   {"grafana_folder_uid":"af397c9y8enswf","grafana_folder_title":"observe",
 *    "nc_folder":"observe","mode":"sync","format":"json"}
 */
final class AddMapping extends Command {
	public function __construct(
		private MappingService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:add-mapping')
			->setDescription('Add a folder mapping (same as the admin Settings panel, via CLI).')
			->addArgument('json', InputArgument::REQUIRED, 'The mapping as a JSON object.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$data = json_decode((string)$input->getArgument('json'), true);
		if (!is_array($data)) {
			$output->writeln('<error>argument is not a valid JSON object</error>');
			return 1;
		}
		// Ids are server-assigned — strip any client-supplied one, same as the REST
		// create endpoint, so a mapping can't get a non-route-safe id (e.g. slashes)
		// that the admin panel then can't update or delete.
		unset($data['id']);
		try {
			$saved = $this->service->add(Mapping::fromArray($data));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('<info>Added mapping ' . $saved->id . ' (' . $saved->grafanaFolderUid . ' → ' . $saved->ncFolder . ').</info>');
		return 0;
	}
}
