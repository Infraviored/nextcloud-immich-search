<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichDb;
use OCA\Glimpse\Service\MoveTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

	public function testUnreachableDatabaseIsNotTriedAgainAndHidesThePassword(): void {
		$config = $this->createMock(Config::class);
		// Port 1 on loopback: refused at once (or no pgsql driver at all), both a PDOException.
		$config->method('getDatabase')->willReturn(['host' => '127.0.0.1', 'port' => 1, 'name' => 'immich', 'user' => 'u', 'password' => 'secret-pw']);
		$config->method('getPathPattern')->willReturn('/m/{user}/');
		$tracker = new MoveTracker($config, new ImmichDb($config), $this->createMock(LoggerInterface::class));

		$this->assertTrue($tracker->isEnabled());
		try {
			$tracker->move('/m/u/a.jpg', '/m/u/b.jpg');
			$this->fail('expected an exception');
		} catch (\RuntimeException $e) {
			$this->assertNotInstanceOf(\PDOException::class, $e);
			$this->assertNull($e->getPrevious());
			$this->assertStringNotContainsString('secret-pw', $e->getMessage() . $e->getTraceAsString());
		}
		$this->assertFalse($tracker->isEnabled());
		$this->assertSame(0, $tracker->move('/m/u/a.jpg', '/m/u/c.jpg'));
	}
}
