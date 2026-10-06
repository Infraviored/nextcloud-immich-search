<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\FileActions;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\ITagManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileActionsTest extends TestCase {
	private Folder&MockObject $home;
	private ISystemTagManager&MockObject $tags;
	private ISystemTagObjectMapper&MockObject $mapper;
	private FileActions $actions;
	/** @var array<int, File> */
	private array $nodes = [];

	protected function setUp(): void {
		$this->home = $this->createMock(Folder::class);
		$this->home->method('getFirstNodeById')->willReturnCallback(fn ($id) => $this->nodes[$id] ?? null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->home);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$this->tags = $this->createMock(ISystemTagManager::class);
		$this->tags->method('canUserSeeTag')->willReturn(true);
		$this->tags->method('canUserAssignTag')->willReturn(true);
		$this->tags->method('canUserCreateTag')->willReturn(true);
		$this->mapper = $this->createMock(ISystemTagObjectMapper::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($s, $p = []) => vsprintf($s, $p));
		$this->actions = new FileActions($root, $users, $this->createMock(ITagManager::class), $this->tags, $this->mapper, $l, $this->createMock(LoggerInterface::class));
	}

	private function tag(string $id, string $name): ISystemTag&MockObject {
		$t = $this->createMock(ISystemTag::class);
		$t->method('getId')->willReturn($id);
		$t->method('getName')->willReturn($name);
		return $t;
	}

	private function file(int $id, string $name, int $perms = Constants::PERMISSION_ALL): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn($id);
		$f->method('getName')->willReturn($name);
		$f->method('getPermissions')->willReturn($perms);
		return $this->nodes[$id] = $f;
	}

	public function testFindTagIgnoresCaseAndBlanks(): void {
		$tags = [$this->tag('1', 'Urlaub'), $this->tag('2', 'Ärger')];
		$this->assertSame('1', FileActions::findTag($tags, ' urlaub ')?->getId());
		$this->assertSame('2', FileActions::findTag($tags, 'ÄRGER')?->getId());
		$this->assertNull(FileActions::findTag($tags, 'Urlaub 2026'));
	}

	public function testNewTagNameInOtherCaseReusesExisting(): void {
		$this->file(10, 'a.jpg');
		$this->tags->method('getAllTags')->willReturn([$this->tag('7', 'Beach')]);
		$this->tags->expects($this->never())->method('createTag');
		$this->tags->method('getTagsByIds')->with(['7'])->willReturn(['7' => $this->tag('7', 'Beach')]);
		$this->mapper->expects($this->once())->method('assignTags')->with('10', 'files', ['7']);
		$this->assertSame(1, $this->actions->tag('alice', [10], [], ['beach'])['done']);
	}

	public function testUnknownTagIdIsBadRequest(): void {
		$this->tags->method('getTagsByIds')->willThrowException(new TagNotFoundException());
		$this->expectException(\InvalidArgumentException::class);
		$this->actions->tag('alice', [10], ['99'], []);
	}

	public function testNonNumericTagIdIsBadRequest(): void {
		$this->tags->expects($this->never())->method('getTagsByIds');
		$this->expectException(\InvalidArgumentException::class);
		$this->actions->tag('alice', [10], ['x'], []);
	}

	public function testReadOnlyFileIsNotTagged(): void {
		$this->file(10, 'mine.jpg');
		$this->file(11, 'shared-read-only.jpg', Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
		$this->tags->method('getTagsByIds')->willReturn(['7' => $this->tag('7', 'Beach')]);
		$this->mapper->expects($this->once())->method('assignTags')->with('10');
		$r = $this->actions->tag('alice', [10, 11], ['7'], []);
		$this->assertSame(1, $r['done']);
		$this->assertSame(11, $r['failed'][0]['fileId']);
	}

	public function testUndoName(): void {
		$this->assertSame('a.jpg', FileActions::undoName('a.jpg', 'a (2).jpg'));
		$this->assertSame('a (2).jpg', FileActions::undoName(null, 'a (2).jpg'));
		$this->assertSame('a (2).jpg', FileActions::undoName('../x.jpg', 'a (2).jpg'));
		$this->assertSame('a (2).jpg', FileActions::undoName('', 'a (2).jpg'));
		$this->assertSame('a (2).jpg', FileActions::undoName('..', 'a (2).jpg'));
	}

	public function testUndoRestoresOriginalName(): void {
		$origin = $this->createMock(Folder::class);
		$origin->method('getId')->willReturn(1);
		$origin->method('isCreatable')->willReturn(true);
		$origin->method('getPath')->willReturn('/alice/files/Photos');
		// "a.jpg" is free again in the original folder
		$origin->method('getNonExistingName')->willReturnArgument(0);
		$this->home->method('get')->with('/Photos')->willReturn($origin);
		$elsewhere = $this->createMock(Folder::class);
		$elsewhere->method('getId')->willReturn(2);
		$f = $this->file(10, 'a (2).jpg');
		$f->method('getParent')->willReturn($elsewhere);
		$f->expects($this->once())->method('move')->with('/alice/files/Photos/a.jpg');
		$this->actions->undoMove('alice', [['fileId' => 10, 'from' => '/Photos', 'fromName' => 'a.jpg']]);
	}

	public function testUndoWithoutFromNameKeepsCurrentName(): void {
		$origin = $this->createMock(Folder::class);
		$origin->method('getId')->willReturn(1);
		$origin->method('isCreatable')->willReturn(true);
		$origin->method('getPath')->willReturn('/alice/files/Photos');
		$origin->method('getNonExistingName')->willReturnCallback(fn ($n) => $n === 'a (2).jpg' ? 'a (3).jpg' : $n);
		$this->home->method('get')->willReturn($origin);
		$elsewhere = $this->createMock(Folder::class);
		$elsewhere->method('getId')->willReturn(2);
		$f = $this->file(10, 'a (2).jpg');
		$f->method('getParent')->willReturn($elsewhere);
		$f->expects($this->once())->method('move')->with('/alice/files/Photos/a (3).jpg');
		$this->actions->undoMove('alice', [['fileId' => 10, 'from' => '/Photos']]);
	}
}
