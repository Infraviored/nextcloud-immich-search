<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\PeopleNames;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeopleNamesTest extends TestCase {
	private PeopleNames $names;

	protected function setUp(): void {
		$index = $this->createMock(PeopleIndex::class);
		$index->method('allPeople')->willReturn([
			['id' => 'p1', 'name' => 'Tara'],
			['id' => 'p2', 'name' => 'Tara Müller'],
			['id' => 'p3', 'name' => 'Flo'],
			['id' => 'p4', 'name' => 'Secret'],          // not visible to the user
			['id' => 'p5', 'name' => 'Hidden', 'isHidden' => true],
			['id' => 'p6', 'name' => ''],               // unnamed
		]);
		$index->method('forUser')->willReturn(['updated' => 1, 'people' => [
			'p1' => ['count' => 50, 'asset' => 'a'], 'p2' => ['count' => 3, 'asset' => 'b'],
			'p3' => ['count' => 9, 'asset' => 'c'], 'p5' => ['count' => 1, 'asset' => 'd'], 'p6' => ['count' => 1, 'asset' => 'e'],
		]]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$this->names = new PeopleNames($index, $caches);
	}

	public static function queries(): array {
		return [
			'name only' => ['Tara', 'p1', ''],
			'name first' => ['Tara am Strand', 'p1', 'am Strand'],
			'name last' => ['Strand mit Flo', 'p3', 'Strand mit'],
			'longest name wins' => ['Tara Müller beim Essen', 'p2', 'beim Essen'],
			'case-insensitive' => ['tara strand', 'p1', 'strand'],
			'not a whole word' => ['Taramisu', null, 'Taramisu'],
			'in the middle' => ['Strand Tara Abend', null, 'Strand Tara Abend'],
			'invisible person' => ['Secret beach', null, 'Secret beach'],
			'hidden person' => ['Hidden beach', null, 'Hidden beach'],
		];
	}

	#[DataProvider('queries')]
	public function testSplit(string $q, ?string $person, string $rest): void {
		$this->assertSame([$person, $rest], $this->names->split('u', $q));
	}

	public function testFindOnlyVisibleNamedByCount(): void {
		$this->assertSame(['p1', 'p2'], array_column($this->names->find('u', 'tar'), 'id'));
		$this->assertSame([], $this->names->find('u', 'secret'));
	}
}
