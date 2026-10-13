<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

/**
 * Nextcloud's collaborative tags as a search filter: which files carry a tag, and the
 * pictures among them as a gallery (straight from Nextcloud, like FolderBrowser).
 * Only tags the user can see count; a file counts only if it resolves in their tree.
 */
class TagFilter {
	/** Pictures listed at most for one tag. */
	private const MAX_ITEMS = 5000;

	public function __construct(
		private ISystemTagManager $tags,
		private ISystemTagObjectMapper $mapper,
		private IUserManager $users,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * File ids with the tag, as keys. Says nothing about whether the user may open them.
	 *
	 * @return array<int, true>
	 * @throws \InvalidArgumentException if there is no such tag the user can see
	 */
	public function fileIds(string $uid, string $tagId): array {
		$user = $this->users->get($uid);
		if ($user === null || !ctype_digit($tagId)) {
			throw new \InvalidArgumentException('tag');
		}
		try {
			$found = $this->tags->getTagsByIds([$tagId], $user);
		} catch (TagNotFoundException) {
			$found = [];
		}
		$tag = reset($found);
		if ($tag === false || !$this->tags->canUserSeeTag($tag, $user)) {
			throw new \InvalidArgumentException('tag');
		}
		$ids = $this->mapper->getObjectIdsForTags([$tagId], 'files');
		return array_fill_keys(array_map('intval', $ids), true);
	}

	/**
	 * File ids of the pictures and videos with the tag that the user can reach, as keys.
	 *
	 * @param string $type '', 'IMAGE' or 'VIDEO'
	 * @return array<int, true>
	 */
	public function mediaIds(string $uid, string $tagId, string $type = ''): array {
		return array_fill_keys(array_column($this->items($uid, $tagId, $type)['items'], 'fileId'), true);
	}

	/**
	 * Pictures and videos with the tag that the user can reach, newest first.
	 *
	 * @param string $type '', 'IMAGE' or 'VIDEO'
	 * @return array{items: list<array>, truncated: bool}
	 */
	public function items(string $uid, string $tagId, string $type = ''): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$items = [];
		$truncated = false;
		foreach (array_keys($this->fileIds($uid, $tagId)) as $id) {
			$node = $home->getFirstNodeById($id);
			if (!$node instanceof File || !preg_match('#^(image|video)/#', $node->getMimetype())) {
				continue;
			}
			$item = FolderBrowser::item($home, $node);
			if ($type !== '' && $item['type'] !== $type) {
				continue;
			}
			if (count($items) >= self::MAX_ITEMS) {
				$truncated = true;
				break;
			}
			$items[] = $item;
		}
		usort($items, static fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		return ['items' => $items, 'truncated' => $truncated];
	}
}
