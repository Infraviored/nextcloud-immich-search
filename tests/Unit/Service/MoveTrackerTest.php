<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ImmichSearch\Tests\Unit\Service;

use OCA\ImmichSearch\Service\MoveTracker;
use PHPUnit\Framework\TestCase;

class MoveTrackerTest extends TestCase {
	public function testFileMove(): void {
		$this->assertSame(['a' => '/m/u/New/x.jpg'], MoveTracker::plan(['a' => '/m/u/Old/x.jpg'], '/m/u/Old/x.jpg', '/m/u/New/x.jpg'));
	}

	public function testFolderMoveTakesEverythingBelow(): void {
		$plan = MoveTracker::plan([
			'a' => '/m/u/Cam/1.jpg',
			'b' => '/m/u/Cam/2024/2.jpg',
			'c' => '/m/u/Camera/3.jpg', // same prefix, different folder
		], '/m/u/Cam', '/m/u/Photos/Cam');
		$this->assertSame(['a' => '/m/u/Photos/Cam/1.jpg', 'b' => '/m/u/Photos/Cam/2024/2.jpg'], $plan);
	}

	public function testChecksumMatchesImmich(): void {
		// Immich: cryptoRepository.hashSha1(`path:${assetPath}`)
		$this->assertSame(sha1('path:/m/u/a.jpg'), bin2hex(MoveTracker::pathChecksum('/m/u/a.jpg')));
	}
}
