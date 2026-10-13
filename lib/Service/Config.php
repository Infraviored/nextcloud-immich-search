<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * The one Immich connection of this instance. Immich indexes every user's files
 * under one service account; who may see which hit is decided by Nextcloud
 * (see AssetSearch), so there are no per-user keys. The key is stored encrypted.
 */
class Config {
	public const USER_PLACEHOLDER = '{user}';
	public const DEFAULT_PATH_PATTERN = '/mnt/nextcloud/{user}/';

	private const URL = 'url';
	private const KEY = 'api_key';
	private const PATH_PATTERN = 'path_pattern';
	private const FACE_MODE = 'face_mode';
	private const MIN_SIZE = 'min_size';
	private const DB = 'immich_db';
	private const DB_PASSWORD = 'immich_db_password';
	public const DEFAULT_MIN_SIZE = 256;

	/** Who may name, merge and hide people. Everyone only ever sees people from pictures they can open. */
	public const FACE_MODES = ['all', 'admins', 'off'];

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private ?IAppConfig $appConfig = null,
	) {
	}

	public function getUrl(): string {
		return rtrim($this->config->getAppValue(Application::APP_ID, self::URL, ''), '/');
	}

	public function getApiKey(): string {
		$stored = $this->config->getAppValue(Application::APP_ID, self::KEY, '');
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Exception) {
			return '';
		}
	}

	public function hasApiKey(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::KEY, '') !== '';
	}

	/**
	 * Where Immich sees a user's Nextcloud files, e.g. "/mnt/nextcloud/{user}/" when the
	 * Nextcloud data dir "<datadir>/<user>/files" is mounted there into the Immich container.
	 */
	public function getPathPattern(): string {
		return $this->config->getAppValue(Application::APP_ID, self::PATH_PATTERN, self::DEFAULT_PATH_PATTERN);
	}

	public function getFaceMode(): string {
		$mode = $this->config->getAppValue(Application::APP_ID, self::FACE_MODE, 'all');
		return in_array($mode, self::FACE_MODES, true) ? $mode : 'all';
	}

	public function setFaceMode(string $mode): void {
		if (!in_array($mode, self::FACE_MODES, true)) {
			throw new \InvalidArgumentException('face_mode');
		}
		$this->config->setAppValue(Application::APP_ID, self::FACE_MODE, $mode);
	}

	/**
	 * Pictures whose shorter side is below this (px) are never shown: icons, emoji,
	 * thumbnails of thumbnails. Their embeddings match almost anything. 0 = off.
	 */
	public function getMinSize(): int {
		return max(0, (int)$this->config->getAppValue(Application::APP_ID, self::MIN_SIZE, (string)self::DEFAULT_MIN_SIZE));
	}

	public function setMinSize(int $px): void {
		$this->config->setAppValue(Application::APP_ID, self::MIN_SIZE, (string)max(0, min(4000, $px)));
	}

	/**
	 * Plain settings with a type and a default. Each is stored as one app config value;
	 * lists as JSON. Validation happens in set().
	 *
	 * @var array<string, array{0: string, 1: mixed}>
	 */
	public const SETTINGS = [
		// What Immich indexes (written into every library's exclusion patterns)
		'excluded_formats' => ['list', ['svg', 'svgz', 'ico', 'icns', 'cur']],
		'excluded_folders' => ['list', []],
		// Results: pictures whose shorter side is below this (px) are never shown
		'min_size' => ['int', self::DEFAULT_MIN_SIZE],
		// ... and are taken out of Immich altogether (SmallImagePruner)
		'prune_small' => ['bool', true],
		// Nextcloud unified search
		'search_photos' => ['bool', true],
		'search_people' => ['bool', true],
		'search_open_all' => ['bool', true],
		'search_min_term' => ['int', 3],
		'search_position' => ['enum:top,low', 'top'],
		// People
		'face_mode' => ['enum:all,admins,off', 'all'],
		'people_min_count' => ['int', 1],
		'people_interval_hours' => ['enum:1,6,24', '1'],
		// Search inside videos: stills every ~15 s go to Immich as archived pictures (VideoScenes)
		'video_scenes' => ['bool', false],
		// Optional health check of Immich's machine-learning service (GET <url>/ping -> "pong")
		'ml_ping_url' => ['url', ''],
	];

	public function get(string $key): mixed {
		[$type, $default] = self::SETTINGS[$key];
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '');
		if ($raw === '') {
			return $default;
		}
		if ($raw === ' ') {
			return $type === 'list' ? [] : '';
		}
		return match (true) {
			$type === 'list' => json_decode($raw, true) ?: [],
			$type === 'bool' => $raw === '1',
			$type === 'int' => (int)$raw,
			default => $raw,
		};
	}

	public function set(string $key, mixed $value): void {
		if (!isset(self::SETTINGS[$key])) {
			throw new \InvalidArgumentException($key);
		}
		$type = self::SETTINGS[$key][0];
		$raw = match (true) {
			$type === 'list' => json_encode(array_values(array_unique(array_filter(array_map(
				static fn ($v) => trim((string)$v), (array)$value), static fn ($v) => $v !== '')))),
			$type === 'bool' => $value ? '1' : '0',
			$type === 'int' => (string)max(0, min(10000, (int)$value)),
			$type === 'url' => trim((string)$value) === '' ? '' : self::normalizeUrl((string)$value),
			str_starts_with($type, 'enum:') => in_array((string)$value, explode(',', substr($type, 5)), true)
				? (string)$value : throw new \InvalidArgumentException($key),
		};
		// Store '' only for "unset": a list or text that is empty on purpose must not fall back.
		$this->config->setAppValue(Application::APP_ID, $key, $raw === '' ? ' ' : $raw);
	}

	/** @return array<string, mixed> */
	public function all(): array {
		$out = [];
		foreach (array_keys(self::SETTINGS) as $key) {
			$out[$key] = $this->get($key);
		}
		return $out;
	}

	/**
	 * Internal bookkeeping (JSON), not user-editable.
	 *
	 * Stored lazily: some of it is large (every pruned path) and would otherwise be loaded
	 * with the app config on every request. A value written before as a normal app value is
	 * still found, and becomes lazy when it is next written.
	 */
	public function getState(string $key): array {
		$raw = $this->appConfig !== null
			? $this->appConfig->getValueString(Application::APP_ID, 'state_' . $key, '[]', lazy: true)
			: $this->config->getAppValue(Application::APP_ID, 'state_' . $key, '[]');
		return json_decode($raw, true) ?: [];
	}

	public function setState(string $key, array $value): void {
		if ($this->appConfig !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'state_' . $key, json_encode($value), lazy: true);
		} else {
			$this->config->setAppValue(Application::APP_ID, 'state_' . $key, json_encode($value));
		}
	}

	public function isConfigured(): bool {
		return $this->getUrl() !== '' && $this->hasApiKey();
	}

	public function setUrl(string $url): void {
		$this->config->setAppValue(Application::APP_ID, self::URL, self::normalizeUrl($url));
	}

	public function setApiKey(string $key): void {
		$this->config->setAppValue(Application::APP_ID, self::KEY, $this->crypto->encrypt(trim($key)));
	}

	/**
	 * Immich's database, to carry moves over (see MoveTracker), or null if not set up.
	 *
	 * @return ?array{host: string, port: int, name: string, user: string, password: string}
	 */
	public function getDatabase(): ?array {
		$db = json_decode($this->config->getAppValue(Application::APP_ID, self::DB, ''), true);
		$stored = $this->config->getAppValue(Application::APP_ID, self::DB_PASSWORD, '');
		if (!is_array($db) || ($db['host'] ?? '') === '' || $stored === '') {
			return null;
		}
		try {
			$password = $this->crypto->decrypt($stored);
		} catch (\Exception) {
			return null;
		}
		return ['host' => $db['host'], 'port' => (int)($db['port'] ?? 5432), 'name' => $db['name'] ?? 'immich', 'user' => $db['user'] ?? '', 'password' => $password];
	}

	public function setDatabase(string $host, int $port, string $name, string $user): void {
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $host) || $port < 1 || $port > 65535 || $name === '' || $user === '') {
			throw new \InvalidArgumentException('database');
		}
		$this->config->setAppValue(Application::APP_ID, self::DB, json_encode(['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user]));
	}

	public function setDatabasePassword(string $password): void {
		$this->config->setAppValue(Application::APP_ID, self::DB_PASSWORD, $this->crypto->encrypt($password));
	}

	public function setPathPattern(string $pattern): void {
		$this->config->setAppValue(Application::APP_ID, self::PATH_PATTERN, self::normalizePathPattern($pattern));
	}

	public static function normalizeUrl(string $url): string {
		$url = rtrim(trim($url), '/');
		if (!preg_match('#^https?://[^/]+#i', $url)) {
			throw new \InvalidArgumentException('url');
		}
		return $url;
	}

	/** Exactly one {user}, absolute, ending in "/". */
	public static function normalizePathPattern(string $pattern): string {
		$pattern = trim($pattern);
		if (!str_starts_with($pattern, '/') || substr_count($pattern, self::USER_PLACEHOLDER) !== 1) {
			throw new \InvalidArgumentException('path_pattern');
		}
		return rtrim($pattern, '/') . '/';
	}
}
