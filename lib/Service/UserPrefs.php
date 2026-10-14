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
		// Ask for the Nextcloud password before showing private pictures
		'private_password' => ['bool', true],
		// Sensitive content (SensitiveContent): only with the admin's sensitive_detection
		'sensitive_enabled' => ['bool', false],
		// all: everything, marked; hide: without sensitive pictures; only: just those
		'sensitive_mode' => ['enum:all,hide,only', 'all'],
		// The mark on tiles
		'sensitive_badge' => ['bool', true],
		// How sure the detector must be (percent) that a body part is uncovered
		'sensitive_threshold' => ['int:20-95', 50],
		// Which uncovered body parts count (SensitiveContent::CATEGORIES)
		'sensitive_breast' => ['bool', true],
		'sensitive_genitals' => ['bool', true],
		'sensitive_buttocks' => ['bool', true],
		'sensitive_chest' => ['bool', false],
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
		return match (true) {
			$type === 'bool' => $raw === '1',
			str_starts_with($type, 'int:') => (int)$raw,
			default => $raw,
		};
	}

	public function set(string $uid, string $key, mixed $value): void {
		$this->setAll($uid, [$key => $value]);
	}

	/**
	 * Several at once: all are checked before the first is stored, so a bad one does not
	 * leave the others half saved.
	 *
	 * @throws \InvalidArgumentException naming the first invalid key
	 */
	public function setAll(string $uid, array $prefs): void {
		$raw = [];
		foreach ($prefs as $key => $value) {
			$raw[$key] = $this->raw((string)$key, $value);
		}
		foreach ($raw as $key => $value) {
			$this->config->setUserValue($uid, Application::APP_ID, 'pref_' . $key, $value);
		}
	}

	private function raw(string $key, mixed $value): string {
		if (!isset(self::PREFS[$key])) {
			throw new \InvalidArgumentException($key);
		}
		$type = self::PREFS[$key][0];
		if ($type === 'bool') {
			return $value ? '1' : '0';
		}
		if (str_starts_with($type, 'int:')) {
			[$min, $max] = array_map('intval', explode('-', substr($type, 4)));
			if (!is_numeric($value)) {
				throw new \InvalidArgumentException($key);
			}
			return (string)max($min, min($max, (int)$value));
		}
		$raw = is_scalar($value) ? (string)$value : '';
		if (!in_array($raw, explode(',', substr($type, 5)), true)) {
			throw new \InvalidArgumentException($key);
		}
		return $raw;
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
