<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\Service\TileProxy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Daily: keep the map tile cache within its size and age limits (see TileProxy). */
class PruneTileCache extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TileProxy $tiles,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->tiles->prune();
	}
}
