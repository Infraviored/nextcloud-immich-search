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
use OCA\Glimpse\Service\ImmichDb;
use OCA\Glimpse\Service\SensitiveContent;
use OCA\Glimpse\Service\UserPrefs;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

		$config = $this->createMock(Config::class);
		$config->method('get')->willReturnCallback(fn ($k) => $k === 'sensitive_detection' ? $this->allowed : null);
		$prefs = $this->createMock(UserPrefs::class);
		$prefs->method('get')->willReturnCallback(fn ($uid, $k) => $this->prefs[$k] ?? UserPrefs::PREFS[$k][1]);

		// The user reaches files 1 to 9, nothing else
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn ($id) => $id < 10 ? $this->createMock(Node::class) : null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		return new SensitiveContent($config, $prefs, $this->createMock(ImmichDb::class), $this->createMock(ImmichClient::class),
			$assets ?? $this->createMock(AssetSearch::class), $root, $this->createMock(IClientService::class), $this->createMock(IConfig::class), $factory,
			$this->createMock(LoggerInterface::class));
	}

	private function items(): array {
		return array_map(static fn ($id) => ['id' => 'a' . $id, 'fileId' => $id, 'path' => '/p/' . $id . '.jpg'], [1, 2, 3]);
	}

	protected function setUp(): void {
		// The terms bring in 1
		$this->files['u.json'] = json_encode(['auto' => [1], 'marks' => []]);
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

	public function testAllMarksWithoutHiding(): void {
		$out = $this->service()->apply('u', $this->items());
		$this->assertSame([true, false, false], array_map(static fn ($i) => $i['sensitive'] ?? false, $out));
	}

	public function testHideAndOnly(): void {
		$this->prefs['sensitive_mode'] = 'hide';
		$this->assertSame([2, 3], array_column($this->service()->apply('u', $this->items()), 'fileId'));
		$this->prefs['sensitive_mode'] = 'only';
		$this->assertSame([1], array_column($this->service()->apply('u', $this->items()), 'fileId'));
		$this->assertSame([1 => true], $this->service()->onlyFiles('u'));
	}

	public function testGlobalSearchNeverShowsOnlyTheSensitive(): void {
		$this->prefs['sensitive_mode'] = 'only';
		$this->assertCount(3, $this->service()->apply('u', $this->items(), true));
		$this->prefs['sensitive_mode'] = 'hide';
		$this->assertSame([2, 3], array_column($this->service()->apply('u', $this->items(), true), 'fileId'));
	}

	public function testMarksWinAndCanBeUndone(): void {
		$s = $this->service();
		// 12 is not the user's: ignored
		$res = $s->mark('u', ['1' => false, '3' => true, '12' => true]);
		$this->assertSame([1 => null, 3 => null], $res['previous']);
		$this->assertSame([1 => false, 3 => true], $res['sensitive']);
		$this->assertSame([3 => true], $this->service()->flagged('u'));
		// The next build takes the pictures nearby along
		$this->assertGreaterThan(0, $this->service()->get('u')['marksChanged']);

		$back = $this->service()->mark('u', $res['previous']);
		$this->assertSame([1 => true, 3 => false], $back['sensitive']);
		$this->assertSame([1 => true], $this->service()->flagged('u'));
	}

	public function testOwnMarkWinsOverEverything(): void {
		$this->assertSame([1 => true, 3 => true], SensitiveContent::withMarks([1, 2], [2 => false, 3 => true]));
	}

	public function testOldFilesKeepTheirMarks(): void {
		$this->files['u.json'] = json_encode(['scores' => ['1' => 0.9], 'marks' => ['4' => true]]);
		$this->assertSame([4 => true], $this->service()->flagged('u'));
	}


	public function testSetTermRejectsUnseenPicture(): void {
		$assets = $this->createMock(AssetSearch::class);
		$assets->method('maySee')->willReturn(false);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Picture not found');
		$this->service($assets)->setTerm('u', 'beach', '11111111-1111-1111-1111-111111111111');
	}
}
