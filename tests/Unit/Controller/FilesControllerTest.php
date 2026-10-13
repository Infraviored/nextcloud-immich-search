<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\Controller\FilesController;
use OCA\Glimpse\Service\FileActions;
use OCA\Glimpse\Service\FileTransfer;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FilesControllerTest extends TestCase {
	public function testValidMoves(): void {
		$this->assertTrue(FilesController::validMoves([['fileId' => 3, 'from' => '/A']]));
		$this->assertTrue(FilesController::validMoves([['fileId' => '3', 'from' => '/A', 'fromName' => 'a.jpg']]));
		$this->assertFalse(FilesController::validMoves([]));
		$this->assertFalse(FilesController::validMoves(['x']));
		$this->assertFalse(FilesController::validMoves([['fileId' => 3]]));
		$this->assertFalse(FilesController::validMoves([['fileId' => 3, 'from' => ['/A']]]));
		$this->assertFalse(FilesController::validMoves([['fileId' => 'abc', 'from' => '/A']]));
		$this->assertFalse(FilesController::validMoves([['fileId' => 3, 'from' => '/A', 'fromName' => 5]]));
	}

	public function testMalformedUndoIsBadRequest(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->undo([['from' => '/A']])->getStatus());
	}

	public function testZipOverSizeCapIsBadRequest(): void {
		$big = $this->createMock(File::class);
		$big->method('getSize')->willReturn(FilesController::MAX_ZIP_BYTES / 2 + 1);
		$big->expects($this->never())->method('fopen');
		$actions = $this->createMock(FileActions::class);
		$actions->method('readable')->willReturn(['files' => [$big, $big], 'failed' => []]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($actions)->zip('1,2')->getStatus());
	}

	public function testZipTooManyFilesIsBadRequest(): void {
		$actions = $this->createMock(FileActions::class);
		$actions->expects($this->never())->method('readable');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($actions)->zip(implode(',', range(1, 2001)))->getStatus());
	}

	public function testZipWithNothingReadableIsBadRequest(): void {
		$broken = $this->createMock(File::class);
		$broken->method('getSize')->willReturn(10);
		$broken->method('fopen')->willThrowException(new \RuntimeException('gone'));
		$actions = $this->createMock(FileActions::class);
		$actions->method('readable')->willReturn(['files' => [$broken], 'failed' => []]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($actions)->zip('1')->getStatus());
	}

	private function controller(?FileActions $actions = null): FilesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($s, $p = []) => vsprintf($s, $p));
		return new FilesController($this->createMock(IRequest::class), $session, $this->createMock(FileTransfer::class),
			$actions ?? $this->createMock(FileActions::class), $l);
	}
}
