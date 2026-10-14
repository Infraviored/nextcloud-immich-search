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
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
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
	/** File ids that no longer resolve for a user (share taken back): uid => list<int> */
	private array $revoked = [];
	private AssetSearch $search;
	private IShareManager&MockObject $shares;

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
		$cache->method('remove')->willReturnCallback(function ($k) {
			unset($this->grants[$k]);
			return true;
		});
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);

		$iconfig = $this->createMock(IConfig::class);
		$iconfig->method('getAppValue')->willReturnCallback(fn ($a, $k, $d) => $this->app[$k] ?? $d);
		$config = new Config($iconfig, $this->createMock(ICrypto::class));

		$this->immich = $this->createMock(ImmichClient::class);
		$this->shares = $this->createMock(IShareManager::class);
		$this->shares->method('getSharedWith')->willReturnCallback(fn ($uid) => $uid === 'bob' ? [$this->createMock(IShare::class)] : []);
		$this->search = new AssetSearch($this->immich, $config, $root, $users, $caches, $this->shares);
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
		$hidden = fn ($i) => $this->asset("h$i", '/mnt/nextcloud/carol/x.jpg');
		$pages = [
			1 => ['items' => array_map($hidden, range(1, 4)), 'nextPage' => '2'],
			2 => ['items' => [$this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'), $this->asset('a6', '/mnt/nextcloud/alice/Private/secret.jpg')], 'nextPage' => '3'],
			3 => ['items' => [], 'nextPage' => null],
		];
		$this->immich->method('json')->willReturnCallback(fn ($m, $e, $b) => ['assets' => $pages[$b['page']]]);

		$res = $this->search->search('alice', 'beach', 1, 4);
		$this->assertCount(2, $res['items']);
		$this->assertSame(3, $res['nextPage'], 'stops once half a page is filled');
	}

	public function testTagFilterKeepsOnlyTaggedFiles(): void {
		$this->immich->method('json')->willReturn(['assets' => ['items' => [
			$this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'),
			$this->asset('a2', '/mnt/nextcloud/alice/Private/secret.jpg'),
		], 'nextPage' => null]]);

		$res = $this->search->search('alice', 'beach', 1, 4, '', '', '', [11 => true]);
		$this->assertSame(['a2'], array_column($res['items'], 'id'));
	}

	public function testTagFilterStopsOnceEveryTaggedFileIsFound(): void {
		$this->immich->expects($this->once())->method('json')->willReturn(['assets' => ['items' => [
			$this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg'),
		], 'nextPage' => '2']]);

		$res = $this->search->search('alice', 'beach', 1, 4, '', '', '', [10 => true]);
		$this->assertSame(['a1'], array_column($res['items'], 'id'));
		$this->assertNull($res['nextPage'], 'the only tagged file is found, no second page');
	}

	public function testEmptyTagAsksImmichNothing(): void {
		$this->immich->expects($this->never())->method('json');
		$res = $this->search->search('alice', 'beach', 1, 4, '', '', '', []);
		$this->assertSame(['items' => [], 'nextPage' => null], $res);
	}

	private const A1 = '11111111-1111-4111-8111-111111111111';
	private const A2 = '22222222-2222-4222-8222-222222222222';

	private const VIDEO = '33333333-3333-4333-8333-333333333333';
	private const STILL = '44444444-4444-4444-8444-444444444444';

	private function still(string $id, string $video, int $ms): array {
		return ['id' => $id, 'originalPath' => '/data/upload/x/' . $id . '.jpg', 'type' => 'IMAGE', 'visibility' => 'archive',
			'originalFileName' => "glimpse-frame_{$video}_{$ms}.jpg", 'originalMimeType' => 'image/jpeg', 'width' => 768, 'height' => 432];
	}

	private function immichWithVideo(array $hits): void {
		$this->immich->method('json')->willReturnCallback(fn ($m, $e) => $m === 'GET'
			? $this->asset(self::VIDEO, '/mnt/nextcloud/alice/Photos/beach.jpg', ['type' => 'VIDEO'])
			: ['assets' => ['items' => $hits, 'nextPage' => null]]);
	}

	public function testStillStandsForItsVideoOnceAtThatMoment(): void {
		$this->immichWithVideo([
			$this->still(self::STILL, self::VIDEO, 30500),
			$this->asset(self::VIDEO, '/mnt/nextcloud/alice/Photos/beach.jpg', ['type' => 'VIDEO']),
		]);
		$res = $this->search->search('alice', 'dog', 1, 10);
		$this->assertCount(1, $res['items'], 'the video once, at its best place');
		$this->assertSame(self::VIDEO, $res['items'][0]['id']);
		$this->assertSame(10, $res['items'][0]['fileId']);
		$this->assertSame(30.5, $res['items'][0]['at']);
		$this->assertSame(self::STILL, $res['items'][0]['thumbId']);
		$this->assertTrue($this->search->maySee('alice', self::STILL), 'its thumbnail may be shown');
	}

	public function testStillsStayOutOfEverythingButSearches(): void {
		$this->immichWithVideo([]);
		$this->assertNull($this->search->visibleItem('alice', $this->still(self::STILL, self::VIDEO, 1000)));
	}

	public function testStillOfAVideoTheUserCannotOpenIsHidden(): void {
		$this->immich->method('json')->willReturnCallback(fn ($m) => $m === 'GET'
			? $this->asset(self::VIDEO, '/mnt/nextcloud/alice/Private/secret.jpg', ['type' => 'VIDEO'])
			: ['assets' => ['items' => [$this->still(self::STILL, self::VIDEO, 1000)], 'nextPage' => null]]);
		$this->assertSame([], $this->search->search('bob', 'dog', 1, 10)['items']);
	}

	public function testPersonTimelineAsksForThatPerson(): void {
		$bodies = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b) use (&$bodies) {
			$bodies[] = $b;
			return ['assets' => ['items' => [], 'nextPage' => null]];
		});
		$this->search->timeline('alice', null, 1, 10, '', null, self::A1);
		$this->assertSame([self::A1], $bodies[0]['personIds']);
		$this->assertSame('timeline', $bodies[0]['visibility']);
	}

	public function testPersonTimelineWithInvalidUuidReturnsEmpty(): void {
		$this->immich->expects($this->never())->method('json');
		$this->assertSame(['items' => [], 'nextPage' => null], $this->search->timeline('alice', null, 1, 10, '', null, 'not-a-uuid'));
	}

	public function testPersonYearsOfSomeoneWithoutSharesOnlyFromTheirFolder(): void {
		$this->immich->method('json')->willReturnCallback(fn ($m, $e, $b = null) => $m === 'GET'
			? [['timeBucket' => '2024-05-01'], ['timeBucket' => '2019-01-01'], ['timeBucket' => '2019-07-01']]
			: ['assets' => ['items' => str_starts_with($b['takenAfter'], '2024') ? [['id' => 'x']] : []]]);
		$this->assertSame([2024], $this->search->personYears('alice', self::A1));
		$this->assertSame([], $this->search->personYears('alice', 'not-a-uuid'));
	}

	public function testPersonYearsCacheAvoidsBucketsCallOnCacheHit(): void {
		$calls = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use (&$calls) {
			$calls[] = $e;
			return $e === '/timeline/buckets?visibility=timeline&personId=' . self::A1
				? [['timeBucket' => '2024-01-01']]
				: ['assets' => ['items' => [['id' => 'x']]]];
		});
		$first = $this->search->personYears('alice', self::A1);
		$this->assertSame([2024], $first);
		$callCountBefore = count($calls);
		$second = $this->search->personYears('alice', self::A1);
		$this->assertSame([2024], $second);
		$this->assertSame($callCountBefore, count($calls), 'cache hit does not call /timeline/buckets or /search/metadata');
	}

	public function testPersonYearsInvalidatesWhenNewYearSeen(): void {
		$calls = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use (&$calls) {
			$calls[] = $e;
			return $m === 'GET'
				? [['timeBucket' => '2025-01-01'], ['timeBucket' => '2024-01-01']]
				: ['assets' => ['items' => [['id' => 'x']]]];
		});
		$this->search->personYears('alice', self::A1);
		$callCountAfterFirst = count($calls);
		// Passing an already cached year does not invalidate
		$this->search->personYears('alice', self::A1, 2024);
		$this->assertSame($callCountAfterFirst, count($calls));
		// Passing an unseen year invalidates the cache and re-queries
		$this->search->personYears('alice', self::A1, 2026);
		$this->assertGreaterThan($callCountAfterFirst, count($calls));
	}

	public function testPersonYearsWithSharesCachesBuckets(): void {
		$calls = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use (&$calls) {
			$calls[] = $e;
			return [['timeBucket' => '2024-05-01'], ['timeBucket' => '2022-01-01']];
		});
		$first = $this->search->personYears('bob', self::A1);
		$this->assertSame([2024, 2022], $first);
		$callCountBefore = count($calls);
		$second = $this->search->personYears('bob', self::A1);
		$this->assertSame([2024, 2022], $second);
		$this->assertSame($callCountBefore, count($calls), 'cache hit does not call /timeline/buckets for shared scope');
	}

	public function testThumbnailGrantFromSearch(): void {
		$this->immich->expects($this->once())->method('json')->willReturn($this->asset(self::A2, '/mnt/nextcloud/alice/Private/secret.jpg'));
		$this->search->visibleItem('bob', $this->asset(self::A1, '/mnt/nextcloud/alice/Photos/beach.jpg'));
		$this->assertTrue($this->search->maySee('bob', self::A1), 'granted by the search, no Immich call');
		$this->assertFalse($this->search->maySee('bob', self::A2), 'asks Immich, then refuses');
	}

	public function testGrantEndsWhenShareIsTakenBack(): void {
		$this->search->visibleItem('bob', $this->asset(self::A1, '/mnt/nextcloud/alice/Photos/beach.jpg'));
		$this->assertTrue($this->search->maySee('bob', self::A1));
		$this->revoked['bob'] = [10];
		// Immich is asked again and the owner's file no longer resolves for bob
		$this->immich->method('json')->willReturn($this->asset(self::A1, '/mnt/nextcloud/alice/Photos/beach.jpg'));
		$this->assertFalse($this->search->maySee('bob', self::A1), 'the cached grant does not outlive the share');
	}

	public function testMaySeeRejectsMalformedIdsWithoutAskingImmich(): void {
		$this->immich->expects($this->never())->method('json');
		$this->assertFalse($this->search->maySee('bob', '../server/config'));
		$this->assertFalse($this->search->maySee('bob', self::A1 . '/thumbnail'));
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
		$f->method('getFirstNodeById')->willReturnCallback(fn ($id) => in_array($id, $this->revoked[$uid] ?? [], true) ? null : ($byId[$id] ?? null));
		$f->method('getRelativePath')->willReturnCallback(fn ($p) => substr($p, strlen("/$uid/files")));
		return $f;
	}

	public function testTimelineOfSomeoneWithoutSharesAsksOnlyForTheirFolder(): void {
		$bodies = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b) use (&$bodies) {
			$bodies[] = $b;
			return ['assets' => ['items' => [$this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg')], 'nextPage' => null]];
		});
		$this->search->timeline('alice', null, 1, 4);
		$this->search->timeline('bob', null, 1, 4);
		$this->assertSame('/mnt/nextcloud/alice/', $bodies[0]['originalPath']);
		$this->assertArrayNotHasKey('originalPath', $bodies[1], 'bob sees shared files from other folders');
	}

	public function testTimelineForwardFromAPointInTime(): void {
		$body = null;
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b) use (&$body) {
			$body = $b;
			return ['assets' => ['items' => [], 'nextPage' => null]];
		});
		$this->search->timeline('bob', null, 1, 4, '', '2020-01-01T00:00:00.000Z');
		$this->assertSame('asc', $body['order']);
		$this->assertSame('2020-01-01T00:00:00.000Z', $body['takenAfter']);
	}

	public function testYearsOnlyThoseOfTheirOwnFolder(): void {
		$this->immich->method('json')->willReturnCallback(fn ($m, $e, $b = null) => $e === '/timeline/buckets'
			? [['timeBucket' => '2024-05-01'], ['timeBucket' => '2023-01-01'], ['timeBucket' => '2022-03-01']]
			: ['assets' => ['items' => str_starts_with($b['takenAfter'] ?? '', '2023') ? [] : [['id' => 'x']]]]);
		$this->assertSame([2024, 2022], $this->search->years('alice'));
		$this->assertSame([2024, 2023, 2022], $this->search->years('bob'), 'shares can come from any year');
	}

	public function testYearsCacheAvoidsBucketsCallOnCacheHit(): void {
		$calls = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use (&$calls) {
			$calls[] = $e;
			return $e === '/timeline/buckets'
				? [['timeBucket' => '2024-01-01']]
				: ['assets' => ['items' => [['id' => 'x']]]];
		});
		$first = $this->search->years('alice');
		$this->assertSame([2024], $first);
		$callCountBefore = count($calls);
		$second = $this->search->years('alice');
		$this->assertSame([2024], $second);
		$this->assertSame($callCountBefore, count($calls), 'cache hit does not call /timeline/buckets or /search/metadata');
	}

	public function testYearsInvalidatesWhenNewYearSeen(): void {
		$calls = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b = null) use (&$calls) {
			$calls[] = $e;
			return $e === '/timeline/buckets'
				? [['timeBucket' => '2025-01-01'], ['timeBucket' => '2024-01-01']]
				: ['assets' => ['items' => [['id' => 'x']]]];
		});
		$this->search->years('alice');
		$callCountAfterFirst = count($calls);
		// Passing an already cached year does not invalidate
		$this->search->years('alice', 2024);
		$this->assertSame($callCountAfterFirst, count($calls));
		// Passing an unseen year invalidates the cache and re-queries
		$this->search->years('alice', 2026);
		$this->assertGreaterThan($callCountAfterFirst, count($calls));
	}

	public function testOwnScopeRequiresUserPlaceholderAndTrailingSlash(): void {
		$bodies = [];
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b) use (&$bodies) {
			$bodies[] = $b;
			return ['assets' => ['items' => [], 'nextPage' => null]];
		});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([
			['dan', $this->folder('dan', [], [])],
			['charlie', $this->folder('charlie', [], [])],
		]);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$shares = $this->createMock(IShareManager::class);
		$shares->method('getSharedWith')->willReturn([]);

		// Pattern without {user} placeholder: falls back to unscoped
		$iconfigNoUser = $this->createMock(IConfig::class);
		$iconfigNoUser->method('getAppValue')->willReturn('/mnt/nextcloud/data/');
		$searchNoUser = new AssetSearch($this->immich, new Config($iconfigNoUser, $this->createMock(ICrypto::class)), $root, $users, $caches, $shares);
		$searchNoUser->timeline('dan', null, 1, 4);
		$this->assertArrayNotHasKey('originalPath', $bodies[0], 'no {user} placeholder falls back to unscoped');

		// Pattern without trailing slash: ensures trailing slash is appended
		$iconfigSlash = $this->createMock(IConfig::class);
		$iconfigSlash->method('getAppValue')->willReturn('/mnt/nextcloud/{user}');
		$searchSlash = new AssetSearch($this->immich, new Config($iconfigSlash, $this->createMock(ICrypto::class)), $root, $users, $caches, $shares);
		$searchSlash->timeline('charlie', null, 1, 4);
		$this->assertSame('/mnt/nextcloud/charlie/', $bodies[1]['originalPath'], 'trailing slash is ensured');
	}

	public function testVisibleItemParsesStillWithoutVisibilityAttribute(): void {
		$vidId = '33333333-3333-4333-8333-333333333333';
		$stillName = "glimpse-frame_{$vidId}_15000.jpg";

		$this->immich->method('json')->willReturnCallback(function ($m, $e) use ($vidId) {
			if ($e === '/assets/' . $vidId) {
				return $this->asset($vidId, '/mnt/nextcloud/alice/Photos/beach.jpg', ['type' => 'VIDEO']);
			}
			return [];
		});

		$stillAsset = [
			'id' => 'still-1',
			'originalFileName' => $stillName,
		];

		$item = $this->search->visibleItem('alice', $stillAsset, true, true);
		$this->assertNotNull($item);
		$this->assertSame(10, $item['fileId']);
		$this->assertEquals(15.0, $item['at']);
		$this->assertSame('still-1', $item['thumbId']);
	}

	public function testSimilarSearchSuppressesSourceVideoMatchedViaStill(): void {
		$vidId = '33333333-3333-4333-8333-333333333333';
		$stillName = "glimpse-frame_{$vidId}_15000.jpg";

		$this->immich->method('json')->willReturnCallback(function ($m, $e) use ($vidId, $stillName) {
			if ($e === '/assets/' . $vidId) {
				return $this->asset($vidId, '/mnt/nextcloud/alice/Photos/beach.jpg', ['type' => 'VIDEO']);
			}
			if ($e === '/search/smart') {
				return ['assets' => ['items' => [
					['id' => 's1', 'originalFileName' => $stillName],
					$this->asset('a2', '/mnt/nextcloud/alice/Private/secret.jpg'),
				], 'nextPage' => null]];
			}
			return [];
		});

		$res = $this->search->search('alice', '', 1, 10, '', '', $vidId);
		$items = $res['items'];

		$this->assertCount(1, $items);
		$this->assertSame(11, $items[0]['fileId']);
	}

	public function testTimelineOnlyFilesCascadeTerminatesWithNextPageNullWhenEmpty(): void {
		$calls = 0;
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b) use (&$calls) {
			$calls++;
			return ['assets' => [
				'items' => [$this->asset('a1', '/mnt/nextcloud/alice/Photos/beach.jpg')],
				'nextPage' => $calls + 1,
			]];
		});

		// Looking for file 11, but Immich only returns file 10 across all pages
		$res = $this->search->timeline('alice', null, 1, 4, '', null, '', [11 => true]);
		$this->assertSame([], $res['items']);
		$this->assertNull($res['nextPage'], 'ends with nextPage null when nothing found across all pages');
		$this->assertSame(10, $calls, 'probes up to MAX_PAGES_PER_CALL_FILTERED pages, then stops');
	}

	public function testTimelineOnlyFilesCompletesEvenOnSubsequentPages(): void {
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b) {
			return ['assets' => [
				'items' => [$this->asset('a2', '/mnt/nextcloud/alice/Private/secret.jpg')],
				'nextPage' => 12,
			]];
		});

		// Page cursor is 5 (subsequent page); file 11 is the only target file
		$res = $this->search->timeline('alice', null, 5, 4, '', null, '', [11 => true]);
		$this->assertCount(1, $res['items']);
		$this->assertNull($res['nextPage'], 'all files in onlyFiles found, ends with nextPage null even on subsequent pages');
	}
}
