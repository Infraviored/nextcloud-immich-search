<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Command;

use OCA\ImmichSearch\Service\MapIndex;
use OCA\ImmichSearch\Service\PeopleIndex;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ immich_search:people-index — rebuild now instead of waiting for the hourly job. */
class PeopleIndexCommand extends Command {
	public function __construct(
		private PeopleIndex $index,
		private MapIndex $map,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('immich_search:people-index')
			->setDescription('Rebuild which people and map markers each user may see (normally hourly in the background)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		[$people, $secs] = $this->index->rebuild(true);
		$output->writeln("$people people checked in {$secs}s");
		$output->writeln($this->map->rebuild(true) . ' geotagged pictures mapped');
		return self::SUCCESS;
	}
}
