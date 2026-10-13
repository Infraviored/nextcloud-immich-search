<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\VideoScenes;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ glimpse:video-scenes [--minutes=N] [--limit=N] — take stills from videos now (the
 * background job does 2 minutes every 5). --status shows how far it is, --orphans removes
 * stills of deleted videos, --purge removes all stills.
 */
class VideoScenesCommand extends Command {
	public function __construct(
		private VideoScenes $scenes,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:video-scenes')
			->setDescription('Search inside videos: take stills from videos and hand them to Immich')
			->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Stop after this many minutes', '60')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many videos')
			->addOption('status', null, InputOption::VALUE_NONE, 'Only show the progress')
			->addOption('orphans', null, InputOption::VALUE_NONE, 'Remove stills of videos that are gone')
			->addOption('purge', null, InputOption::VALUE_NONE, 'Remove every still Glimpse made');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			if ($input->getOption('purge') || $input->getOption('orphans')) {
				$n = $this->scenes->purge(!$input->getOption('purge'));
				$output->writeln("$n stills removed");
				return self::SUCCESS;
			}
			if (!$input->getOption('status')) {
				if (!$this->scenes->enabled()) {
					$output->writeln('<error>Search inside videos is off (Glimpse settings, "What gets indexed")</error>');
					return self::FAILURE;
				}
				if (VideoScenes::ffmpeg() === null) {
					$output->writeln('<error>ffmpeg is not installed on the Nextcloud server</error>');
					return self::FAILURE;
				}
				$limit = $input->getOption('limit');
				$r = $this->scenes->run(max(1, (int)$input->getOption('minutes')) * 60, $limit !== null ? (int)$limit : null);
				$output->writeln("{$r['videos']} videos, {$r['stills']} stills, {$r['failed']} failed");
			}
		} catch (LockedException) {
			$output->writeln('<error>Already running (background job), try again later</error>');
			return self::FAILURE;
		}
		$s = $this->scenes->status();
		$output->writeln("{$s['done']} of {$s['videos']} videos done, {$s['stills']} stills, {$s['failed']} failed");
		return self::SUCCESS;
	}
}
