<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\BackgroundJob;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * After files were copied in the app: let Immich scan the libraries holding the owner's
 * files now instead of at its next scheduled scan. Queued once per owner (argument: uid),
 * in the background so a copy never waits for Immich.
 */
class ScanLibraries extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private ImmichClient $immich,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!is_string($argument) || $argument === '' || !$this->config->isConfigured()) {
			return;
		}
		$root = str_replace(Config::USER_PLACEHOLDER, $argument, $this->config->getPathPattern());
		try {
			foreach (self::covering($this->immich->json('GET', '/libraries'), $root) as $id) {
				$this->immich->json('POST', '/libraries/' . rawurlencode($id) . '/scan');
			}
		} catch (\Throwable $e) {
			$this->logger->info('Library scan failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/**
	 * Libraries with an import path inside $root (a folder of the user) or containing it
	 * (one library for everybody).
	 *
	 * @param list<array{id: string, importPaths?: list<string>}> $libraries
	 * @return list<string> library ids
	 */
	public static function covering(array $libraries, string $root): array {
		$root = rtrim($root, '/') . '/';
		$out = [];
		foreach ($libraries as $lib) {
			foreach ($lib['importPaths'] ?? [] as $p) {
				$p = rtrim((string)$p, '/') . '/';
				if (str_starts_with($root, $p) || str_starts_with($p, $root)) {
					$out[] = (string)$lib['id'];
					break;
				}
			}
		}
		return $out;
	}
}
