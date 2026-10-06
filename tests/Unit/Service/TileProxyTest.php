<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\TileProxy;
use PHPUnit\Framework\TestCase;

class TileProxyTest extends TestCase {
	public function testOldTilesGo(): void {
		$tiles = ['old' => [10, 100], 'new' => [10, 500]];
		$this->assertSame(['old'], TileProxy::toPrune($tiles, 1000, 200));
	}

	public function testOldestGoUntilTheCacheFits(): void {
		$tiles = ['c' => [40, 300], 'a' => [40, 100], 'b' => [40, 200]];
		$this->assertSame(['a', 'b'], TileProxy::toPrune($tiles, 50, 0));
	}

	public function testNothingWhenWithinLimits(): void {
		$this->assertSame([], TileProxy::toPrune(['a' => [10, 100]], 10, 50));
	}
}
