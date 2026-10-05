<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\IndexAdmin;
use OCA\Glimpse\Service\SmallImagePruner;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SmallImagePrunerTest extends TestCase {
	public function testEscapesGlobCharacters(): void {
		$this->assertSame('/a/b ?1?/x?2??.png', SmallImagePruner::escape('/a/b (1)/x[2]*.png'));
		$this->assertSame('/m/AlbumArt_?50CD?_Small.jpg', SmallImagePruner::escape('/m/AlbumArt_{50CD}_Small.jpg'));
		$this->assertSame('/a?b.png', SmallImagePruner::escape('/a\\b.png'));
		$this->assertSame('/plain/path.jpg', SmallImagePruner::escape('/plain/path.jpg'));
		$this->assertSame('/a?b.png', SmallImagePruner::escape('/a,b.png'));
	}

	public function testPacksIntoBracePatternsUnderTheLimit(): void {
		$entries = array_map(fn ($i) => "/m/u/f$i.png", range(1, 10));
		$this->assertSame(['{' . implode(',', $entries) . '}'], SmallImagePruner::pack($entries));
		$packed = SmallImagePruner::pack($entries, 40);
		$this->assertGreaterThan(1, count($packed));
		foreach ($packed as $p) {
			$this->assertLessThanOrEqual(40, strlen($p));
		}
		$this->assertSame(['/only/one.png'], SmallImagePruner::pack(['/only/one.png']));
		$this->assertSame([], SmallImagePruner::pack([]));
	}

	public function testTrashesTinyImagesAndExcludesThem(): void {
		$app = [];
		$iconfig = $this->createMock(IConfig::class);
		$iconfig->method('getAppValue')->willReturnCallback(function ($a, $k, $d) use (&$app) {
			return $app[$k] ?? $d;
		});
		$iconfig->method('setAppValue')->willReturnCallback(function ($a, $k, $v) use (&$app) {
			$app[$k] = $v;
		});
		$config = new Config($iconfig, $this->createMock(ICrypto::class));

		$img = fn ($id, $path, $w) => ['id' => $id, 'originalPath' => $path, 'width' => $w, 'height' => $w];
		$items = [
			// icons/: three tiny, nothing else -> whole folder
			$img('i1', '/m/u/icons/a.png', 16), $img('i2', '/m/u/icons/b.png', 24), $img('i3', '/m/u/icons/c.png', 32),
			// Photos/: one tiny among normal ones -> just that file
			$img('p1', '/m/u/Photos/big.jpg', 3000), $img('p2', '/m/u/Photos/tiny (1).jpg', 100),
		];
		$immich = $this->createMock(ImmichClient::class);
		$deleted = [];
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use ($items, &$deleted) {
			if ($m === 'DELETE') {
				$deleted = $b['ids'];
				return [];
			}
			return ['assets' => ['items' => $items, 'nextPage' => null]];
		});
		$index = $this->createMock(IndexAdmin::class);
		$index->expects($this->once())->method('applyExclusions')->with(false);

		$r = (new SmallImagePruner($immich, $config, $index, $this->createMock(LoggerInterface::class)))->run(true);

		$this->assertSame(5, $r['checked']);
		$this->assertSame(4, $r['pruned']);
		$this->assertEqualsCanonicalizing(['i1', 'i2', 'i3', 'p2'], $deleted);
		$this->assertEqualsCanonicalizing(['/m/u/icons/*', '/m/u/Photos/tiny ?1?.jpg'], $config->getState('pruned_entries'));
	}
}
