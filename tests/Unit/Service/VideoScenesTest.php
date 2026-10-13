<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\VideoScenes;
use PHPUnit\Framework\TestCase;

class VideoScenesTest extends TestCase {
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
}
