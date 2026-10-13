<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Files_Sharing\SharedStorage;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\ITagManager;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagCreationForbiddenException;
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

	/**
	 * @param bool $visible all tags the user can see (to filter by), not only those they may assign
	 * @return list<array{id: string, name: string}>
	 */
	public function tags(string $uid, bool $visible = false): array {
		$user = $this->users->get($uid);
		$out = [];
		foreach ($this->systemTags->getAllTags(true) as $tag) {
			if ($visible ? $this->systemTags->canUserSeeTag($tag, $user) : $this->systemTags->canUserAssignTag($tag, $user)) {
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
		$visible = null;
		foreach ($newNames as $name) {
			$name = trim((string)$name);
			if ($name === '') {
				continue;
			}
			// Nextcloud refuses a second tag that differs only in case, so reuse that one
			$visible ??= array_values(array_filter($this->systemTags->getAllTags(true), fn (ISystemTag $t) => $this->systemTags->canUserSeeTag($t, $user)));
			$tag = self::findTag($visible, $name);
			if ($tag === null) {
				if (method_exists($this->systemTags, 'canUserCreateTag') && !$this->systemTags->canUserCreateTag($user)) {
					throw new \InvalidArgumentException($this->l->t('You are not allowed to create tags'));
				}
				try {
					$tag = $this->systemTags->createTag($name, true, true);
				} catch (TagAlreadyExistsException) {
					// exists, but hidden from this user
					throw new \InvalidArgumentException($this->l->t('You are not allowed to assign the tag %s', [$name]));
				} catch (TagCreationForbiddenException) {
					throw new \InvalidArgumentException($this->l->t('You are not allowed to create tags'));
				}
				$visible[] = $tag;
			}
			$tagIds[] = $tag->getId();
		}
		$tagIds = array_values(array_unique(array_map('strval', $tagIds)));
		if ($tagIds === []) {
			throw new \InvalidArgumentException($this->l->t('Tag not found'));
		}
		foreach ($tagIds as $id) {
			if (!ctype_digit($id)) {
				throw new \InvalidArgumentException($this->l->t('Tag not found'));
			}
		}
		try {
			// with the user: tags they cannot see count as not found
			$tags = $this->systemTags->getTagsByIds($tagIds, $user);
		} catch (TagNotFoundException) {
			throw new \InvalidArgumentException($this->l->t('Tag not found'));
		}
		foreach ($tags as $tag) {
			if (!$this->systemTags->canUserSeeTag($tag, $user) || !$this->systemTags->canUserAssignTag($tag, $user)) {
				throw new \InvalidArgumentException($this->l->t('You are not allowed to assign the tag %s', [$tag->getName()]));
			}
		}
		$ids = array_map(static fn ($t) => $t->getId(), array_values($tags));
		return $this->each($uid, $fileIds, function (Node $n) use ($ids) {
			// As in Files: tagging needs write access, so a read-only share cannot tag the owner's file
			if (!($n->getPermissions() & Constants::PERMISSION_UPDATE)) {
				throw new \RuntimeException('not updatable');
			}
			$this->tagMapper->assignTags((string)$n->getId(), 'files', $ids);
		});
	}

	/**
	 * The tag called $name, ignoring case and surrounding blanks, as Nextcloud does when
	 * it refuses a duplicate.
	 *
	 * @param list<ISystemTag> $tags
	 */
	public static function findTag(array $tags, string $name): ?ISystemTag {
		$want = mb_strtolower(trim($name));
		foreach ($tags as $tag) {
			if (mb_strtolower(trim($tag->getName())) === $want) {
				return $tag;
			}
		}
		return null;
	}

	/**
	 * Put moved files back where they came from, under the name they had there (a move into a
	 * folder with that name already taken renamed them to "name (2)").
	 *
	 * @param list<array{fileId: int, from: string, fromName?: string}> $moves as returned by FileTransfer
	 */
	public function undoMove(string $uid, array $moves): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$from = [];
		foreach ($moves as $m) {
			$from[(int)$m['fileId']] = [(string)$m['from'], isset($m['fromName']) ? (string)$m['fromName'] : null];
		}
		return $this->each($uid, array_keys($from), function (Node $n) use ($home, $from) {
			[$folder, $fromName] = $from[$n->getId()];
			$dest = $home->get($folder);
			if (!$dest instanceof Folder || !$dest->isCreatable()) {
				throw new \RuntimeException('target gone');
			}
			$name = self::undoName($fromName, $n->getName());
			if ($n->getParent()->getId() === $dest->getId() && $n->getName() === $name) {
				return; // already back
			}
			$n->move($dest->getPath() . '/' . $dest->getNonExistingName($name));
		});
	}

	/** The name to restore: the old one if it is a plain file name, else the current one. */
	public static function undoName(?string $fromName, string $current): string {
		if ($fromName === null || $fromName === '' || $fromName === '.' || $fromName === '..'
			|| str_contains($fromName, '/') || str_contains($fromName, '\\') || str_contains($fromName, "\0")) {
			return $current;
		}
		return $fromName;
	}

	/**
	 * Files of $fileIds the user may download, for the ZIP. Shares with download disabled
	 * ("view only") are left out, as Files does.
	 *
	 * @return array{files: list<File>, failed: list<array{fileId: int, name: string, error: string}>}
	 */
	public function readable(string $uid, array $fileIds): array {
		$home = $this->rootFolder->getUserFolder($uid);
		$files = [];
		$failed = [];
		foreach (array_unique(array_map('intval', $fileIds)) as $id) {
			$n = $home->getFirstNodeById($id);
			if (!$n instanceof File || !$n->isReadable()) {
				$failed[] = ['fileId' => $id, 'name' => $n?->getName() ?? '', 'error' => $this->l->t('Not found')];
			} elseif (!$this->canDownload($n)) {
				$failed[] = ['fileId' => $id, 'name' => $n->getName(), 'error' => $this->l->t('Download is disabled for this share')];
			} else {
				$files[] = $n;
			}
		}
		return ['files' => $files, 'failed' => $failed];
	}

	/** Same rule as apps/files_sharing ViewOnly: a share may deny the download attribute. */
	private function canDownload(Node $n): bool {
		try {
			$storage = $n->getStorage();
			if (!class_exists(SharedStorage::class) || !$storage->instanceOfStorage(SharedStorage::class)) {
				return true;
			}
			// storage wrappers pass the call on to the shared storage
			$share = $storage->getShare();
			return $share->getAttributes()?->getAttribute('permissions', 'download') !== false;
		} catch (\Throwable $e) {
			// unsure: rather not hand it out
			$this->logger->info('Download check failed: ' . $e->getMessage(), ['app' => 'glimpse']);
			return false;
		}
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
