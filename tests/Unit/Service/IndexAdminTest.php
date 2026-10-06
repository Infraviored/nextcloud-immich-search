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
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexAdminTest extends TestCase {
	private function config(array $preset = []): Config {
		$app = $preset;
		$iconfig = $this->createMock(IConfig::class);
		$iconfig->method('getAppValue')->willReturnCallback(function ($a, $k, $d) use (&$app) {
			return $app[$k] ?? $d;
		});
		$iconfig->method('setAppValue')->willReturnCallback(function ($a, $k, $v) use (&$app) {
			$app[$k] = $v;
		});
		return new Config($iconfig, $this->createMock(ICrypto::class));
	}

	private function admin(Config $config, ImmichClient $immich, ?IUserManager $users = null): IndexAdmin {
		return new IndexAdmin($immich, $config, $users ?? $this->createMock(IUserManager::class),
			$this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));
	}

	public function testFolderNamesAreMadeLiteral(): void {
		$this->assertSame('**/icons/**', IndexAdmin::folderPattern(' /icons/ '));
		$this->assertSame('**/Program Files ?x86?/**', IndexAdmin::folderPattern('Program Files (x86)'));
		// Stored globs (also suggestions taken before) stay as they are.
		$this->assertSame('/m/u/a (1)/**', IndexAdmin::folderPattern('/m/u/a (1)/**'));
	}

	public function testExclusionsAreKnownAsOursBeforeTheyAreWritten(): void {
		$config = $this->config(['excluded_formats' => '["svg"]', 'path_pattern' => '/m/{user}/']);
		$config->setState('managed_patterns', ['**/*.ico']);
		$libs = [
			['id' => 'a', 'importPaths' => ['/m/alice'], 'exclusionPatterns' => ['**/*.ico', 'mine/**']],
			['id' => 'b', 'importPaths' => ['/m/bob'], 'exclusionPatterns' => []],
		];
		$immich = $this->createMock(ImmichClient::class);
		$puts = [];
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use ($libs, $config, &$puts) {
			if ($m === 'GET') {
				return $libs;
			}
			if ($m === 'PUT') {
				// Every pattern of this run is recorded before the first library is written.
				$this->assertContains('/m/bob/x.png', $config->getState('managed_patterns'));
				$this->assertContains('**/*.ico', $config->getState('managed_patterns'));
				if ($e === '/libraries/b') {
					throw new \RuntimeException('Immich down');
				}
				$puts[$e] = $b['exclusionPatterns'];
			}
			return [];
		});
		try {
			$this->admin($config, $immich)->applyExclusions(false, ['/m/alice/a.png', '/m/bob/x.png']);
			$this->fail('expected the error to come through');
		} catch (\RuntimeException) {
		}
		// Admin's own pattern kept, the old managed one replaced, only alice's entry for alice.
		$this->assertSame(['mine/**', '**/*.svg', '**/*.SVG', '/m/alice/a.png'], $puts['/libraries/a']);
		// Still known as ours after the failure, so the next run replaces them.
		$this->assertEqualsCanonicalizing(['**/*.ico', '**/*.svg', '**/*.SVG', '/m/alice/a.png', '/m/bob/x.png'], $config->getState('managed_patterns'));
	}

	public function testTooManyPatternsWritesNothing(): void {
		$config = $this->config(['excluded_formats' => '[]', 'path_pattern' => '/m/{user}/']);
		$libs = [
			['id' => 'a', 'importPaths' => ['/m/alice'], 'exclusionPatterns' => []],
			['id' => 'b', 'importPaths' => ['/m/bob'], 'exclusionPatterns' => array_map(fn ($i) => "own$i/**", range(1, 128))],
		];
		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturnCallback(function ($m) use ($libs) {
			$this->assertSame('GET', $m);
			return $libs;
		});
		$this->expectException(\InvalidArgumentException::class);
		$this->admin($config, $immich)->applyExclusions(false, ['/m/bob/x.png']);
	}

	public function testNewLibraryGetsOnlyItsUsersEntries(): void {
		$config = $this->config(['excluded_formats' => '[]', 'path_pattern' => '/m/{user}/']);
		$config->setState('pruned_entries', ['/m/alice/a.png', '/m/bob/b.png']);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$immich = $this->createMock(ImmichClient::class);
		$created = null;
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use (&$created) {
			if ($m === 'POST' && $e === '/libraries') {
				$created = $b;
				return ['id' => 'new'];
			}
			return ['id' => 'me'];
		});
		$this->admin($config, $immich, $users)->createLibrary('bob');
		$this->assertSame(['/m/bob'], $created['importPaths']);
		$this->assertSame(['/m/bob/b.png'], $created['exclusionPatterns']);
		$this->assertContains('/m/bob/b.png', $config->getState('managed_patterns'));
	}
}
