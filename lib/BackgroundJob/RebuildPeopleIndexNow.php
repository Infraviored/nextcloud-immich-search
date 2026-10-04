<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\ImmichSearch\BackgroundJob;

use OCA\ImmichSearch\Service\MapIndex;
use OCA\ImmichSearch\Service\PeopleIndex;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** One-off rebuild requested from the admin settings. */
class RebuildPeopleIndexNow extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PeopleIndex $index,
		private MapIndex $map,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$this->index->rebuild(true);
		$this->map->rebuild(true);
	}
}
