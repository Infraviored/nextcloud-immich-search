<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The permission model: a hit is shown only if the searching user can reach the file
 * in Nextcloud, under the path they know it by.
 *
 * World: alice owns /Photos/beach.jpg (id 10, shared with bob as /Shared/Photos/beach.jpg)
 * and /Private/secret.jpg (id 11, not shared). carol does not exist in Nextcloud.
 */
class AssetSearchTest extends TestCase {
	private ImmichClient&MockObject $immich;
	private array $app = [];
	private array $grants = [];
	private AssetSearch $search;

	protected function setUp(): void {
		$beach = $this->file(10, '/alice/files/Photos/beach.jpg');
		$secret = $this->file(11, '/alice/files/Private/secret.jpg');
		$beachForBob = $this->file(10, '/bob/files/Shared/Photos/beach.jpg');

		$alice = $this->folder('alice', ['Photos/beach.jpg' => $beach, 'Private/secret.jpg' => $secret], [10 => $beach, 11 => $secret]);
		$bob = $this->folder('bob', [], [10 => $beachForBob]);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([['alice', $alice], ['bob', $bob]]);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn ($u) => in_array($u, ['alice', 'bob'], true));

		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function ($k, $v) {
			$this->grants[$k] = $v;
			return true;
		});
		$cache->method('get')->willReturnCallback(fn ($k) => $this->grants[$k] ?? null);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);

		$iconfig = $this->createMock(IConfig::class);
		$iconfig->method('getAppValue')->willReturnCallback(fn ($a, $k, $d) => $this->app[$k] ?? $d);
		$config = new Config($iconfig, $this->createMock(ICrypto::class));

		$this->immich = $this->createMock(ImmichClient::class);
		$this->search = new AssetSearch($this->immich, $config, $root, $users, $caches);
	}

	private function asset(string $id, string $path, array $extra = []): array {
		return $extra + ['id' => $id, 'originalPath' => $path, 'type' => 'IMAGE', 'width' => 4000, 'height' => 3000,
			'originalMimeType' => 'image/jpeg', 'localDateTime' => '2025-09-24T13:00:00.000Z'];
	}

	public function testOwnerSeesOwnFile(): void {
		$item = $this->search->visibleItem('alice', $this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'));
		$this->assertSame('/Photos/beach.jpg', $item['path']);
		$this->assertSame(10, $item['fileId']);
	}

	public function testShareRecipientSeesItUnderTheirPath(): void {
		$item = $this->search->visibleItem('bob', $this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'));
		$this->assertSame('/Shared/Photos/beach.jpg', $item['path']);
	}

	public function testUnsharedFileIsHidden(): void {
		$this->assertNull($this->search->visibleItem('bob', $this->asset('a2', '/mnt/nextcloud/alice/Private/secret.jpg')));
	}

	public function testUnknownOwnerAndForeignPathsAreHidden(): void {
		$this->assertNull($this->search->visibleItem('alice', $this->asset('a3', '/mnt/nextcloud/carol/x.jpg')));
		$this->assertNull($this->search->visibleItem('alice', $this->asset('a4', '/somewhere/else/alice/Photos/beach.jpg')));
		$this->assertNull($this->search->visibleItem('alice', $this->asset('a5', '/mnt/nextcloud/alice/Photos/gone.jpg')));
	}

	public function testCustomPathPattern(): void {
		$this->app['path_pattern'] = '/data/{user}/files/';
		$item = $this->search->visibleItem('alice', $this->asset('a1', '/data/alice/files/Photos/beach.jpg'));
		$this->assertSame('/Photos/beach.jpg', $item['path']);
		$this->assertNull($this->search->visibleItem('alice', $this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg')));
	}

	public function testIconsAndTinyImagesAreHidden(): void {
		$p = '/mnt/nextcloud/alice/Photos/beach.jpg';
		$this->assertNull($this->search->visibleItem('alice', $this->asset('s', $p, ['originalMimeType' => 'image/svg+xml'])));
		$this->assertNull($this->search->visibleItem('alice', $this->asset('t', $p, ['width' => 16, 'height' => 16])));
		$this->assertNotNull($this->search->visibleItem('alice', $this->asset('v', $p, ['width' => 0, 'height' => 0])), 'unknown size passes');
		$this->app['min_size'] = '0';
		$search = $this->searchWithFreshConfig();
		$this->assertNotNull($search->visibleItem('alice', $this->asset('t', $p, ['width' => 16, 'height' => 16])), '0 turns the filter off');
	}

	public function testSearchSkipsPagesUntilHalfFilled(): void {
		$hidden = fn ($i) => $this->asset("h$i", '/mnt/nextcloud/alice/Private/secret.jpg');
		$pages = [
			1 => ['items' => array_map($hidden, range(1, 4)), 'nextPage' => '2'],
			2 => ['items' => [$this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'), $this->asset('a6', '/mnt/nextcloud/alice/Photos/beach.jpg')], 'nextPage' => '3'],
			3 => ['items' => [], 'nextPage' => null],
		];
		$this->immich->method('json')->willReturnCallback(fn ($m, $e, $b) => ['assets' => $pages[$b['page']]]);

		$res = $this->search->search('bob', 'beach', 1, 4);
		$this->assertCount(2, $res['items']);
		$this->assertSame(3, $res['nextPage'], 'stops once half a page is filled');
	}

	public function testThumbnailGrantFromSearch(): void {
		$this->immich->expects($this->once())->method('json')->willReturn($this->asset('a2', '/mnt/nextcloud/alice/Private/secret.jpg'));
		$this->search->visibleItem('bob', $this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'));
		$this->assertTrue($this->search->maySee('bob', 'a1'), 'granted by the search, no Immich call');
		$this->assertFalse($this->search->maySee('bob', 'a2'), 'asks Immich, then refuses');
	}

	private function searchWithFreshConfig(): AssetSearch {
		$iconfig = $this->createMock(IConfig::class);
		$iconfig->method('getAppValue')->willReturnCallback(fn ($a, $k, $d) => $this->app[$k] ?? $d);
		$r = new \ReflectionClass($this->search);
		$copy = clone $this->search;
		$prop = $r->getProperty('config');
		$prop->setValue($copy, new Config($iconfig, $this->createMock(ICrypto::class)));
		$r->getProperty('minSize')->setValue($copy, null);
		return $copy;
	}

	private function file(int $id, string $path): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn($id);
		$f->method('getPath')->willReturn($path);
		$f->method('getName')->willReturn(basename($path));
		$f->method('getMimetype')->willReturn('image/jpeg');
		return $f;
	}

	private function folder(string $uid, array $byPath, array $byId): Folder&MockObject {
		$f = $this->createMock(Folder::class);
		$f->method('get')->willReturnCallback(fn ($p) => $byPath[$p] ?? throw new NotFoundException($p));
		$f->method('getFirstNodeById')->willReturnCallback(fn ($id) => $byId[$id] ?? null);
		$f->method('getRelativePath')->willReturnCallback(fn ($p) => substr($p, strlen("/$uid/files")));
		return $f;
	}
}
