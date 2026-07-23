<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Command;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ grafana_sync:set-token [<token>]`
 *
 * Store the Grafana service-account token the way the app reads it —
 * **`ICrypto`-encrypted** under the `grafana_token` AppConfig entry, exactly as
 * the admin UI's `sensitive` field does on save. {@see \OCA\GrafanaSync\Service\GrafanaClient}
 * calls `ICrypto::decrypt()` on this value, so a plain `occ config:app:set …
 * grafana_token` (even with `--sensitive`, which only hides it from reports)
 * stores plaintext and fails to decrypt — this command is the correct headless
 * path.
 *
 * The headless equivalent of pasting the token into Settings: useful for occ/helm
 * config injection and for the integration tests.
 *
 * Pass the token as an argument, or pipe it on stdin to keep it out of the
 * process list / shell history:
 *   echo "$RAW_TOKEN" | occ grafana_sync:set-token
 */
final class SetToken extends Command {
	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('grafana_sync:set-token')
			->setDescription('Store the Grafana service-account token (encrypted), as the admin Settings panel would.')
			->addArgument('token', InputArgument::OPTIONAL, 'The raw Grafana service-account token. If omitted, read from stdin.');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$token = (string)($input->getArgument('token') ?? '');
		if ($token === '') {
			$stdin = file_get_contents('php://stdin');
			$token = $stdin === false ? '' : trim($stdin);
		}
		if ($token === '') {
			$output->writeln('<error>No token provided (pass as an argument or pipe on stdin).</error>');
			return 1;
		}

		$this->config->setValueString(Application::APP_ID, 'grafana_token', $this->crypto->encrypt($token), sensitive: true);
		$output->writeln('<info>Grafana service-account token stored (encrypted).</info>');
		return 0;
	}
}
