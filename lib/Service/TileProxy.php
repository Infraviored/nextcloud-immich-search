<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;

/**
 * Map tiles through Nextcloud: the browser never talks to the tile server (no IP or
 * location leaks to third parties, no CSP exception), and tiles are cached as the
 * OpenStreetMap tile usage policy asks.
 */
class TileProxy {
	private const SOURCE = 'https://tile.openstreetmap.org/%d/%d/%d.png';
	private const FOLDER = 'tiles';
	private const MAX_AGE = 14 * 86400;
	private const MAX_ZOOM = 19;
	private IAppData $appData;

	public function __construct(
		private IClientService $clientService,
		private Config $config,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** @return string PNG bytes */
	public function tile(int $z, int $x, int $y): string {
		$n = 1 << max(0, $z);
		if ($z < 0 || $z > self::MAX_ZOOM || $x < 0 || $y < 0 || $x >= $n || $y >= $n) {
			throw new \InvalidArgumentException('tile');
		}
		$name = "$z-$x-$y.png";
		$folder = $this->folder();
		try {
			$file = $folder->getFile($name);
			if ($file->getMTime() > time() - self::MAX_AGE) {
				return $file->getContent();
			}
		} catch (NotFoundException) {
		}
		$resp = $this->clientService->newClient()->get(sprintf(self::SOURCE, $z, $x, $y), [
			'headers' => ['User-Agent' => 'Nextcloud glimpse (photo map; contact: server admin)'],
			'timeout' => 10,
		]);
		$png = (string)$resp->getBody();
		$folder->newFile($name, $png);
		return $png;
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
