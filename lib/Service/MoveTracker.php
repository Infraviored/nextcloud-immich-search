<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Carries moves and renames in Nextcloud over to Immich.
 *
 * Immich knows a file in an external library only by its path (the asset's checksum is
 * sha1("path:" . path)). A moved file would otherwise go offline and come back as a new
 * asset on the next scan: thumbnails, image understanding and face recognition all over
 * again, and faces assigned by hand are lost. Immich has no API to change a path, so this
 * updates its database directly: path, file name and that checksum, nothing else.
 *
 * Needs a database user that may only read and update those columns (docs/flo-server.md).
 */
class MoveTracker {
	public function __construct(
		private Config $config,
		private ImmichDb $db,
		private LoggerInterface $logger,
	) {
	}

	/** Set up, and not found unreachable earlier in this request. */
	public function isEnabled(): bool {
		return $this->db->isEnabled();
	}

	/**
	 * Where Immich sees a file of $owner, e.g. "/mnt/nextcloud/alice/Photos/a.jpg".
	 *
	 * @param string $relative path inside the owner's files, e.g. "/Photos/a.jpg"
	 */
	public function immichPath(string $owner, string $relative): string {
		return str_replace(Config::USER_PLACEHOLDER, $owner, $this->config->getPathPattern()) . ltrim($relative, '/');
	}

	/**
	 * New path for every asset at or below $from, as [assetId => newPath].
	 *
	 * @param array<string, string> $assets assetId => current path
	 * @return array<string, string>
	 */
	public static function plan(array $assets, string $from, string $to): array {
		$out = [];
		foreach ($assets as $id => $path) {
			if ($path === $from) {
				$out[$id] = $to;
			} elseif (str_starts_with($path, $from . '/')) {
				$out[$id] = $to . substr($path, strlen($from));
			}
		}
		return $out;
	}

	/** Immich's checksum for a library file: sha1 of "path:" and the path, raw bytes. */
	public static function pathChecksum(string $path): string {
		return sha1('path:' . $path, true);
	}

	/**
	 * Move the asset at $from, or everything below it for a folder, to $to.
	 *
	 * @return int assets updated
	 */
	public function move(string $from, string $to): int {
		if ($from === $to || !$this->isEnabled()) {
			return 0;
		}
		$pdo = $this->db->pdo();
		$like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $from) . '/%';
		$select = $pdo->prepare('SELECT id, "originalPath" FROM asset WHERE "checksumAlgorithm" = \'sha1-path\' AND ("originalPath" = ? OR "originalPath" LIKE ?)');
		$select->execute([$from, $like]);
		$plan = self::plan($select->fetchAll(\PDO::FETCH_KEY_PAIR), $from, $to);
		if ($plan === []) {
			return 0;
		}
		// Skipped when Immich already has the new path (a scan was quicker): that one stays.
		$update = $pdo->prepare('UPDATE asset a SET "originalPath" = ?, "originalFileName" = ?, checksum = ?
			WHERE a.id = ? AND NOT EXISTS (SELECT 1 FROM asset b WHERE b."libraryId" = a."libraryId" AND b."originalPath" = ?)');
		$done = 0;
		$pdo->beginTransaction();
		try {
			foreach ($plan as $id => $path) {
				$update->bindValue(1, $path);
				$update->bindValue(2, basename($path));
				$update->bindValue(3, self::pathChecksum($path), \PDO::PARAM_LOB);
				$update->bindValue(4, $id);
				$update->bindValue(5, $path);
				$update->execute();
				$done += $update->rowCount();
			}
			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		$this->logger->info(sprintf('Moved %d of %d Immich assets: %s -> %s', $done, count($plan), $from, $to), ['app' => Application::APP_ID]);
		if ($done > 0) {
			// Read by IndexSignature: Immich's paths changed without a scan.
			$this->config->setState('moves', ['count' => (int)($this->config->getState('moves')['count'] ?? 0) + 1, 'at' => time()]);
		}
		return $done;
	}

	/** Can we reach the database with the stored credentials? Error message, or null if fine. */
	public function check(): ?string {
		return $this->db->check();
	}
}
