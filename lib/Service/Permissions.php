<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\IGroupManager;

class Permissions {
	public function __construct(
		private Config $config,
		private IGroupManager $groups,
	) {
	}

	public function isAdmin(string $uid): bool {
		return $this->groups->isAdmin($uid);
	}

	/** May $uid name, merge and hide people (those they can see)? */
	public function canManagePeople(string $uid): bool {
		return match ($this->config->getFaceMode()) {
			'all' => true,
			'admins' => $this->isAdmin($uid),
			default => false,
		};
	}
}
