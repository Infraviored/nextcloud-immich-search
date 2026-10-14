<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Dashboard;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\Discover;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\SensitiveContent;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/** Dashboard: pictures taken on this day in earlier years. */
class MemoriesWidget implements IAPIWidgetV2, IIconWidget {
	private \OCP\IL10N $l;

	public function __construct(
		private Discover $discover,
		private PrivateArea $privateArea,
		private SensitiveContent $sensitive,
		private Config $config,
		private IURLGenerator $url,
		private IConfig $serverConfig,
		IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
		$this->l = $l10nFactory->get(Application::APP_ID);
	}

	public function getId(): string {
		return Application::APP_ID . '_memories';
	}

	public function getTitle(): string {
		return $this->l->t('On this day');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-picture';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('glimpse.page.indextimeline');
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->config->isConfigured()) {
			return new WidgetItems([], $this->l->t('Glimpse is not set up yet'));
		}
		$items = [];
		try {
			// The user's today, not the server's: around midnight they differ by a day
			$today = new \DateTimeImmutable('now', $this->timezone($userId));
			foreach ($this->discover->onThisDay($userId, $today->format('Y-m-d')) as $memory) {
				// Never private pictures on the dashboard; sensitive ones not when the user hides them
				foreach ($this->sensitive->apply($userId, $this->privateArea->filter($userId, $memory['items'], false), true) as $a) {
					$years = (int)$today->format('Y') - $memory['year'];
					$items[] = new WidgetItem(
						$a['name'],
						$this->l->n('%n year ago', '%n years ago', $years),
						$this->url->linkToRouteAbsolute('files.View.showFile', ['fileid' => $a['fileId']]),
						$this->url->linkToRouteAbsolute('glimpse.api.thumbnail', ['id' => $a['id']]),
						(string)$a['fileId'],
					);
					if (count($items) >= $limit) {
						break 2;
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->info('Memories widget: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return new WidgetItems($items, $this->l->t('No pictures from this day in earlier years'));
	}

	/** The time zone set in the user's personal settings, else the server's. */
	private function timezone(string $userId): \DateTimeZone {
		$fallback = (string)$this->serverConfig->getSystemValue('default_timezone', date_default_timezone_get());
		foreach ([(string)$this->serverConfig->getUserValue($userId, 'core', 'timezone', ''), $fallback] as $tz) {
			if ($tz !== '') {
				try {
					return new \DateTimeZone($tz);
				} catch (\Throwable) {
				}
			}
		}
		return new \DateTimeZone('UTC');
	}
}
