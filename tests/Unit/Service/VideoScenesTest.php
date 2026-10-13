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
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\VideoScenes;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VideoScenesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		putenv('FFMPEG_PATH=/bin/true');
	}

	protected function tearDown(): void {
		putenv('FFMPEG_PATH');
		parent::tearDown();
	}

	public function testShortClipsGetTwoStillsAndTinyOnesNone(): void {
		$this->assertSame([], VideoScenes::moments(4));
		$this->assertSame([4.0, 8.0], VideoScenes::moments(10));
	}

	public function testOneStillEvery15Seconds(): void {
		$this->assertSame([7.5, 22.5, 37.5, 52.5], VideoScenes::moments(60));
	}

	public function testLongVideosAreCappedAt40(): void {
		$m = VideoScenes::moments(8194);
		$this->assertCount(40, $m);
		$this->assertLessThan(8194, end($m));
	}

	public function testImmichDuration(): void {
		$this->assertSame(58.123, VideoScenes::seconds('0:00:58.123'));
		$this->assertSame(3725.0, VideoScenes::seconds('1:02:05'));
		$this->assertSame(8.383, VideoScenes::seconds(8383), 'Immich 3: milliseconds');
		$this->assertSame(0.0, VideoScenes::seconds(null));
		$this->assertSame(0.0, VideoScenes::seconds('garbage'));
	}

	public function testStillNames(): void {
		$this->assertSame(['33333333-3333-4333-8333-333333333333', 30.5], VideoScenes::parseName('glimpse-frame_33333333-3333-4333-8333-333333333333_30500.jpg'));
		$this->assertNull(VideoScenes::parseName('IMG_0001.jpg'));
		$this->assertNull(VideoScenes::parseName('glimpse-frame_../../x_1.jpg'));
	}

	public function testInWindow(): void {
		$config = $this->createMock(Config::class);
		$config->method('get')->with('video_scenes_hours')->willReturn('2-7');
		$scenes = new VideoScenes(
			$config,
			$this->createMock(ImmichClient::class),
			$this->createMock(AssetSearch::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
		);

		// Default window '2-7'
		$this->assertFalse($scenes->inWindow(null, strtotime('2026-10-13 01:59:59')));
		$this->assertTrue($scenes->inWindow(null, strtotime('2026-10-13 02:00:00')));
		$this->assertTrue($scenes->inWindow(null, strtotime('2026-10-13 06:59:59')));
		$this->assertFalse($scenes->inWindow(null, strtotime('2026-10-13 07:00:00')));

		// Overnight window '23-6'
		$this->assertFalse($scenes->inWindow('23-6', strtotime('2026-10-13 22:59:59')));
		$this->assertTrue($scenes->inWindow('23-6', strtotime('2026-10-13 23:00:00')));
		$this->assertTrue($scenes->inWindow('23-6', strtotime('2026-10-13 02:00:00')));
		$this->assertTrue($scenes->inWindow('23-6', strtotime('2026-10-13 05:59:59')));
		$this->assertFalse($scenes->inWindow('23-6', strtotime('2026-10-13 06:00:00')));

		// Always windows '' and '0-24'
		$this->assertTrue($scenes->inWindow('', strtotime('2026-10-13 14:00:00')));
		$this->assertTrue($scenes->inWindow('0-24', strtotime('2026-10-13 14:00:00')));
	}

	public function testPurgeBatchedOrphanCheckAndClearsFailed(): void {
		$config = $this->createMock(Config::class);
		$immich = $this->createMock(ImmichClient::class);
		$locks = $this->createMock(ILockingProvider::class);

		$vid1Still = 'glimpse-frame_11111111-1111-4111-8111-111111111111_15000.jpg';
		$vid2Still = 'glimpse-frame_22222222-2222-4222-8222-222222222222_15000.jpg';

		$immich->method('json')->willReturnCallback(function ($method, $endpoint, $body = []) use ($vid1Still, $vid2Still) {
			if ($endpoint === '/search/metadata') {
				if (($body['originalFileName'] ?? '') === VideoScenes::PREFIX) {
					return ['assets' => ['items' => [
						['id' => 's1', 'originalFileName' => $vid1Still],
						['id' => 's2', 'originalFileName' => $vid2Still],
					], 'nextPage' => null]];
				}
				if (($body['type'] ?? '') === 'VIDEO' && ($body['visibility'] ?? '') === 'timeline') {
					return ['assets' => ['items' => [
						['id' => '22222222-2222-4222-8222-222222222222'],
					], 'nextPage' => null]];
				}
			}
			if ($method === 'DELETE' && $endpoint === '/assets') {
				return [];
			}
			return [];
		});

		$state = [
			'11111111-1111-4111-8111-111111111111' => 1,
			'22222222-2222-4222-8222-222222222222' => 1,
			'33333333-3333-4333-8333-333333333333' => -1,
			'44444444-4444-4444-8444-444444444444' => 0,
		];
		$config->method('getState')->with('video_scenes')->willReturn($state);

		$savedState = null;
		$config->expects($this->once())->method('setState')->with('video_scenes', $this->callback(function ($s) use (&$savedState) {
			$savedState = $s;
			return true;
		}));

		$scenes = new VideoScenes($config, $immich, $this->createMock(AssetSearch::class), $locks, $this->createMock(LoggerInterface::class));
		$removed = $scenes->purge(true);

		$this->assertSame(1, $removed);
		$this->assertArrayNotHasKey('11111111-1111-4111-8111-111111111111', $savedState);
		$this->assertArrayHasKey('22222222-2222-4222-8222-222222222222', $savedState);
		$this->assertArrayNotHasKey('33333333-3333-4333-8333-333333333333', $savedState, 'FAILED video cleared in daily cleanup');
		$this->assertArrayNotHasKey('44444444-4444-4444-8444-444444444444', $savedState, 'deleted video cleared from done');
	}

	public function testRunHaltsOnImmich502WithoutMarkingVideoFailed(): void {
		$config = $this->createMock(Config::class);
		$config->method('get')->with('video_scenes')->willReturn(true);
		$config->method('getState')->willReturnCallback(fn ($k) => $k === 'video_scenes' ? [] : ['page' => 1]);

		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = []) {
			if ($e === '/search/metadata' && ($b['type'] ?? '') === 'VIDEO') {
				return ['assets' => ['items' => [
					['id' => '11111111-1111-4111-8111-111111111111', 'originalPath' => '/data/v1.mp4', 'duration' => 20],
				], 'nextPage' => null]];
			}
			if ($e === '/search/metadata' && str_starts_with($b['originalFileName'] ?? '', VideoScenes::PREFIX)) {
				throw new ImmichException('Immich is not reachable', 502, new \RuntimeException('connection refused'));
			}
			return [];
		});

		$assets = $this->createMock(AssetSearch::class);
		$file = $this->createMock(File::class);
		$file->method('getInternalPath')->willReturn('v1.mp4');
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn(__FILE__);
		$file->method('getStorage')->willReturn($storage);
		$assets->method('ownerFile')->willReturn($file);

		$savedState = [];
		$config->method('setState')->willReturnCallback(function ($k, $v) use (&$savedState) {
			if ($k === 'video_scenes') {
				$savedState = $v;
			}
		});

		$scenes = new VideoScenes($config, $immich, $assets, $this->createMock(ILockingProvider::class), $this->createMock(LoggerInterface::class));
		$stats = $scenes->run(10);

		$this->assertSame(0, $stats['failed']);
		$this->assertArrayNotHasKey('11111111-1111-4111-8111-111111111111', $savedState);
	}

	public function testRunSkipsExistingMoments(): void {
		$config = $this->createMock(Config::class);
		$config->method('get')->with('video_scenes')->willReturn(true);
		$config->method('getState')->willReturnCallback(fn ($k) => $k === 'video_scenes' ? [] : ['page' => 1]);

		$vidId = '11111111-1111-4111-8111-111111111111';
		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = []) use ($vidId) {
			if ($e === '/search/metadata' && ($b['type'] ?? '') === 'VIDEO') {
				return ['assets' => ['items' => [
					['id' => $vidId, 'originalPath' => '/data/v1.mp4', 'duration' => 10000],
				], 'nextPage' => null]];
			}
			if ($e === '/search/metadata' && ($b['originalFileName'] ?? '') === VideoScenes::PREFIX . $vidId . '_') {
				return ['assets' => ['items' => [
					['id' => 's1', 'originalFileName' => VideoScenes::PREFIX . $vidId . '_4000.jpg'],
					['id' => 's2', 'originalFileName' => VideoScenes::PREFIX . $vidId . '_8000.jpg'],
				], 'nextPage' => null]];
			}
			return [];
		});

		$immich->expects($this->never())->method('upload');

		$assets = $this->createMock(AssetSearch::class);
		$file = $this->createMock(File::class);
		$file->method('getInternalPath')->willReturn('v1.mp4');
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn(__FILE__);
		$file->method('getStorage')->willReturn($storage);
		$assets->method('ownerFile')->willReturn($file);

		$savedState = [];
		$config->method('setState')->willReturnCallback(function ($k, $v) use (&$savedState) {
			if ($k === 'video_scenes') {
				$savedState = $v;
			}
		});

		$scenes = new VideoScenes($config, $immich, $assets, $this->createMock(ILockingProvider::class), $this->createMock(LoggerInterface::class));
		$stats = $scenes->run(10);

		$this->assertSame(1, $stats['videos']);
		$this->assertSame(0, $stats['failed']);
		$this->assertSame(2, $savedState[$vidId]);
	}

	public function testRunSkipsNonLocalStorage(): void {
		$config = $this->createMock(Config::class);
		$config->method('get')->with('video_scenes')->willReturn(true);
		$config->method('getState')->willReturnCallback(fn ($k) => $k === 'video_scenes' ? [] : ['page' => 1]);

		$vidId = '11111111-1111-4111-8111-111111111111';
		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturnCallback(function ($m, $e, $b = []) use ($vidId) {
			if ($e === '/search/metadata' && ($b['type'] ?? '') === 'VIDEO') {
				return ['assets' => ['items' => [
					['id' => $vidId, 'originalPath' => '/data/v1.mp4', 'duration' => 10],
				], 'nextPage' => null]];
			}
			return [];
		});

		$assets = $this->createMock(AssetSearch::class);
		$file = $this->createMock(File::class);
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->expects($this->never())->method('getLocalFile');
		$file->method('getStorage')->willReturn($storage);
		$assets->method('ownerFile')->willReturn($file);

		$savedState = [];
		$config->method('setState')->willReturnCallback(function ($k, $v) use (&$savedState) {
			if ($k === 'video_scenes') {
				$savedState = $v;
			}
		});

		$scenes = new VideoScenes($config, $immich, $assets, $this->createMock(ILockingProvider::class), $this->createMock(LoggerInterface::class));
		$stats = $scenes->run(10);

		$this->assertSame(1, $stats['videos']);
		$this->assertSame(0, $stats['failed']);
		$this->assertSame(-2, $savedState[$vidId], 'NO_FILE recorded for non-local storage');
	}
}
