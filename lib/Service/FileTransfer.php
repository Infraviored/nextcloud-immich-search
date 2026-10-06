<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Glimpse\BackgroundJob\ScanLibraries;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Move or copy search hits into a folder, as the user, with Nextcloud's normal
 * permission checks (a share without delete right cannot be moved out of, etc.).
 */
class FileTransfer {
	public function __construct(
		private IRootFolder $rootFolder,
		private IJobList $jobs,
		private MoveTracker $tracker,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<int> $fileIds
	 * @return array{done: int, failed: list<array{fileId: int, name: string, error: string}>, moved: list<array{fileId: int, from: string, fromName: string}>}
	 */
	public function transfer(string $uid, array $fileIds, string $target, bool $copy): array {
		$home = $this->rootFolder->getUserFolder($uid);
		try {
			$dest = $home->get($target);
		} catch (NotFoundException|NotPermittedException|InvalidPathException) {
			throw new \InvalidArgumentException($this->l->t('Target folder not found'));
		}
		if (!$dest instanceof Folder || !$dest->isCreatable()) {
			throw new \InvalidArgumentException($this->l->t('You cannot add files to this folder'));
		}

		$done = 0;
		$failed = [];
		$moved = [];
		$destOwner = $dest->getOwner()?->getUID();
		$scan = $copy || !$this->tracker->isEnabled();
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
					// out of someone else's share: Nextcloud copies, the listener does not follow
					$scan = $scan || $node->getOwner()?->getUID() !== $destOwner;
					$node->move($path);
					// the name too: a taken name made this "name (2)", undo restores the old one
					$moved[] = ['fileId' => (int)$id, 'from' => $from, 'fromName' => $node->getName()];
				}
				$done++;
			} catch (\Throwable $e) {
				$failed[] = ['fileId' => (int)$id, 'name' => $node->getName(), 'error' => $this->l->t('Not allowed')];
				$this->logger->info('Transfer failed: ' . $e->getMessage(), ['app' => 'glimpse']);
			}
		}
		// Moves reach Immich through the TrackMoves listener; copies are new files that
		// Immich would only find at its next scheduled scan.
		if ($scan && $done > 0 && $destOwner !== null) {
			$this->queueScan($destOwner);
		}
		return ['done' => $done, 'failed' => $failed, 'moved' => $moved];
	}

	/** In the background, and once per owner however many copies come in meanwhile. */
	private function queueScan(string $owner): void {
		try {
			if (!$this->jobs->has(ScanLibraries::class, $owner)) {
				$this->jobs->add(ScanLibraries::class, $owner);
			}
		} catch (\Throwable $e) {
			$this->logger->info('Could not queue a library scan: ' . $e->getMessage(), ['app' => 'glimpse']);
		}
	}
}
