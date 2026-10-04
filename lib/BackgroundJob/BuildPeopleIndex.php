<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\BackgroundJob;

use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\MapIndex;
use OCA\ImmichSearch\Service\PeopleIndex;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Hourly by default (admin setting): who may see which person and which map markers (see PeopleIndex). New faces arrive with Immich's library scans. */
class BuildPeopleIndex extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private PeopleIndex $index,
		private MapIndex $map,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * (int)$config->get('people_interval_hours'));
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if ($this->config->isConfigured()) {
			$this->index->rebuild();
			$this->map->rebuild();
		}
	}
}
