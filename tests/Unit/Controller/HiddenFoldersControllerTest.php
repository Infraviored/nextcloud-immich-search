<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\Controller\HiddenFoldersController;
use OCA\Glimpse\Service\HiddenFolders;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HiddenFoldersControllerTest extends TestCase {
	private HiddenFolders&MockObject $service;
	private IUserSession&MockObject $userSession;
	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $userFolder;
	private IL10N&MockObject $l;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = $this->createMock(HiddenFolders::class);

		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);

		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(fn ($text) => $text);
	}

	private function controller(): HiddenFoldersController {
		return new HiddenFoldersController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->service,
			$this->rootFolder,
			$this->l,
		);
	}

	public function testGetReturnsFolders(): void {
		$this->service->method('get')->with('alice')->willReturn(['/Secret', '/Photos/Hidden']);

		$res = $this->controller()->get();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['folders' => ['/Secret', '/Photos/Hidden']], $res->getData());
	}

	public function testHideNotFoundWhenFileIdZeroOrMissing(): void {
		$res = $this->controller()->hide(0);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame(['error' => 'Not found'], $res->getData());

		$this->userFolder->method('getFirstNodeById')->with(999)->willReturn(null);
		$res2 = $this->controller()->hide(999);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res2->getStatus());
		$this->assertSame(['error' => 'Not found'], $res2->getData());
	}

	public function testHideRejectsRootFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/picture.jpg');

		$this->userFolder->method('getFirstNodeById')->with(10)->willReturn($file);
		$this->userFolder->method('getRelativePath')->with('/alice/files/picture.jpg')->willReturn('/picture.jpg');

		$res = $this->controller()->hide(10);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['error' => 'The root folder cannot be hidden'], $res->getData());
	}

	public function testHideAddsContainingFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Photos/2026/summer.jpg');

		$this->userFolder->method('getFirstNodeById')->with(42)->willReturn($file);
		$this->userFolder->method('getRelativePath')->with('/alice/files/Photos/2026/summer.jpg')->willReturn('/Photos/2026/summer.jpg');

		$this->service->expects($this->once())
			->method('addFolder')
			->with('alice', '/Photos/2026')
			->willReturn(['/Photos/2026']);

		$res = $this->controller()->hide(42);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['folder' => '/Photos/2026', 'folders' => ['/Photos/2026']], $res->getData());
	}

	public function testSetReplacesList(): void {
		$this->service->expects($this->once())
			->method('setFolders')
			->with('alice', ['/Photos', '/Archive'])
			->willReturn(['/Photos', '/Archive']);

		$res = $this->controller()->set(['/Photos', '/Archive']);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['folders' => ['/Photos', '/Archive']], $res->getData());
	}
}
