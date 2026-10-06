<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\MapIndex;
use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\PeopleIndexLock;
use OCA\Glimpse\Service\PrivateArea;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\Lock\LockedException;

/** Hourly by default (admin setting): who may see which person and which map markers (see PeopleIndex). New faces arrive with Immich's library scans. */
class BuildPeopleIndex extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private PeopleIndex $index,
		private MapIndex $map,
		private PrivateArea $privateArea,
		private IUserManager $users,
		private PeopleIndexLock $lock,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * (int)$config->get('people_interval_hours'));
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (!$this->config->isConfigured()) {
			return;
		}
		try {
			$this->lock->run(function () {
				$this->index->rebuild();
				$this->map->rebuild();
				$this->users->callForSeenUsers(fn ($u) => $this->privateArea->refresh($u->getUID()));
			});
		} catch (LockedException) {
			// A rebuild asked for by the admin or occ is going on; that one is fresh enough.
		}
	}
}
