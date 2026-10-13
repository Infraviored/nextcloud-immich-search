<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\Service\MapIndex;
use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\PeopleIndexLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\LockedException;

/** One-off rebuild requested from the admin settings. */
class RebuildPeopleIndexNow extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PeopleIndex $index,
		private MapIndex $map,
		private PeopleIndexLock $lock,
		private IJobList $jobs,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		try {
			$this->lock->run(function () {
				$this->index->rebuild(true);
				$this->map->rebuild(true);
			});
		} catch (LockedException) {
			// The hourly rebuild is going on and may skip unchanged work; this one was asked
			// for to force it, so it runs again at the next cron run.
			$this->jobs->add(self::class);
		}
	}
}
