<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * A folder as a gallery: its subfolders and its pictures and videos, straight from
 * Nextcloud (no Immich needed; thumbnails come from Nextcloud's preview service).
 */
class FolderBrowser {
	private const MAX_ITEMS = 5000;

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/** @return array{path: string, folders: list<array>, items: list<array>, truncated: bool} */
	public function list(string $uid, string $path): array {
		$home = $this->rootFolder->getUserFolder($uid);
		try {
			$folder = $path === '' || $path === '/' ? $home : $home->get($path);
		} catch (NotFoundException) {
			throw new \InvalidArgumentException('path');
		}
		if (!$folder instanceof Folder) {
			throw new \InvalidArgumentException('path');
		}
		$folders = [];
		$items = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$folders[] = ['name' => $node->getName(), 'path' => $home->getRelativePath($node->getPath())];
			} elseif ($node instanceof File && preg_match('#^(image|video)/#', $node->getMimetype()) && count($items) < self::MAX_ITEMS) {
				$items[] = self::item($home, $node);
			}
		}
		usort($folders, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
		usort($items, static fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		return ['path' => $home->getRelativePath($folder->getPath()), 'folders' => $folders, 'items' => $items, 'truncated' => count($items) >= self::MAX_ITEMS];
	}

	/** Items for file ids (the map knows ids and coordinates, the grid needs names and paths). */
	public function items(string $uid, array $fileIds): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$out = [];
		foreach (array_unique(array_map('intval', $fileIds)) as $id) {
			$node = $home->getFirstNodeById($id);
			if ($node instanceof File) {
				$out[] = self::item($home, $node);
			}
		}
		return $out;
	}

	private static function item(Folder $home, File $node): array {
		return [
			'id' => null,
			'fileId' => $node->getId(),
			'type' => str_starts_with($node->getMimetype(), 'video/') ? 'VIDEO' : 'IMAGE',
			'date' => gmdate('Y-m-d\TH:i:s\Z', $node->getMTime()),
			'name' => $node->getName(),
			'path' => $home->getRelativePath($node->getPath()),
			'mime' => $node->getMimetype(),
		];
	}
}
