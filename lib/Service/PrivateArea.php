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
use OCP\ISession;

/**
 * A user's private pictures: folders, people and single files they marked private.
 * They are left out everywhere (search, timeline, map, people, memories, duplicates)
 * unless the user unlocked them in this session; in the global Nextcloud search never.
 *
 * Immich's search results do not say who is in a picture, so for private people the
 * ids of their pictures are looked up when they are marked and refreshed hourly.
 * Stored per user in app data: {"folders": [], "people": [], "files": [], "assets": []}.
 */
class PrivateArea {
	private const FOLDER = 'private';
	private const SESSION_KEY = 'glimpse_private_until';
	private const UNLOCK_SECONDS = 1800;
	private IAppData $appData;
	/** @var array<string, array> */
	private array $cache = [];

	public function __construct(
		private ImmichClient $immich,
		private ISession $session,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	public function get(string $uid): array {
		if (!isset($this->cache[$uid])) {
			try {
				$data = json_decode($this->folder()->getFile($uid . '.json')->getContent(), true) ?: [];
			} catch (NotFoundException) {
				$data = [];
			}
			$this->cache[$uid] = $data + ['folders' => [], 'people' => [], 'files' => [], 'assets' => []];
		}
		return $this->cache[$uid];
	}

	/** Folders and people as a whole; looks up the pictures of private people. */
	public function setLists(string $uid, array $folders, array $people): array {
		$data = $this->get($uid);
		$data['folders'] = array_values(array_unique(array_filter(array_map(
			static fn ($f) => '/' . trim((string)$f, '/'), $folders), static fn ($f) => $f !== '/')));
		$data['people'] = array_values(array_unique(array_filter($people, static fn ($p) => preg_match(AssetSearch::UUID, (string)$p))));
		$data['assets'] = $this->assetsOf($data['people']);
		return $this->save($uid, $data);
	}

	/** Mark single files private, or not. */
	public function setFiles(string $uid, array $fileIds, bool $on): array {
		$data = $this->get($uid);
		$ids = array_map('intval', $fileIds);
		$data['files'] = array_values($on ? array_unique([...$data['files'], ...$ids]) : array_diff($data['files'], $ids));
		return $this->save($uid, $data);
	}

	/** Hourly, with the people index: people get new pictures. */
	public function refresh(string $uid): void {
		$data = $this->get($uid);
		if ($data['people'] !== []) {
			$data['assets'] = $this->assetsOf($data['people']);
			$this->save($uid, $data);
		}
	}

	public function unlock(): void {
		$this->session->set(self::SESSION_KEY, time() + self::UNLOCK_SECONDS);
	}

	public function lock(): void {
		$this->session->remove(self::SESSION_KEY);
	}

	public function isUnlocked(): bool {
		return (int)$this->session->get(self::SESSION_KEY) > time();
	}

	/** Is this item private for the user? Items as built by AssetSearch / FolderBrowser. */
	public function isPrivate(string $uid, array $item): bool {
		$data = $this->get($uid);
		if (in_array((int)$item['fileId'], $data['files'], true)
			|| ($item['id'] !== null && in_array($item['id'], $data['assets'], true))) {
			return true;
		}
		return $this->inPrivateFolder($uid, (string)$item['path']);
	}

	public function inPrivateFolder(string $uid, string $path): bool {
		foreach ($this->get($uid)['folders'] as $f) {
			if ($path === $f || str_starts_with($path, $f . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drop private items unless the user unlocked them ($allowUnlock = false: drop always,
	 * for the global Nextcloud search).
	 */
	public function filter(string $uid, array $items, bool $allowUnlock = true): array {
		if ($allowUnlock && $this->isUnlocked()) {
			return $items;
		}
		$data = $this->get($uid);
		if ($data['folders'] === [] && $data['files'] === [] && $data['assets'] === []) {
			return $items;
		}
		return array_values(array_filter($items, fn ($i) => !$this->isPrivate($uid, $i)));
	}

	public function hiddenPeople(string $uid): array {
		return $this->isUnlocked() ? [] : $this->get($uid)['people'];
	}

	/** @return list<string> asset ids of these people's pictures */
	private function assetsOf(array $people): array {
		$ids = [];
		foreach ($people as $pid) {
			for ($page = 1; $page !== null;) {
				$res = $this->immich->json('POST', '/search/metadata', ['personIds' => [$pid], 'page' => $page, 'size' => 1000])['assets'] ?? [];
				array_push($ids, ...array_column($res['items'] ?? [], 'id'));
				$page = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
			}
		}
		return array_values(array_unique($ids));
	}

	private function save(string $uid, array $data): array {
		$this->folder()->newFile($uid . '.json', json_encode($data));
		$this->cache[$uid] = $data;
		return $data;
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
