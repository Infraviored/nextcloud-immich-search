<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * A fingerprint of everything the per-user indexes (people, map) depend on: Immich's
 * pictures, people and library scans, who shares what with whom in Nextcloud, and
 * moves and removed shares as counted by MoveTracker and ShareChanges.
 * If it has not changed since the last run, the hourly rebuild can be skipped.
 */
class IndexSignature {
	/** Rebuild anyway after this long, to catch what the fingerprint does not see. */
	public const MAX_AGE = 86400;
	/** Every share type that makes a file resolve in the recipient's own tree. */
	private const SHARE_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_CIRCLE, IShare::TYPE_ROOM, IShare::TYPE_DECK];

	public function __construct(
		private ImmichClient $immich,
		private IUserManager $users,
		private IShareManager $shares,
		private Config $config,
	) {
	}

	public function compute(): string {
		$parts = [];
		$stats = $this->immich->json('GET', '/server/statistics');
		$parts[] = ($stats['photos'] ?? 0) . '/' . ($stats['videos'] ?? 0);
		foreach ($this->immich->json('GET', '/libraries') as $lib) {
			$parts[] = $lib['id'] . '@' . ($lib['refreshedAt'] ?? '');
		}
		$people = $this->immich->json('GET', '/people?withHidden=true&size=1');
		$parts[] = 'people:' . ($people['total'] ?? 0);
		$parts[] = 'moves:' . ($this->config->getState('moves')['count'] ?? 0);
		$parts[] = 'shares_changed:' . ($this->config->getState('shares_changed')['count'] ?? 0);
		$this->users->callForSeenUsers(function (IUser $u) use (&$parts) {
			foreach (self::SHARE_TYPES as $type) {
				// Types whose app is not installed give an empty list
				foreach ($this->shares->getSharedWith($u->getUID(), $type, null, -1) as $share) {
					$parts[] = $u->getUID() . ':' . $type . ':' . $share->getId() . ':' . $share->getNodeId() . ':' . $share->getPermissions();
				}
			}
		});
		sort($parts);
		return hash('sha256', implode("\n", $parts));
	}
}
