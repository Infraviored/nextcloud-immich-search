<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\SensitiveScanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Every 5 minutes, for up to 2 minutes: pictures the nudity detector has not seen yet (SensitiveScanner). */
class ScanSensitive extends TimedJob {
	private const BUDGET = 120;

	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private SensitiveScanner $scanner,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (!$this->config->isConfigured() || !$this->scanner->enabled()) {
			return;
		}
		try {
			$this->scanner->run(self::BUDGET);
		} catch (LockedException) {
			// A run from occ is going on
		} catch (\Throwable $e) {
			// Immich or the detector down: the next run goes on from here
			$this->logger->warning('Sensitive scan: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}
}
