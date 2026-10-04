<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Tests\Unit\Service;

use OCA\ImmichSearch\Service\ImmichClient;
use OCA\ImmichSearch\Service\PrivateArea;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ISession;
use PHPUnit\Framework\TestCase;

class PrivateAreaTest extends TestCase {
	private array $files = [];
	private array $session = [];
	private PrivateArea $area;

	protected function setUp(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function ($name) {
			if (!isset($this->files[$name])) {
				throw new NotFoundException();
			}
			$f = $this->createMock(ISimpleFile::class);
			$f->method('getContent')->willReturn($this->files[$name]);
			return $f;
		});
		$folder->method('newFile')->willReturnCallback(function ($name, $content) {
			$this->files[$name] = $content;
			return $this->createMock(ISimpleFile::class);
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(fn ($k) => $this->session[$k] ?? null);
		$session->method('set')->willReturnCallback(function ($k, $v) {
			$this->session[$k] = $v;
		});
		$session->method('remove')->willReturnCallback(function ($k) {
			unset($this->session[$k]);
		});

		$immich = $this->createMock(ImmichClient::class);
		// Person p1 appears in asset a-p1
		$immich->method('json')->willReturn(['assets' => ['items' => [['id' => 'a-p1']], 'nextPage' => null]]);

		$this->area = new PrivateArea($immich, $session, $factory);
	}

	private function item(string $id, int $fileId, string $path): array {
		return ['id' => $id, 'fileId' => $fileId, 'path' => $path];
	}

	public function testFoldersPeopleAndFilesAreFilteredUntilUnlocked(): void {
		$p1 = '11111111-1111-4111-8111-111111111111';
		$this->area->setLists('u', ['/Private', 'Secret/'], [$p1]);
		$this->area->setFiles('u', [7], true);
		$items = [
			$this->item('a1', 1, '/Photos/a.jpg'),
			$this->item('a2', 2, '/Private/b.jpg'),
			$this->item('a3', 3, '/Private/deep/c.jpg'),
			$this->item('a4', 4, '/PrivateNot/d.jpg'),     // only a name prefix, not the folder
			$this->item('a-p1', 5, '/Photos/e.jpg'),       // private person
			$this->item('a6', 7, '/Photos/f.jpg'),         // single file
			$this->item('a7', 8, '/Secret/g.jpg'),
		];
		$this->assertSame([1, 4], array_column($this->area->filter('u', $items), 'fileId'));

		$this->area->unlock();
		$this->assertCount(7, $this->area->filter('u', $items));
		$this->assertSame([1, 4], array_column($this->area->filter('u', $items, false), 'fileId'), 'global search never shows private');

		$this->area->lock();
		$this->assertCount(2, $this->area->filter('u', $items));
	}

	public function testUsersAreSeparate(): void {
		$this->area->setLists('alice', ['/Private'], []);
		$items = [$this->item('a', 1, '/Private/x.jpg')];
		$this->assertCount(0, $this->area->filter('alice', $items));
		$this->assertCount(1, $this->area->filter('bob', $items));
	}
}
