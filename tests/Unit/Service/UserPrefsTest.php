<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\UserPrefs;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class UserPrefsTest extends TestCase {
	private array $stored = [];
	private UserPrefs $prefs;

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn ($u, $a, $k, $d) => $this->stored[$k] ?? $d);
		$config->method('setUserValue')->willReturnCallback(function ($u, $a, $k, $v) {
			$this->stored[$k] = $v;
		});
		$this->prefs = new UserPrefs($config);
	}

	public function testNothingIsSavedIfOneValueIsInvalid(): void {
		try {
			$this->prefs->setAll('u', ['show_videos' => false, 'start_view' => 'nowhere']);
			$this->fail('invalid enum accepted');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('start_view', $e->getMessage());
		}
		$this->assertSame([], $this->stored, 'the valid key before it was not saved either');

		$this->expectException(\InvalidArgumentException::class);
		$this->prefs->setAll('u', ['show_videos' => false, 'unknown' => 1]);
	}

	public function testValidValuesAreSaved(): void {
		$this->prefs->setAll('u', ['show_videos' => false, 'start_view' => 'map']);
		$this->assertFalse($this->prefs->get('u', 'show_videos'));
		$this->assertSame('map', $this->prefs->get('u', 'start_view'));
	}
}
