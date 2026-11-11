<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user list of folders hidden from timeline, search, map, memories, etc.
 * Stored in user config as a JSON list.
 */
class HiddenFolders {
	public const MAX_ENTRIES = 200;
	private const CONFIG_KEY = 'hidden_folders';

	/** @var array<string, list<string>> */
	private array $cache = [];

	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return list<string> */
	public function get(string $uid): array {
		if (!isset($this->cache[$uid])) {
			$raw = $this->config->getUserValue($uid, Application::APP_ID, self::CONFIG_KEY, '[]');
			$decoded = json_decode($raw, true);
			$this->cache[$uid] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
		}
		return $this->cache[$uid];
	}

	/**
	 * @param list<string> $folders
	 * @return list<string>
	 */
	public function setFolders(string $uid, array $folders): array {
		$normalized = [];
		foreach ($folders as $f) {
			if (!is_scalar($f)) {
				continue;
			}
			$clean = '/' . trim((string)$f, '/');
			if ($clean !== '/') {
				$normalized[] = $clean;
			}
		}
		$unique = array_values(array_unique($normalized));
		if (count($unique) > self::MAX_ENTRIES) {
			$unique = array_slice($unique, 0, self::MAX_ENTRIES);
		}
		$this->config->setUserValue($uid, Application::APP_ID, self::CONFIG_KEY, json_encode($unique));
		return $this->cache[$uid] = $unique;
	}

	/**
	 * @return list<string> the updated list of hidden folders
	 */
	public function addFolder(string $uid, string $folder): array {
		$clean = '/' . trim($folder, '/');
		if ($clean === '/') {
			throw new \InvalidArgumentException('Root folder cannot be hidden');
		}
		$current = $this->get($uid);
		if (in_array($clean, $current, true)) {
			return $current;
		}
		return $this->setFolders($uid, [...$current, $clean]);
	}

	/**
	 * Is this folder (or one of its parent folders) hidden for this user?
	 * Mirrors PrivateArea::inPrivateFolder ($path === $f || str_starts_with($path, $f . '/')).
	 */
	public function isHidden(string $uid, string $path): bool {
		$folders = $this->get($uid);
		if ($folders === []) {
			return false;
		}
		$norm = '/' . ltrim($path, '/');
		foreach ($folders as $f) {
			if ($norm === $f || str_starts_with($norm, $f . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drops items inside a hidden folder.
	 *
	 * @param list<array> $items
	 * @return list<array>
	 */
	public function filter(string $uid, array $items): array {
		if ($this->get($uid) === []) {
			return $items;
		}
		return array_values(array_filter($items, fn (array $i) => !$this->isHidden($uid, (string)($i['path'] ?? ''))));
	}
}
