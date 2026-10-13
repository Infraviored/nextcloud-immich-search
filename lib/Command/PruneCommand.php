<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\SmallImagePruner;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ glimpse:prune [--full] — take tiny images out of Immich now.
 * occ glimpse:prune --reset [--full] — forget every entry (e.g. when the exclusion patterns no
 * longer fit Immich's limit), and with --full build them up again right away.
 */
class PruneCommand extends Command {
	public function __construct(
		private SmallImagePruner $pruner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:prune')
			->setDescription('Take images below the minimum size out of Immich (normally every 15 minutes, new images only)')
			->addOption('full', null, InputOption::VALUE_NONE, 'Check every image, not only new ones')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Forget every taken-out image and write the exclusion patterns without them first');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			if ($input->getOption('reset')) {
				$this->pruner->reset();
				$output->writeln('Entries cleared, exclusion patterns written without them');
				if (!$input->getOption('full')) {
					return self::SUCCESS;
				}
			}
			$r = $this->pruner->run((bool)$input->getOption('full'));
		} catch (LockedException) {
			$output->writeln('<error>Already running (background job or admin page), try again later</error>');
			return self::FAILURE;
		}
		$output->writeln("{$r['checked']} checked, {$r['pruned']} taken out, {$r['patterns']} entries");
		return self::SUCCESS;
	}
}
