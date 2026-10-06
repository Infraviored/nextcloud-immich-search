<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\MapIndex;
use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\PeopleIndexLock;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ glimpse:people-index — rebuild now instead of waiting for the hourly job. */
class PeopleIndexCommand extends Command {
	public function __construct(
		private PeopleIndex $index,
		private MapIndex $map,
		private PeopleIndexLock $lock,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:people-index')
			->setDescription('Rebuild which people and map markers each user may see (normally hourly in the background)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			return $this->lock->run(function () use ($output) {
				[$people, $secs] = $this->index->rebuild(true);
				$output->writeln("$people people checked in {$secs}s");
				$output->writeln($this->map->rebuild(true) . ' geotagged pictures mapped');
				return self::SUCCESS;
			});
		} catch (LockedException) {
			$output->writeln('<error>A rebuild is already running (background job), try again later</error>');
			return self::FAILURE;
		}
	}
}
