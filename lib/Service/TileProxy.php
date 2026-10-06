<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Map tiles through Nextcloud: the browser never talks to the tile server (no IP or
 * location leaks to third parties, no CSP exception), and tiles are cached as the
 * OpenStreetMap tile usage policy asks. The cache is kept in bounds by PruneTileCache.
 */
class TileProxy {
	private const SOURCE = 'https://tile.openstreetmap.org/%d/%d/%d.png';
	private const FOLDER = 'tiles';
	private const MAX_AGE = 14 * 86400;
	/** OSM serves up to 19, but its policy asks not to pull the heaviest level through proxies. */
	public const MAX_ZOOM = 18;
	/** Cache limits, enforced daily: total size, and tiles unused for this long go. */
	public const CACHE_BYTES = 500 * 1000 ** 2;
	public const CACHE_DAYS = 30;
	private IAppData $appData;

	public function __construct(
		private IClientService $clientService,
		private Config $config,
		private IAppManager $appManager,
		private LoggerInterface $logger,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** @return string PNG bytes */
	public function tile(int $z, int $x, int $y): string {
		$n = 1 << max(0, min($z, 30));
		if ($z < 0 || $z > self::MAX_ZOOM || $x < 0 || $y < 0 || $x >= $n || $y >= $n) {
			throw new \InvalidArgumentException('tile');
		}
		$name = "$z-$x-$y.png";
		$folder = $this->folder();
		$cached = null;
		try {
			$cached = $folder->getFile($name);
			if ($cached->getMTime() > time() - self::MAX_AGE) {
				return $cached->getContent();
			}
		} catch (NotFoundException) {
		}
		try {
			$resp = $this->clientService->newClient()->get(sprintf(self::SOURCE, $z, $x, $y), [
				'headers' => ['User-Agent' => $this->userAgent()],
				'timeout' => 10,
			]);
			$png = (string)$resp->getBody();
			if ($resp->getStatusCode() !== 200 || $png === '') {
				throw new \RuntimeException('HTTP ' . $resp->getStatusCode());
			}
		} catch (\Throwable $e) {
			// Tile server down or refusing: an outdated tile beats a hole in the map
			if ($cached instanceof ISimpleFile) {
				$this->logger->debug('Tile server failed, serving cached tile: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				return $cached->getContent();
			}
			throw $e;
		}
		if ($cached instanceof ISimpleFile) {
			$cached->putContent($png);
		} else {
			$folder->newFile($name, $png);
		}
		return $png;
	}

	/**
	 * Drop tiles older than CACHE_DAYS, then the oldest until the cache fits CACHE_BYTES.
	 *
	 * @return int tiles removed
	 */
	public function prune(): int {
		try {
			$folder = $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return 0;
		}
		$files = [];
		$tiles = [];
		foreach ($folder->getDirectoryListing() as $file) {
			$files[$file->getName()] = $file;
			$tiles[$file->getName()] = [(int)$file->getSize(), $file->getMTime()];
		}
		$removed = 0;
		foreach (self::toPrune($tiles, self::CACHE_BYTES, time() - self::CACHE_DAYS * 86400) as $name) {
			try {
				$files[$name]->delete();
				$removed++;
			} catch (\Throwable $e) {
				$this->logger->info('Could not remove cached tile ' . $name . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $removed;
	}

	/**
	 * Which tiles to delete: all older than $before, then the oldest until the rest fit
	 * into $maxBytes.
	 *
	 * @param array<string, array{0: int, 1: int}> $tiles name => [bytes, mtime]
	 * @return list<string>
	 */
	public static function toPrune(array $tiles, int $maxBytes, int $before): array {
		uasort($tiles, static fn ($a, $b) => $a[1] <=> $b[1]);
		$total = array_sum(array_column($tiles, 0));
		$out = [];
		foreach ($tiles as $name => [$bytes, $mtime]) {
			if ($mtime >= $before && $total <= $maxBytes) {
				break;
			}
			$out[] = (string)$name;
			$total -= $bytes;
		}
		return $out;
	}

	/** As the OSM policy asks: who is fetching, in which version, and where to find out more. */
	private function userAgent(): string {
		$version = $this->appManager->getAppVersion(Application::APP_ID);
		$info = $this->appManager->getAppInfo(Application::APP_ID) ?? [];
		$site = is_string($info['website'] ?? null) ? $info['website'] : 'https://github.com/Infraviored/nextcloud-immich-search';
		return 'Nextcloud-Glimpse/' . $version . ' (photo map tile proxy; +' . $site . ')';
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
