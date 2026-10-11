<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\TagFilter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Tag 5 ("Holiday") is on files 10 (a photo bob can reach), 11 (a video bob can reach),
 * 12 (a PDF) and 13 (not shared with bob). Tag 6 is one bob cannot see.
 */
class TagFilterTest extends TestCase {
	private TagFilter $filter;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn ($uid) => $uid === 'bob' ? $user : null);

		$tag = $this->createMock(ISystemTag::class);
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTagsByIds')->willReturnCallback(function ($ids) use ($tag) {
			if ($ids !== ['5']) {
				throw new TagNotFoundException();
			}
			return ['5' => $tag];
		});
		$tags->method('canUserSeeTag')->willReturn(true);

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getObjectIdsForTags')->willReturn(['10', '11', '12', '13']);

		$home = $this->createMock(Folder::class);
		$files = [
			10 => $this->file(10, 'beach.jpg', 'image/jpeg', 100),
			11 => $this->file(11, 'surf.mp4', 'video/mp4', 200),
			12 => $this->file(12, 'ticket.pdf', 'application/pdf', 300),
		];
		$home->method('getFirstNodeById')->willReturnCallback(fn ($id) => $files[$id] ?? null);
		$home->method('getRelativePath')->willReturnCallback(fn ($p) => substr($p, strlen('/bob/files')));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$this->filter = new TagFilter($tags, $mapper, $users, $root);
	}

	public function testListsReachablePicturesNewestFirst(): void {
		$res = $this->filter->items('bob', '5');
		$this->assertSame([11, 10], array_column($res['items'], 'fileId'));
		$this->assertSame('/Holiday/surf.mp4', $res['items'][0]['path']);
		$this->assertFalse($res['truncated']);
	}

	public function testTypeFilter(): void {
		$this->assertSame([10], array_column($this->filter->items('bob', '5', 'IMAGE')['items'], 'fileId'));
	}

	public function testHiddenOrMalformedTagIsRefused(): void {
		foreach (['6', '5 OR 1', ''] as $id) {
			try {
				$this->filter->fileIds('bob', $id);
				$this->fail("tag '$id' accepted");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	private function file(int $id, string $name, string $mime, int $mtime): File {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn($id);
		$f->method('getName')->willReturn($name);
		$f->method('getMimetype')->willReturn($mime);
		$f->method('getMTime')->willReturn($mtime);
		$f->method('getPath')->willReturn('/bob/files/Holiday/' . $name);
		return $f;
	}
}
