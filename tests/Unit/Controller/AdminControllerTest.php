<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\Controller\AdminController;
use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\IndexAdmin;
use OCA\Glimpse\Service\SensitiveContent;
use OCA\Glimpse\Service\SmallImagePruner;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminControllerTest extends TestCase {
	private Config&MockObject $config;
	private IndexAdmin&MockObject $index;
	private SmallImagePruner&MockObject $pruner;
	private SensitiveContent&MockObject $sensitive;
	private IL10N&MockObject $l;
	private LoggerInterface&MockObject $logger;
	private AssetSearch&MockObject $assets;
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
		$this->index = $this->createMock(IndexAdmin::class);
		$this->pruner = $this->createMock(SmallImagePruner::class);
		$this->sensitive = $this->createMock(SensitiveContent::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(fn (string $text) => $text);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->assets = $this->createMock(AssetSearch::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->pruner->method('exclusive')->willReturnCallback(fn (callable $fn) => $fn());
	}

	private function controller(?IRootFolder $rootFolder = null): AdminController {
		return new AdminController(
			$this->createMock(IRequest::class),
			$this->config,
			$this->index,
			$this->pruner,
			$this->sensitive,
			$this->l,
			$this->logger,
			$this->assets,
			$this->userSession,
			$rootFolder,
		);
	}

	public function testExcludeFolderNotFoundWhenFileCannotBeResolved(): void {
		$this->assets->method('immichPathOf')->with('alice', 99)->willReturn(null);

		$res = $this->controller()->excludeFolder(99);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame(['error' => 'This file is not indexed by Glimpse'], $res->getData());
	}

	public function testExcludeFolderRejectsRootFolder(): void {
		$this->config->method('getPathPattern')->willReturn('/mnt/nextcloud/{user}/');
		$this->assets->method('immichPathOf')->with('alice', 10)->willReturn('/mnt/nextcloud/alice/root-file.jpg');

		$res = $this->controller()->excludeFolder(10);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['error' => 'The root folder cannot be ignored'], $res->getData());
	}

	public function testExcludeFolderRejectsSharedFileMovedToDifferentFolder(): void {
		$root = $this->createMock(IRootFolder::class);
		$aliceFolder = $this->createMock(Folder::class);
		$bobFolder = $this->createMock(Folder::class);

		$aliceNode = $this->createMock(Node::class);
		$bobNode = $this->createMock(Node::class);

		$aliceParent = $this->createMock(Folder::class);
		$aliceParent->method('getId')->willReturn(100);
		$aliceNode->method('getParent')->willReturn($aliceParent);

		$bobParent = $this->createMock(Folder::class);
		$bobParent->method('getId')->willReturn(200);
		$bobNode->method('getParent')->willReturn($bobParent);

		$bobUser = $this->createMock(IUser::class);
		$bobUser->method('getUID')->willReturn('bob');
		$aliceNode->method('getOwner')->willReturn($bobUser);

		$aliceFolder->method('getFirstNodeById')->with(40)->willReturn($aliceNode);
		$bobFolder->method('getFirstNodeById')->with(40)->willReturn($bobNode);

		$root->method('getUserFolder')->willReturnCallback(fn ($uid) => $uid === 'alice' ? $aliceFolder : $bobFolder);

		$res = $this->controller($root)->excludeFolder(40);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['error' => 'Only folders you own or that are shared as a whole can be ignored'], $res->getData());
	}

	public function testExcludeFolderAlreadyExcludedReturnsWithoutReapplying(): void {
		$this->config->method('getPathPattern')->willReturn('/mnt/nextcloud/{user}/');
		$this->assets->method('immichPathOf')->with('alice', 20)->willReturn('/mnt/nextcloud/alice/Photos/pic.jpg');
		$this->config->method('get')->with('excluded_folders')->willReturn(['/mnt/nextcloud/alice/Photos/**']);

		$this->index->expects($this->never())->method('applyExclusions');
		$this->config->expects($this->never())->method('set');

		$res = $this->controller()->excludeFolder(20);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['pattern' => '/mnt/nextcloud/alice/Photos/**', 'folder' => '/Photos', 'exclusionsApplied' => null], $res->getData());
	}

	public function testExcludeFolderAddsPatternAndAppliesUnderLock(): void {
		$this->config->method('getPathPattern')->willReturn('/mnt/nextcloud/{user}/');
		$this->assets->method('immichPathOf')->with('alice', 30)->willReturn('/mnt/nextcloud/alice/Vacation/beach.jpg');
		$this->config->method('get')->with('excluded_folders')->willReturn(['/other/**']);

		$this->config->expects($this->once())
			->method('set')
			->with('excluded_folders', ['/other/**', '/mnt/nextcloud/alice/Vacation/**']);
		$this->index->expects($this->once())
			->method('applyExclusions')
			->willReturn(4);

		$res = $this->controller()->excludeFolder(30);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['pattern' => '/mnt/nextcloud/alice/Vacation/**', 'folder' => '/Vacation', 'exclusionsApplied' => 4], $res->getData());
	}
}
