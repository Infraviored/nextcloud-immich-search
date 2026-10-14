<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Service;

/**
 * The optional direct connection to Immich's database (`occ glimpse:configure --db-*`), for
 * what Immich's API cannot do: carrying moves over (MoveTracker) and reading the search
 * embeddings (SensitiveContent). One connection per request, opened on first use.
 */
class ImmichDb {
	private ?\PDO $pdo = null;
	/** Why the database could not be reached; not tried again for the rest of the request. */
	private ?string $failed = null;

	public function __construct(
		private Config $config,
	) {
	}

	/** Set up, and not found unreachable earlier in this request. */
	public function isEnabled(): bool {
		return $this->failed === null && $this->config->getDatabase() !== null;
	}

	/**
	 * The connection, with statements cut off after $timeout. Moves wait for the database,
	 * so they keep the default short; never behind a lock Immich holds for long.
	 */
	public function pdo(string $timeout = '5s'): \PDO {
		if ($this->failed !== null) {
			throw new \RuntimeException($this->failed);
		}
		if ($this->pdo === null) {
			$db = $this->config->getDatabase() ?? throw new \RuntimeException('Immich database not configured');
			try {
				$pdo = new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5', $db['host'], $db['port'], $db['name']), $db['user'], $db['password'], [
					\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				]);
				$pdo->exec("SET lock_timeout = '2s'");
			} catch (\PDOException $e) {
				// Remembered, so a bulk move does not wait 5 s per file. Not chained: the trace
				// of the constructor call holds the password (PHP 8.1 shows arguments).
				$this->failed = 'Immich database not reachable: ' . $e->getMessage();
				throw new \RuntimeException($this->failed);
			}
			$this->pdo = $pdo;
		}
		if (!preg_match('/^\d+(ms|s|min)$/', $timeout)) {
			throw new \InvalidArgumentException($timeout);
		}
		$this->pdo->exec("SET statement_timeout = '" . $timeout . "'");
		return $this->pdo;
	}

	/**
	 * Can we reach the database and read what $sql reads? Error message, or null if fine.
	 */
	public function check(string $sql = 'SELECT "originalPath" FROM asset LIMIT 1'): ?string {
		try {
			$this->pdo()->query($sql);
			return null;
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}
}
