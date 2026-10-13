<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\VideoScenes;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\LockedException;

/**
 * Every 5 minutes, for up to 2 minutes: stills from videos not done yet (see VideoScenes).
 * Once a day, stills of videos that are gone are taken out again.
 */
class IndexVideoScenes extends TimedJob {
	private const BUDGET = 120;

	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private VideoScenes $scenes,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (!$this->config->isConfigured() || !$this->scenes->enabled() || !$this->scenes->inWindow()) {
			return;
		}
		try {
			$this->scenes->run(self::BUDGET);
			$last = (int)($this->config->getState('video_scenes_cleanup')['at'] ?? 0);
			if (time() - $last > 86400) {
				$this->scenes->purge(true);
				$this->config->setState('video_scenes_cleanup', ['at' => time()]);
			}
		} catch (LockedException) {
			// A run from occ is going on
		} catch (ImmichException) {
			// Immich is down; the next run tries again
		}
	}
}
