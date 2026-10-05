<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\SmallImagePruner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ glimpse:prune [--full] — take tiny images out of Immich now. */
class PruneCommand extends Command {
	public function __construct(
		private SmallImagePruner $pruner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:prune')
			->setDescription('Take images below the minimum size out of Immich (normally every 15 minutes, new images only)')
			->addOption('full', null, InputOption::VALUE_NONE, 'Check every image, not only new ones');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$r = $this->pruner->run((bool)$input->getOption('full'));
		$output->writeln("{$r['checked']} checked, {$r['pruned']} taken out, {$r['patterns']} exclusion patterns");
		return self::SUCCESS;
	}
}
