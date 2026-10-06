<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\Config;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase {
	public static function patterns(): array {
		return [
			'default' => ['/mnt/nextcloud/{user}/', '/mnt/nextcloud/{user}/'],
			'adds slash' => ['/data/{user}/files', '/data/{user}/files/'],
			'trims' => ['  /x/{user}/ ', '/x/{user}/'],
		];
	}

	#[DataProvider('patterns')]
	public function testNormalizesPathPattern(string $in, string $out): void {
		$this->assertSame($out, Config::normalizePathPattern($in));
	}

	public static function badPatterns(): array {
		return [['mnt/{user}/'], ['/mnt/nextcloud/'], ['/{user}/{user}/']];
	}

	#[DataProvider('badPatterns')]
	public function testRejectsBadPathPattern(string $in): void {
		$this->expectException(\InvalidArgumentException::class);
		Config::normalizePathPattern($in);
	}

	public function testUrlMustBeHttp(): void {
		$this->assertSame('http://immich:2283', Config::normalizeUrl(' http://immich:2283/ '));
		$this->expectException(\InvalidArgumentException::class);
		Config::normalizeUrl('immich:2283');
	}

	public function testMinSizeIsClamped(): void {
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(function ($app, $k, $v) use (&$store) {
			$store[$k] = $v;
		});
		$config->method('getAppValue')->willReturnCallback(function ($app, $k, $d) use (&$store) {
			return $store[$k] ?? $d;
		});
		$c = new Config($config, $this->createMock(ICrypto::class));

		$this->assertSame(Config::DEFAULT_MIN_SIZE, $c->getMinSize());
		$c->setMinSize(-5);
		$this->assertSame(0, $c->getMinSize());
		$c->setMinSize(99999);
		$this->assertSame(4000, $c->getMinSize());
	}

	public function testStateIsStoredLazily(): void {
		$store = [];
		$app = $this->createMock(IAppConfig::class);
		$app->expects($this->once())->method('setValueString')
			->willReturnCallback(function ($a, $k, $v, $lazy) use (&$store) {
				$this->assertTrue($lazy);
				$store[$k] = $v;
				return true;
			});
		$app->method('getValueString')->willReturnCallback(function ($a, $k, $d, $lazy) use (&$store) {
			$this->assertTrue($lazy);
			return $store[$k] ?? $d;
		});
		$iconfig = $this->createMock(IConfig::class);
		$iconfig->expects($this->never())->method('setAppValue');
		$c = new Config($iconfig, $this->createMock(ICrypto::class), $app);

		$this->assertSame([], $c->getState('moves'));
		$c->setState('moves', ['count' => 1]);
		$this->assertSame(['count' => 1], $c->getState('moves'));
	}
}
