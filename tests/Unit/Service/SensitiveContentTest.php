<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\SensitiveContent;
use OCA\Glimpse\Service\SensitiveScanner;
use OCA\Glimpse\Service\UserPrefs;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class SensitiveContentTest extends TestCase {
	private array $files = [];
	private array $prefs = [];
	private bool $allowed = true;

	private function service(): SensitiveContent {
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
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$prefs = $this->createMock(UserPrefs::class);
		$prefs->method('get')->willReturnCallback(fn ($uid, $k) => $this->prefs[$k] ?? UserPrefs::PREFS[$k][1]);

		// Asset a1 (file 1): an uncovered breast; a2 (file 2): only a bare male chest;
		// a3 (file 3): unsure buttocks; a9: someone else's file
		$scanner = $this->createMock(SensitiveScanner::class);
		$scanner->method('enabled')->willReturnCallback(fn () => $this->allowed);
		$scanner->method('candidates')->willReturn([
			'a1' => ['path' => '/p/1', 'type' => 'IMAGE', 'scores' => ['FEMALE_BREAST_EXPOSED' => 0.9]],
			'a2' => ['path' => '/p/2', 'type' => 'IMAGE', 'scores' => ['MALE_BREAST_EXPOSED' => 0.8]],
			'a3' => ['path' => '/p/3', 'type' => 'IMAGE', 'scores' => ['BUTTOCKS_EXPOSED' => 0.3]],
			'a9' => ['path' => '/p/9', 'type' => 'IMAGE', 'scores' => ['FEMALE_GENITALIA_EXPOSED' => 0.9]],
		]);
		$assets = $this->createMock(AssetSearch::class);
		$assets->method('visibleItem')->willReturnCallback(fn ($uid, $a) => $a['id'] === 'a9' ? null : ['fileId' => (int)substr($a['id'], 1)]);

		// The user reaches files 1 to 8
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn ($id) => $id < 9 ? $this->createMock(Node::class) : null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		return new SensitiveContent($prefs, $scanner, $assets, $root, $this->createMock(IConfig::class), $factory, $cacheFactory);
	}

	private function items(): array {
		return array_map(static fn ($id) => ['id' => 'a' . $id, 'fileId' => $id, 'path' => '/p/' . $id . '.jpg'], [1, 2, 3, 4]);
	}

	protected function setUp(): void {
		$this->prefs = ['sensitive_enabled' => true];
	}

	public function testOffChangesNothing(): void {
		$this->prefs['sensitive_enabled'] = false;
		$this->prefs['sensitive_mode'] = 'hide';
		$this->assertSame($this->items(), $this->service()->apply('u', $this->items()));
		$this->assertSame('all', $this->service()->mode('u'));
	}

	public function testNotAllowedByTheAdminChangesNothing(): void {
		$this->allowed = false;
		$this->prefs['sensitive_mode'] = 'only';
		$this->assertSame($this->items(), $this->service()->apply('u', $this->items()));
		$this->assertNull($this->service()->onlyFiles('u'));
	}

	public function testOnlyChosenBodyPartsAboveTheThresholdCount(): void {
		// Default: breast, genitals, buttocks at 50 %; a bare male chest does not count
		$this->assertSame([1 => true], $this->service()->flagged('u'));
		$this->prefs['sensitive_chest'] = true;
		$this->assertSame([1 => true, 2 => true], $this->service()->flagged('u'));
		$this->prefs['sensitive_threshold'] = 25;
		$this->assertSame([1 => true, 2 => true, 3 => true], $this->service()->flagged('u'));
		$this->prefs['sensitive_breast'] = false;
		$this->assertSame([2 => true, 3 => true], $this->service()->flagged('u'));
	}

	public function testSomeoneElsesFilesNeverCount(): void {
		$this->prefs['sensitive_threshold'] = 20;
		$this->assertArrayNotHasKey(9, $this->service()->flagged('u'));
		$this->assertSame(1, $this->service()->status('u')['counts'][50]);
	}

	public function testHideAndOnly(): void {
		$this->prefs['sensitive_mode'] = 'hide';
		$this->assertSame([2, 3, 4], array_column($this->service()->apply('u', $this->items()), 'fileId'));
		$this->prefs['sensitive_mode'] = 'only';
		$this->assertSame([1], array_column($this->service()->apply('u', $this->items()), 'fileId'));
		$this->assertSame([1 => true], $this->service()->onlyFiles('u'));
	}

	public function testGlobalSearchNeverShowsOnlyTheSensitive(): void {
		$this->prefs['sensitive_mode'] = 'only';
		$this->assertCount(4, $this->service()->apply('u', $this->items(), true));
	}

	public function testMarksWinAndCanBeUndone(): void {
		$s = $this->service();
		// 12 is not the user's: ignored
		$res = $s->mark('u', ['1' => false, '4' => true, '12' => true]);
		$this->assertSame([1 => null, 4 => null], $res['previous']);
		$this->assertSame([1 => false, 4 => true], $res['sensitive']);
		$this->assertSame([4 => true], $this->service()->flagged('u'));

		$back = $this->service()->mark('u', $res['previous']);
		$this->assertSame([1 => true, 4 => false], $back['sensitive']);
		$this->assertSame([1 => true], $this->service()->flagged('u'));
	}
}
