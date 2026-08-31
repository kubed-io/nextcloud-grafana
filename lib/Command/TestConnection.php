<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Command;

use OCA\GrafanaSync\Service\GrafanaClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ grafana_sync:test-connection`
 *
 * Headless equivalent of the admin "Test connection" button — runs the exact same
 * {@see GrafanaClient::ping()} (an authenticated GET /api/folders with the stored,
 * decrypted token) so an operator can verify the base URL + token without a
 * browser. Same code path the Settings panel exercises.
 *
 * Exit 0 on a reachable, authenticated instance; 1 otherwise (with the same
 * friendly message the button shows).
 */
final class TestConnection extends Command {
	public function __construct(
		private GrafanaClient $client,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:test-connection')
			->setDescription('Verify the configured Grafana base URL + token (same as the admin Test connection button).');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$result = $this->client->ping();
		} catch (\Throwable $e) {
			// Same friendly formatter the admin button uses — so an unset token and a
			// rejected token report differently, and the CLI matches the UI.
			$output->writeln('<error>' . GrafanaClient::describeConnectionError($e) . '</error>');
			return 1;
		}
		$output->writeln('<info>' . $result['message'] . '</info>');
		return 0;
	}
}
