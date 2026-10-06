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
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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

	private function pruner(Config $config, array $items, IndexAdmin $index, array &$deleted, ?ILockingProvider $locks = null): SmallImagePruner {
		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use ($items, &$deleted) {
			if ($m === 'DELETE') {
				array_push($deleted, ...$b['ids']);
				return [];
			}
			return ['assets' => ['items' => $items, 'nextPage' => null]];
		});
		return new SmallImagePruner($immich, $config, $index, $this->createMock(IRootFolder::class),
			$locks ?? $this->createMock(ILockingProvider::class), $this->createMock(LoggerInterface::class));
	}

	private static function img(string $id, string $path, int $w, array $extra = []): array {
		return $extra + ['id' => $id, 'originalPath' => $path, 'width' => $w, 'height' => $w, 'libraryId' => 'lib'];
	}

	public function testTrashesTinyImagesAndExcludesThem(): void {
		$config = $this->config(['path_pattern' => '/m/{user}/']);
		$items = [
			// icons/: three tiny, nothing else -> whole folder, but only for their types
			self::img('i1', '/m/u/icons/a.png', 16), self::img('i2', '/m/u/icons/b.gif', 24), self::img('i3', '/m/u/icons/c.png', 32),
			// Photos/: one tiny among normal ones -> just that file
			self::img('p1', '/m/u/Photos/big.jpg', 3000), self::img('p2', '/m/u/Photos/tiny (1).jpg', 100),
			// already in Immich's trash: kept in the list, not trashed again
			self::img('t1', '/m/u/Photos/old.png', 50, ['isTrashed' => true]),
			// uploaded by the service account itself / outside the Nextcloud mounts: never touched
			self::img('up', '/m/u/Photos/upload.png', 10, ['libraryId' => null]),
			self::img('ot', '/elsewhere/x.png', 10),
		];
		$index = $this->createMock(IndexAdmin::class);
		$written = null;
		$index->expects($this->once())->method('applyExclusions')->willReturnCallback(function ($scan, $entries) use (&$written, $config) {
			$this->assertFalse($scan);
			// Not saved before Immich has them.
			$this->assertSame([], $config->getState('pruned_entries'));
			$written = $entries;
			return 1;
		});
		$deleted = [];

		$r = $this->pruner($config, $items, $index, $deleted)->run(true);

		$this->assertSame(6, $r['checked']);
		$this->assertSame(4, $r['pruned']);
		$this->assertEqualsCanonicalizing(['i1', 'i2', 'i3', 'p2'], $deleted);
		$expected = ['/m/u/Photos/old.png', '/m/u/Photos/tiny ?1?.jpg', '/m/u/icons/*.{gif,png}'];
		$this->assertSame($expected, $written);
		$this->assertSame($expected, $config->getState('pruned_entries'));
		$this->assertSame('/m/u/icons', $config->getState('pruned_paths')['/m/u/icons/*.{gif,png}']);
	}

	public function testNothingIsSavedWhenImmichRefusesThePatterns(): void {
		$config = $this->config(['path_pattern' => '/m/{user}/']);
		$config->setState('pruned_entries', ['/m/u/a.png']);
		$index = $this->createMock(IndexAdmin::class);
		$index->method('applyExclusions')->willThrowException(new \InvalidArgumentException('Too many'));
		$deleted = [];
		try {
			$this->pruner($config, [self::img('x', '/m/u/b.png', 10)], $index, $deleted)->run();
			$this->fail('expected the error to come through');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame(['/m/u/a.png'], $config->getState('pruned_entries'));
		$this->assertSame([], $deleted);
	}

	public function testFullRunDropsEntriesThatNoLongerMatter(): void {
		$entries = [
			'/m/u/gone.png',          // not in Immich, not in Nextcloud -> dropped
			'/m/u/kept.png',          // not in Immich (trash emptied), still in Nextcloud -> kept
			'/m/u/now-big.png',       // seen again and no longer tiny -> dropped
			'/m/u/icons/*',           // old whole-folder entry, folder seen again -> replaced
			'/m/u/odd ?1?.png',       // from an old version, real path unknown -> kept
		];
		$paths = ['/m/u/gone.png' => '/m/u/gone.png', '/m/u/kept.png' => '/m/u/kept.png', '/m/u/now-big.png' => '/m/u/now-big.png'];
		$seen = ['/m/u/now-big.png' => true, '/m/u/icons/a.png' => true];
		$folders = ['/m/u' => [0, 1, []], '/m/u/icons' => [3, 0, ['png' => true]]];
		$kept = SmallImagePruner::keep($entries, $paths, $seen, $folders, fn ($p) => $p === '/m/u/kept.png');
		$this->assertSame(['/m/u/kept.png' => '/m/u/kept.png', '/m/u/odd ?1?.png' => ''], $kept);
	}

	public function testFolderGlobIsLimitedToTheTinyTypes(): void {
		$this->assertSame('/m/u/icons/*.png', SmallImagePruner::folderGlob('/m/u/icons', ['png', 'png']));
		$this->assertSame('/m/u/Art ?1?/*.{GIF,png}', SmallImagePruner::folderGlob('/m/u/Art (1)', ['png', 'GIF']));
		// A folder glob packs into a brace list like any other entry.
		$this->assertSame(['{/m/a.png,/m/u/icons/*.{gif,png}}'], SmallImagePruner::pack(['/m/a.png', '/m/u/icons/*.{gif,png}']));
		// Not as a whole when one tiny file has no extension: that would need "folder/*".
		$entries = SmallImagePruner::entries(['a' => '/m/x/a', 'b' => '/m/x/b.png', 'c' => '/m/x/c.png'], ['/m/x' => [3, 0, ['' => true, 'png' => true]]], true);
		$this->assertSame(['/m/x/a', '/m/x/b.png', '/m/x/c.png'], array_keys($entries));
	}

	public function testOnlyLibraryAssetsUnderThePathPattern(): void {
		$this->assertTrue(SmallImagePruner::isLibraryAsset(['libraryId' => 'l', 'originalPath' => '/m/u/a.png'], '/m/'));
		$this->assertFalse(SmallImagePruner::isLibraryAsset(['libraryId' => null, 'originalPath' => '/m/u/a.png'], '/m/'));
		$this->assertFalse(SmallImagePruner::isLibraryAsset(['originalPath' => '/m/u/a.png'], '/m/'));
		$this->assertFalse(SmallImagePruner::isLibraryAsset(['libraryId' => 'l', 'originalPath' => '/upload/a.png'], '/m/'));
	}

	public function testNextcloudPath(): void {
		$this->assertSame(['alice', 'Photos/a.jpg'], SmallImagePruner::nextcloudPath('/mnt/nextcloud/{user}/', '/mnt/nextcloud/alice/Photos/a.jpg'));
		$this->assertSame(['bob', 'x/y'], SmallImagePruner::nextcloudPath('/data/{user}/files/', '/data/bob/files/x/y'));
		$this->assertSame(['bob', ''], SmallImagePruner::nextcloudPath('/data/{user}/files/', '/data/bob/files'));
		$this->assertNull(SmallImagePruner::nextcloudPath('/data/{user}/files/', '/data/bob/other/x'));
		$this->assertNull(SmallImagePruner::nextcloudPath('/mnt/nextcloud/{user}/', '/elsewhere/a.jpg'));
	}

	public function testResetWritesFirstThenForgets(): void {
		$config = $this->config();
		$config->setState('pruned_entries', ['/m/u/a.png']);
		$config->setState('pruned_paths', ['/m/u/a.png' => '/m/u/a.png']);
		$index = $this->createMock(IndexAdmin::class);
		$index->expects($this->once())->method('applyExclusions')->with(false, [])->willReturnCallback(function () use ($config) {
			$this->assertSame(['/m/u/a.png'], $config->getState('pruned_entries'));
			return 3;
		});
		$deleted = [];
		$this->assertSame(3, $this->pruner($config, [], $index, $deleted)->reset());
		$this->assertSame([], $config->getState('pruned_entries'));
		$this->assertSame([], $config->getState('pruned_paths'));
	}

	public function testSecondRunIsRefusedWhileOneIsGoingOn(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->with(SmallImagePruner::LOCK, ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new LockedException(SmallImagePruner::LOCK));
		$locks->expects($this->never())->method('releaseLock');
		$index = $this->createMock(IndexAdmin::class);
		$index->expects($this->never())->method('applyExclusions');
		$deleted = [];
		$this->expectException(LockedException::class);
		$this->pruner($this->config(), [], $index, $deleted, $locks)->run(true);
	}
}
