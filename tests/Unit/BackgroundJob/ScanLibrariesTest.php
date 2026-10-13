<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\BackgroundJob;

use OCA\Glimpse\BackgroundJob\ScanLibraries;
use PHPUnit\Framework\TestCase;

class ScanLibrariesTest extends TestCase {
	public function testOnlyLibrariesCoveringTheOwner(): void {
		$libs = [
			['id' => 'all', 'importPaths' => ['/mnt/nextcloud']],
			['id' => 'alice', 'importPaths' => ['/mnt/nextcloud/alice/']],
			['id' => 'alice-photos', 'importPaths' => ['/mnt/nextcloud/alice/Photos']],
			['id' => 'alicia', 'importPaths' => ['/mnt/nextcloud/alicia']],
			['id' => 'bob', 'importPaths' => ['/mnt/nextcloud/bob/', '/mnt/other']],
			['id' => 'none'],
		];
		$this->assertSame(['all', 'alice', 'alice-photos'], ScanLibraries::covering($libs, '/mnt/nextcloud/alice/'));
	}
}
