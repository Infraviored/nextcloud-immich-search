<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ glimpse:configure --url http://immich:2283 --key-stdin < api-key
 * occ glimpse:configure --db-host immich_postgres --db-user nextcloud_moves --db-password-stdin < password
 * Secrets are read from stdin so they never show up in shell history or process lists.
 */
class Configure extends Command {
	public function __construct(
		private Config $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:configure')
			->setDescription('Set the Immich connection used by photo search')
			->addOption('url', null, InputOption::VALUE_REQUIRED, 'Immich base URL, e.g. http://immich:2283')
			->addOption('key-stdin', null, InputOption::VALUE_NONE, 'Read the API key from stdin')
			->addOption('path-pattern', null, InputOption::VALUE_REQUIRED,
				'Where Immich sees a user\'s files, default ' . Config::DEFAULT_PATH_PATTERN)
			->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Immich database host, to carry moves over to Immich')
			->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'Immich database port', '5432')
			->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Immich database name', 'immich')
			->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Immich database user')
			->addOption('db-password-stdin', null, InputOption::VALUE_NONE, 'Read the database password from stdin');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			if (($url = $input->getOption('url')) !== null) {
				$this->config->setUrl($url);
				$output->writeln('URL set');
			}
			if (($pattern = $input->getOption('path-pattern')) !== null) {
				$this->config->setPathPattern($pattern);
				$output->writeln('Path pattern set');
			}
			if (($host = $input->getOption('db-host')) !== null) {
				$this->config->setDatabase($host, (int)$input->getOption('db-port'), (string)$input->getOption('db-name'), (string)$input->getOption('db-user'));
				$output->writeln('Database set');
			}
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>Invalid ' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}
		if ($input->getOption('db-password-stdin')) {
			$password = trim((string)stream_get_contents(STDIN));
			if ($password === '') {
				$output->writeln('<error>No password on stdin</error>');
				return self::FAILURE;
			}
			$this->config->setDatabasePassword($password);
			$output->writeln('Database password stored');
		} elseif ($input->getOption('key-stdin')) {
			$key = trim((string)stream_get_contents(STDIN));
			if ($key === '') {
				$output->writeln('<error>No key on stdin</error>');
				return self::FAILURE;
			}
			$this->config->setApiKey($key);
			$output->writeln('API key stored');
		}
		$output->writeln(sprintf('%s, path pattern %s', $this->config->isConfigured() ? 'Configured' : 'Not configured yet', $this->config->getPathPattern()));
		$output->writeln($this->config->getDatabase() !== null ? 'Moves are carried over to Immich' : 'Moves are not carried over to Immich (no database set)');
		return self::SUCCESS;
	}
}
