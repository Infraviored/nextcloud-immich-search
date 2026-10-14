<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\SensitiveScanner;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ glimpse:sensitive — check the detector, or scan now instead of in the background. */
class SensitiveCommand extends Command {
	public function __construct(
		private SensitiveScanner $scanner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:sensitive')
			->setDescription('Sensitive pictures: check the nudity detector, show progress, or scan now')
			->addOption('scan', null, InputOption::VALUE_NONE, 'Scan pictures the detector has not seen yet')
			->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'With --scan: stop after this many minutes', '60');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$check = $this->scanner->check();
		$output->writeln('Detector:         ' . ($check ?? 'ok'));
		$output->writeln('Allowed by admin: ' . ($this->scanner->enabled() ? 'yes' : 'no (sensitive_detection, sensitive_detector_url)'));
		if ($input->getOption('scan')) {
			if (!$this->scanner->enabled()) {
				return self::FAILURE;
			}
			$start = microtime(true);
			try {
				$n = $this->scanner->run(60 * max(1, (int)$input->getOption('minutes')));
			} catch (LockedException) {
				$output->writeln('<error>A scan is already running (background job), try again later</error>');
				return self::FAILURE;
			}
			$output->writeln(sprintf('%d pictures scanned in %.0fs', $n, microtime(true) - $start));
		}
		$s = $this->scanner->status();
		$output->writeln(sprintf('Scanned:          %d of %s%s', $s['scanned'], $s['total'] ?? '?', $s['firstPassDone'] ? '' : ' (first pass still running)'));
		return $check === null ? self::SUCCESS : self::FAILURE;
	}
}
