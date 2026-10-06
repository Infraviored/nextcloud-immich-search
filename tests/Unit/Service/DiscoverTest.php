<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Discover;
use OCA\Glimpse\Service\ImmichClient;
use PHPUnit\Framework\TestCase;

/** Duplicate groups span users: what one user may learn about them, and dismiss. */
class DiscoverTest extends TestCase {
	private Discover $discover;

	protected function setUp(): void {
		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturn([
			// g1: bob sees a and b, not c
			['duplicateId' => 'g1', 'suggestedKeepAssetIds' => ['c'], 'assets' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]],
			['duplicateId' => 'g2', 'suggestedKeepAssetIds' => ['b'], 'assets' => [['id' => 'a'], ['id' => 'b']]],
		]);
		$assets = $this->createMock(AssetSearch::class);
		$assets->method('visibleItem')->willReturnCallback(fn ($uid, $a) => $a['id'] === 'c' ? null : ['id' => $a['id'], 'fileId' => 1, 'path' => '/x']);
		$this->discover = new Discover($immich, $assets);
	}

	public function testKeepSuggestionOnlyAmongVisibleItems(): void {
		$groups = array_column($this->discover->duplicates('bob'), 'keep', 'id');
		$this->assertSame(['g1' => [], 'g2' => ['b']], $groups);
	}

	public function testDismissOnlyIfTheWholeGroupIsVisible(): void {
		$this->assertFalse($this->discover->seesWholeGroup('bob', 'g1'));
		$this->assertTrue($this->discover->seesWholeGroup('bob', 'g2'));
		$this->assertFalse($this->discover->seesWholeGroup('bob', 'nope'));
	}
}
