<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCA\ImmichSearch\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user settings stored on the server (they follow the user to every device).
 * Display preferences that differ per device (tile size) live in the browser instead.
 */
class UserPrefs {
	/** @var array<string, array{0: string, 1: mixed}> */
	public const PREFS = [
		// Hide "Photos by content" from this user's unified search
		'search_disabled' => ['bool', false],
		// View the app opens with
		'start_view' => ['enum:search,timeline,people,map,folders', 'search'],
		// Include videos in results by default
		'show_videos' => ['bool', true],
	];

	public function __construct(
		private IConfig $config,
	) {
	}

	public function get(string $uid, string $key): mixed {
		[$type, $default] = self::PREFS[$key];
		$raw = $this->config->getUserValue($uid, Application::APP_ID, 'pref_' . $key, '');
		if ($raw === '') {
			return $default;
		}
		return $type === 'bool' ? $raw === '1' : $raw;
	}

	public function set(string $uid, string $key, mixed $value): void {
		if (!isset(self::PREFS[$key])) {
			throw new \InvalidArgumentException($key);
		}
		$type = self::PREFS[$key][0];
		if ($type === 'bool') {
			$raw = $value ? '1' : '0';
		} else {
			$raw = (string)$value;
			if (!in_array($raw, explode(',', substr($type, 5)), true)) {
				throw new \InvalidArgumentException($key);
			}
		}
		$this->config->setUserValue($uid, Application::APP_ID, 'pref_' . $key, $raw);
	}

	/** @return array<string, mixed> */
	public function all(string $uid): array {
		$out = [];
		foreach (array_keys(self::PREFS) as $key) {
			$out[$key] = $this->get($uid, $key);
		}
		return $out;
	}
}
