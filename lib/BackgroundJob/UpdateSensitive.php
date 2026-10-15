<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\SensitiveContent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly: sensitive content for each user who turned it on, when Immich has new pictures,
 * another model or the user marked pictures since the last run (SensitiveContent).
 */
class UpdateSensitive extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private SensitiveContent $sensitive,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (!$this->config->isConfigured() || !$this->sensitive->isAvailable()) {
			return;
		}
		foreach ($this->sensitive->users() as $uid) {
			try {
				if ($this->sensitive->needsRebuild($uid)) {
					$this->sensitive->rebuild($uid);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Sensitive content for ' . $uid . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}
}
