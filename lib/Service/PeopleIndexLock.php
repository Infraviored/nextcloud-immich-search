<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * One rebuild of the people and map index at a time: the hourly job, the one the admin
 * asked for and occ would otherwise run side by side, each writing its own result.
 */
class PeopleIndexLock {
	public const KEY = 'glimpse/people-index';

	public function __construct(
		private ILockingProvider $locks,
	) {
	}

	/** @throws LockedException when a rebuild is going on */
	public function run(callable $fn): mixed {
		$this->locks->acquireLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE, 'Glimpse: people index');
		try {
			return $fn();
		} finally {
			$this->locks->releaseLock(self::KEY, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
