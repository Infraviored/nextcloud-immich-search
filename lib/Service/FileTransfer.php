<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Move or copy search hits into a folder, as the user, with Nextcloud's normal
 * permission checks (a share without delete right cannot be moved out of, etc.).
 */
class FileTransfer {
	public function __construct(
		private IRootFolder $rootFolder,
		private ImmichClient $immich,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<int> $fileIds
	 * @return array{done: int, failed: list<array{fileId: int, name: string, error: string}>, moved: list<array{fileId: int, from: string}>}
	 */
	public function transfer(string $uid, array $fileIds, string $target, bool $copy): array {
		$home = $this->rootFolder->getUserFolder($uid);
		try {
			$dest = $home->get($target);
		} catch (NotFoundException) {
			throw new \InvalidArgumentException($this->l->t('Target folder not found'));
		}
		if (!$dest instanceof Folder || !$dest->isCreatable()) {
			throw new \InvalidArgumentException($this->l->t('You cannot add files to this folder'));
		}

		$done = 0;
		$failed = [];
		$moved = [];
		foreach (array_unique($fileIds) as $id) {
			$node = $home->getFirstNodeById((int)$id);
			if ($node === null) {
				$failed[] = ['fileId' => (int)$id, 'name' => '', 'error' => $this->l->t('Not found')];
				continue;
			}
			if ($node->getParent()->getId() === $dest->getId() && !$copy) {
				$done++; // already there
				continue;
			}
			try {
				$path = $dest->getPath() . '/' . $dest->getNonExistingName($node->getName());
				$from = $home->getRelativePath($node->getParent()->getPath());
				if ($copy) {
					$node->copy($path);
				} else {
					$node->move($path);
					$moved[] = ['fileId' => (int)$id, 'from' => $from];
				}
				$done++;
			} catch (\Throwable $e) {
				$failed[] = ['fileId' => (int)$id, 'name' => $node->getName(), 'error' => $this->l->t('Not allowed')];
				$this->logger->info('Transfer failed: ' . $e->getMessage(), ['app' => 'glimpse']);
			}
		}
		if ($done > 0) {
			$this->rescan();
		}
		return ['done' => $done, 'failed' => $failed, 'moved' => $moved];
	}

	/** Let Immich pick up the new paths now instead of at its next scheduled library scan. */
	private function rescan(): void {
		try {
			foreach ($this->immich->json('GET', '/libraries') as $lib) {
				$this->immich->json('POST', '/libraries/' . $lib['id'] . '/scan');
			}
		} catch (\Throwable $e) {
			$this->logger->info('Library rescan failed: ' . $e->getMessage(), ['app' => 'glimpse']);
		}
	}
}
