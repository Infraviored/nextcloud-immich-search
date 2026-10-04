<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Dashboard;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\Discover;
use OCA\ImmichSearch\Service\PrivateArea;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/** Dashboard: pictures taken on this day in earlier years. */
class MemoriesWidget implements IAPIWidgetV2, IIconWidget {
	private \OCP\IL10N $l;

	public function __construct(
		private Discover $discover,
		private PrivateArea $privateArea,
		private Config $config,
		private IURLGenerator $url,
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
		return $this->url->linkToRouteAbsolute('immich_search.page.indextimeline');
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->config->isConfigured()) {
			return new WidgetItems([], $this->l->t('Photo search is not set up yet'));
		}
		$items = [];
		try {
			foreach ($this->discover->onThisDay($userId, date('Y-m-d')) as $memory) {
				// Never private pictures on the dashboard
				foreach ($this->privateArea->filter($userId, $memory['items'], false) as $a) {
					$years = (int)date('Y') - $memory['year'];
					$items[] = new WidgetItem(
						$a['name'],
						$this->l->n('%n year ago', '%n years ago', $years),
						$this->url->linkToRouteAbsolute('files.View.showFile', ['fileid' => $a['fileId']]),
						$this->url->linkToRouteAbsolute('immich_search.api.thumbnail', ['id' => $a['id']]),
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
}
