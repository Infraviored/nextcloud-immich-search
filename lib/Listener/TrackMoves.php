<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Listener;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\MoveTracker;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Every move or rename in Nextcloud – Files app, mobile and desktop clients, WebDAV, this
 * app – is passed on to Immich so it keeps the asset instead of analysing it again.
 *
 * Paths are taken in the owner's files, since that is what Immich indexes: a share
 * recipient moving a file inside a shared folder moves it for the owner too. Moves that
 * change the owner (out of a share into one's own files) copy the file in Nextcloud, so
 * Immich rightly sees a new file in another library.
 *
 * @template-implements IEventListener<Event>
 */
class TrackMoves implements IEventListener {
	/**
	 * Between the two events: source path => [owner, path in owner's files]. Static, as
	 * the two events need not reach the same listener instance.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $pending = [];

	public function __construct(
		private MoveTracker $tracker,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeNodeRenamedEvent || $event instanceof NodeRenamedEvent)) {
			return;
		}
		try {
			$source = $event->getSource();
			if ($event instanceof BeforeNodeRenamedEvent) {
				if ($this->tracker->isEnabled() && $this->relevant($source)) {
					$at = $this->locate($source);
					if ($at !== null) {
						self::$pending[$source->getPath()] = $at;
					}
				}
				return;
			}
			$before = self::$pending[$source->getPath()] ?? null;
			unset(self::$pending[$source->getPath()]);
			// Not enabled any more when the database was found unreachable during this request:
			// the remaining files of a bulk move are skipped instead of waiting for it each time.
			if ($before === null || !$this->tracker->isEnabled()) {
				return;
			}
			$after = $this->locate($event->getTarget());
			if ($after === null || $after[0] !== $before[0]) {
				return;
			}
			$this->tracker->move($this->tracker->immichPath(...$before), $this->tracker->immichPath(...$after));
		} catch (\Throwable $e) {
			// Never break a move over this; the next Immich scan picks the file up as new.
			$this->logger->warning('Could not carry a move over to Immich: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/** Folders, pictures and videos; not upload chunks or other files Immich ignores. */
	private function relevant(Node $node): bool {
		if ($node->getType() === FileInfo::TYPE_FOLDER) {
			return true;
		}
		return (bool)preg_match('#^(image|video)/#', $node->getMimetype()) && !str_ends_with($node->getName(), '.part');
	}

	/** @return ?array{0: string, 1: string} [owner, path in the owner's files] */
	private function locate(Node $node): ?array {
		$owner = $node->getOwner()?->getUID();
		if ($owner === null) {
			return null;
		}
		$folder = $this->rootFolder->getUserFolder($owner);
		$own = $folder->getFirstNodeById($node->getId());
		return $own !== null ? [$owner, $folder->getRelativePath($own->getPath())] : null;
	}
}
