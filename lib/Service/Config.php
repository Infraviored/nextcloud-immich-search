<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCA\ImmichSearch\AppInfo\Application;
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
	public const DEFAULT_MIN_SIZE = 256;

	/** Who may name, merge and hide people. Everyone only ever sees people from pictures they can open. */
	public const FACE_MODES = ['all', 'admins', 'off'];

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
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

	public function isConfigured(): bool {
		return $this->getUrl() !== '' && $this->hasApiKey();
	}

	public function setUrl(string $url): void {
		$this->config->setAppValue(Application::APP_ID, self::URL, self::normalizeUrl($url));
	}

	public function setApiKey(string $key): void {
		$this->config->setAppValue(Application::APP_ID, self::KEY, $this->crypto->encrypt(trim($key)));
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
