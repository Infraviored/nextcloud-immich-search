<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\SensitiveContent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/** One user's sensitive content now: after turning it on, or asked for in the settings. */
class RebuildSensitive extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private SensitiveContent $sensitive,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$uid = (string)($argument['uid'] ?? '');
		if ($uid === '' || !$this->sensitive->isEnabled($uid)) {
			return;
		}
		try {
			$this->sensitive->rebuild($uid);
		} catch (\Throwable $e) {
			// Stored for the settings page by rebuild()
			$this->logger->warning('Sensitive content for ' . $uid . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}
}
