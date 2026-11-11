<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\HiddenFolders;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HiddenFoldersTest extends TestCase {
	private IConfig&MockObject $config;
	private array $values = [];
	private HiddenFolders $service;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($uid, $app, $key, $default) => $this->values[$uid][$key] ?? $default
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function ($uid, $app, $key, $val) {
				$this->values[$uid][$key] = $val;
			}
		);
		$this->service = new HiddenFolders($this->config);
	}

	private function item(string $id, int $fileId, string $path): array {
		return ['id' => $id, 'fileId' => $fileId, 'path' => $path];
	}

	public function testPrefixMatchingDoesNotHideAB(): void {
		$this->service->setFolders('u', ['/A']);

		$items = [
			$this->item('1', 1, '/A/pic.jpg'),
			$this->item('2', 2, '/A/sub/pic.jpg'),
			$this->item('3', 3, '/A'),
			$this->item('4', 4, '/AB/pic.jpg'),
			$this->item('5', 5, '/AB'),
			$this->item('6', 6, '/B/pic.jpg'),
		];

		$this->assertTrue($this->service->isHidden('u', '/A/pic.jpg'));
		$this->assertTrue($this->service->isHidden('u', '/A/sub/pic.jpg'));
		$this->assertTrue($this->service->isHidden('u', '/A'));
		$this->assertTrue($this->service->isHidden('u', 'A/pic.jpg'));
		$this->assertFalse($this->service->isHidden('u', '/AB/pic.jpg'));
		$this->assertFalse($this->service->isHidden('u', '/AB'));
		$this->assertFalse($this->service->isHidden('u', '/B/pic.jpg'));

		$filtered = $this->service->filter('u', $items);
		$this->assertSame([4, 5, 6], array_column($filtered, 'fileId'));
	}

	public function testDedupe(): void {
		$res = $this->service->setFolders('u', ['/Photos', '/Photos', '/Photos/', 'Photos', '/Vacation']);
		$this->assertSame(['/Photos', '/Vacation'], $res);
		$this->assertSame(['/Photos', '/Vacation'], $this->service->get('u'));

		// Adding an existing folder returns the existing list without duplicating
		$res2 = $this->service->addFolder('u', 'Photos/');
		$this->assertSame(['/Photos', '/Vacation'], $res2);
	}

	public function testCapAt200Entries(): void {
		$folders = [];
		for ($i = 0; $i < 250; $i++) {
			$folders[] = '/Folder' . $i;
		}
		$res = $this->service->setFolders('u', $folders);
		$this->assertCount(200, $res);
		$this->assertSame('/Folder0', $res[0]);
		$this->assertSame('/Folder199', $res[199]);
	}

	public function testRootRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->addFolder('u', '/');
	}

	public function testEmptyStringRootRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->addFolder('u', '');
	}

	public function testSetFoldersFiltersOutRoot(): void {
		$res = $this->service->setFolders('u', ['/', '', '/Photos', '///']);
		$this->assertSame(['/Photos'], $res);
	}

	public function testUsersAreSeparate(): void {
		$this->service->setFolders('alice', ['/Secret']);
		$items = [$this->item('1', 1, '/Secret/photo.jpg')];

		$this->assertCount(0, $this->service->filter('alice', $items));
		$this->assertCount(1, $this->service->filter('bob', $items));
	}
}
