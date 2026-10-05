<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\ITagManager;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Bulk actions on search hits, always as the user and through Nextcloud's own APIs, so
 * every permission rule (shares, read-only, tag visibility) applies as in Files.
 * Each action reports per file what did not work instead of failing as a whole.
 */
class FileActions {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $users,
		private ITagManager $tagManager,
		private ISystemTagManager $systemTags,
		private ISystemTagObjectMapper $tagMapper,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{done: int, failed: list<array>} */
	public function favorite(string $uid, array $fileIds, bool $on): array {
		$tagger = $this->tagManager->load('files', [], false, $uid);
		return $this->each($uid, $fileIds, fn (Node $n) => $on ? $tagger->addToFavorites($n->getId()) : $tagger->removeFromFavorites($n->getId()));
	}

	/** Moves to the Nextcloud trash bin (restorable there). */
	public function delete(string $uid, array $fileIds): array {
		return $this->each($uid, $fileIds, function (Node $n) {
			if (!$n->isDeletable()) {
				throw new \RuntimeException('not deletable');
			}
			$n->delete();
		});
	}

	/** @return list<array{id: string, name: string}> tags the user may assign */
	public function tags(string $uid): array {
		$user = $this->users->get($uid);
		$out = [];
		foreach ($this->systemTags->getAllTags(true) as $tag) {
			if ($this->systemTags->canUserAssignTag($tag, $user)) {
				$out[] = ['id' => $tag->getId(), 'name' => $tag->getName()];
			}
		}
		usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	/**
	 * @param list<string> $tagIds existing tags
	 * @param list<string> $newNames tags to create (or reuse, if one with that name exists)
	 */
	public function tag(string $uid, array $fileIds, array $tagIds, array $newNames): array {
		$user = $this->users->get($uid);
		foreach ($newNames as $name) {
			$name = trim((string)$name);
			if ($name === '') {
				continue;
			}
			try {
				$tag = $this->systemTags->getTag($name, true, true);
			} catch (TagNotFoundException) {
				if (method_exists($this->systemTags, 'canUserCreateTag') && !$this->systemTags->canUserCreateTag($user)) {
					throw new \InvalidArgumentException($this->l->t('You are not allowed to create tags'));
				}
				$tag = $this->systemTags->createTag($name, true, true);
			}
			$tagIds[] = $tag->getId();
		}
		$tags = $this->systemTags->getTagsByIds(array_values(array_unique(array_map('strval', $tagIds))));
		foreach ($tags as $tag) {
			if (!$this->systemTags->canUserAssignTag($tag, $user)) {
				throw new \InvalidArgumentException($this->l->t('You are not allowed to assign the tag %s', [$tag->getName()]));
			}
		}
		$ids = array_map(static fn ($t) => $t->getId(), array_values($tags));
		return $this->each($uid, $fileIds, fn (Node $n) => $this->tagMapper->assignTags((string)$n->getId(), 'files', $ids));
	}

	/**
	 * Put moved files back where they came from.
	 *
	 * @param list<array{fileId: int, from: string}> $moves as returned by FileTransfer
	 */
	public function undoMove(string $uid, array $moves): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$from = [];
		foreach ($moves as $m) {
			$from[(int)$m['fileId']] = (string)$m['from'];
		}
		return $this->each($uid, array_keys($from), function (Node $n) use ($home, $from) {
			$dest = $home->get($from[$n->getId()]);
			if (!$dest instanceof Folder || !$dest->isCreatable()) {
				throw new \RuntimeException('target gone');
			}
			$n->move($dest->getPath() . '/' . $dest->getNonExistingName($n->getName()));
		});
	}

	/** @return list<Node> files of $fileIds the user can read, for the ZIP download */
	public function readable(string $uid, array $fileIds): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$out = [];
		foreach (array_unique(array_map('intval', $fileIds)) as $id) {
			$n = $home->getFirstNodeById($id);
			if ($n !== null && $n->isReadable() && $n instanceof \OCP\Files\File) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private function each(string $uid, array $fileIds, callable $fn): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$done = 0;
		$failed = [];
		foreach (array_unique(array_map('intval', $fileIds)) as $id) {
			$node = $home->getFirstNodeById($id);
			if ($node === null) {
				$failed[] = ['fileId' => $id, 'name' => '', 'error' => $this->l->t('Not found')];
				continue;
			}
			try {
				$fn($node);
				$done++;
			} catch (\Throwable $e) {
				$this->logger->info('File action failed: ' . $e->getMessage(), ['app' => 'glimpse']);
				$failed[] = ['fileId' => $id, 'name' => $node->getName(), 'error' => $this->l->t('Not allowed')];
			}
		}
		return ['done' => $done, 'failed' => $failed];
	}
}
