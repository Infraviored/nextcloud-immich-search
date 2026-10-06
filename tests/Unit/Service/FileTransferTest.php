<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\BackgroundJob\ScanLibraries;
use OCA\Glimpse\Service\FileTransfer;
use OCA\Glimpse\Service\MoveTracker;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileTransferTest extends TestCase {
	private Folder&MockObject $home;
	private Folder&MockObject $dest;
	private IJobList&MockObject $jobs;
	private FileTransfer $transfer;

	protected function setUp(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$this->dest = $this->createMock(Folder::class);
		$this->dest->method('getId')->willReturn(2);
		$this->dest->method('isCreatable')->willReturn(true);
		$this->dest->method('getPath')->willReturn('/alice/files/Target');
		$this->dest->method('getOwner')->willReturn($alice);
		$this->dest->method('getNonExistingName')->willReturn('a (2).jpg');
		$src = $this->createMock(Folder::class);
		$src->method('getId')->willReturn(1);
		$src->method('getPath')->willReturn('/alice/files/Photos');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('a.jpg');
		$file->method('getParent')->willReturn($src);
		$file->method('getOwner')->willReturn($alice);
		$this->home = $this->createMock(Folder::class);
		$this->home->method('getFirstNodeById')->willReturn($file);
		$this->home->method('getRelativePath')->willReturn('/Photos');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->home);
		$this->jobs = $this->createMock(IJobList::class);
		$tracker = $this->createMock(MoveTracker::class);
		$tracker->method('isEnabled')->willReturn(true);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->transfer = new FileTransfer($root, $this->jobs, $tracker, $l, $this->createMock(LoggerInterface::class));
	}

	public function testMoveRecordsOriginalNameAndQueuesNoScan(): void {
		$this->home->method('get')->willReturn($this->dest);
		$this->jobs->expects($this->never())->method('add');
		$r = $this->transfer->transfer('alice', [10], '/Target', false);
		$this->assertSame([['fileId' => 10, 'from' => '/Photos', 'fromName' => 'a.jpg']], $r['moved']);
	}

	public function testCopyQueuesOneScanForTheOwner(): void {
		$this->home->method('get')->willReturn($this->dest);
		$this->jobs->method('has')->with(ScanLibraries::class, 'alice')->willReturn(false);
		$this->jobs->expects($this->once())->method('add')->with(ScanLibraries::class, 'alice');
		$this->transfer->transfer('alice', [10], '/Target', true);
	}

	public function testCopyDoesNotQueueTwice(): void {
		$this->home->method('get')->willReturn($this->dest);
		$this->jobs->method('has')->willReturn(true);
		$this->jobs->expects($this->never())->method('add');
		$this->transfer->transfer('alice', [10], '/Target', true);
	}

	public function testForbiddenTargetIsBadRequest(): void {
		$this->home->method('get')->willThrowException(new NotPermittedException());
		$this->expectException(\InvalidArgumentException::class);
		$this->transfer->transfer('alice', [10], '/../x', false);
	}
}
