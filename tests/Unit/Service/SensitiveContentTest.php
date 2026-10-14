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
			$this->createMock(AssetSearch::class), $root, $this->createMock(IClientService::class), $this->createMock(IConfig::class), $factory);
	}

	private function items(): array {
		return array_map(static fn ($id) => ['id' => 'a' . $id, 'fileId' => $id, 'path' => '/p/' . $id . '.jpg'], [1, 2, 3]);
	}

	protected function setUp(): void {
		// Scored: 1 high, 2 low; 3 not scored at all
		$this->files['u.json'] = json_encode(['scores' => ['1' => 0.9, '2' => 0.3], 'marks' => []]);
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

	public function testThresholdMoves(): void {
		$this->prefs['sensitive_threshold'] = 25;
		$this->assertSame([1 => true, 2 => true], $this->service()->flagged('u'));
	}

	public function testMarksWinAndCanBeUndone(): void {
		$s = $this->service();
		// 12 is not the user's: ignored
		$res = $s->mark('u', ['1' => false, '3' => true, '12' => true]);
		$this->assertSame([1 => null, 3 => null], $res['previous']);
		$this->assertSame([1 => false, 3 => true], $res['sensitive']);
		$this->assertSame([3 => true], $this->service()->flagged('u'));
		// The next run trains with them
		$this->assertGreaterThan(0, $this->service()->get('u')['marksChanged']);

		$back = $this->service()->mark('u', $res['previous']);
		$this->assertSame([1 => true, 3 => false], $back['sensitive']);
		$this->assertSame([1 => true], $this->service()->flagged('u'));
	}

	public function testTermsCachedPerTermIndividually(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$resp = $this->createMock(\OCP\Http\Client\IResponse::class);
		$resp->method('getBody')->willReturn(json_encode(['clip' => [0.1, 0.2]]));
		$client->method('post')->willReturn($resp);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$config = $this->createMock(Config::class);
		$config->method('get')->willReturnCallback(fn ($k) => $k === 'ml_ping_url' ? 'http://ml:3000' : null);

		$immich = $this->createMock(ImmichClient::class);
		$immich->method('json')->willReturnCallback(fn ($m, $e) => $e === '/system-config' ? ['machineLearning' => ['clip' => ['modelName' => 'vit-b']]] : []);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willThrowException(new NotFoundException());
		$saved = [];
		$folder->method('newFile')->willReturnCallback(function ($k, $c) use (&$saved) {
			$saved[$k] = $c;
			return $this->createMock(ISimpleFile::class);
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$s = new SensitiveContent($config, $this->createMock(UserPrefs::class), $this->createMock(ImmichDb::class), $immich,
			$this->createMock(AssetSearch::class), $this->createMock(IRootFolder::class), $clients, $this->createMock(IConfig::class), $factory);

		$r = new \ReflectionMethod($s, 'terms');
		$r->setAccessible(true);
		$terms = $r->invoke($s);

		$this->assertCount(count(\OCA\Glimpse\Service\SensitiveModel::POSITIVE), $terms['sensitive']);
		$this->assertCount(count(\OCA\Glimpse\Service\SensitiveModel::NEUTRAL), $terms['neutral']);
		$this->assertCount(count(\OCA\Glimpse\Service\SensitiveModel::POSITIVE) + count(\OCA\Glimpse\Service\SensitiveModel::NEUTRAL), $saved);
	}
}
