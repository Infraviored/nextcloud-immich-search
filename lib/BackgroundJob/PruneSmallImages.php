<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\BackgroundJob;

use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\SmallImagePruner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Every 15 minutes: take newly imported tiny images out of Immich (see SmallImagePruner). */
class PruneSmallImages extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private SmallImagePruner $pruner,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if ($this->config->isConfigured()) {
			$this->pruner->run();
		}
	}
}
