<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Listener;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\BackgroundJob\RebuildPeopleIndexNow;
use OCA\Glimpse\Service\Config;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use Psr\Log\LoggerInterface;

/**
 * A share was taken back (or the recipient left it): the people and map indexes still
 * list its pictures for the recipient. Search, thumbnails and faces check Nextcloud on
 * every request, the indexes do not, so they are rebuilt at the next cron run instead of
 * waiting for the hourly (or daily) job. The counter changes IndexSignature, so even if
 * the queued run is lost the next regular run does not skip the rebuild.
 *
 * @template-implements IEventListener<Event>
 */
class ShareRemoved implements IEventListener {
	public function __construct(
		private Config $config,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof ShareDeletedEvent || $event instanceof ShareDeletedFromSelfEvent) || !$this->config->isConfigured()) {
			return;
		}
		try {
			$count = (int)($this->config->getState('shares_changed')['count'] ?? 0);
			$this->config->setState('shares_changed', ['count' => $count + 1, 'at' => time()]);
			// Queued once: further removals before the run do not add more jobs
			$this->jobs->add(RebuildPeopleIndexNow::class);
		} catch (\Throwable $e) {
			// Never break unsharing over this; the indexes catch up within a day anyway.
			$this->logger->warning('Could not mark the indexes stale: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
