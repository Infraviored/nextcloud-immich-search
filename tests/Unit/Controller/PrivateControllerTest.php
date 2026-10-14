<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\Controller\PrefsController;
use OCA\Glimpse\Controller\PrivateController;
use OCA\Glimpse\Service\IndexAdmin;
use OCA\Glimpse\Service\Permissions;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\UserPrefs;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** While locked, the private area neither tells what is in it nor lets it be emptied or switched off. */
class PrivateControllerTest extends TestCase {
	private const P1 = '11111111-1111-4111-8111-111111111111';

	private PrivateArea&MockObject $area;
	private UserPrefs&MockObject $prefs;
	private IUserSession&MockObject $userSession;
	private array $session = [];
	private bool $unlocked = false;
	private array $data = ['folders' => ['/Private'], 'people' => [self::P1], 'files' => [7, 8], 'assets' => []];

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->area = $this->createMock(PrivateArea::class);
		$this->area->method('isUnlocked')->willReturnCallback(fn () => $this->unlocked);
		$this->area->method('get')->willReturnCallback(fn () => $this->data);
		$this->prefs = $this->createMock(UserPrefs::class);
		$this->prefs->method('get')->willReturn(true);
	}

	private function controller(): PrivateController {
		return new PrivateController($this->createMock(IRequest::class), $this->userSession, $this->area, $this->prefs);
	}

	private function prefsController(): PrefsController {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(fn ($k) => $this->session[$k] ?? null);
		return new PrefsController($this->createMock(IRequest::class), $this->userSession, $this->prefs,
			$this->createMock(IndexAdmin::class), $this->createMock(Permissions::class), $this->area, $session);
	}

	public function testLockedShowsOnlyCounts(): void {
		$data = $this->controller()->get()->getData();
		$this->assertSame([], $data['folders']);
		$this->assertSame([], $data['people']);
		$this->assertSame([2, 1, 1, true, false], [$data['files'], $data['folderCount'], $data['peopleCount'], $data['locked'], $data['unlocked']]);

		$this->unlocked = true;
		$data = $this->controller()->get()->getData();
		$this->assertSame(['/Private'], $data['folders']);
		$this->assertSame([self::P1], $data['people']);
		$this->assertFalse($data['locked']);
	}

	public function testLockedListsAreOnlyAddedTo(): void {
		$this->area->expects($this->once())->method('setLists')->with('u', ['/New'], [], true)->willReturn($this->data);
		$data = $this->controller()->setLists(['/New'], [])->getData();
		$this->assertSame([], $data['folders'], 'the answer does not list them either');
	}

	public function testUnlockedListsAreReplaced(): void {
		$this->unlocked = true;
		$this->area->expects($this->once())->method('setLists')->with('u', [], [], false)->willReturn($this->data);
		$this->controller()->setLists([], []);
	}

	public function testFilesCannotBeUnmarkedWhileLocked(): void {
		$this->area->expects($this->never())->method('setFiles');
		$this->assertSame(403, $this->controller()->setFiles([7], false)->getStatus());
	}

	public function testFilesCanBeMarkedWhileLocked(): void {
		$this->area->expects($this->once())->method('setFiles')->willReturn(['files' => [7, 8, 9]] + $this->data);
		$this->assertSame(['files' => 3], $this->controller()->setFiles([9], true)->getData());
	}

	public function testPasswordPromptCannotBeTurnedOffWithoutPassword(): void {
		$this->prefs->expects($this->never())->method('setAll');
		$this->assertSame(403, $this->prefsController()->save(['private_password' => false])->getStatus());
		$this->session['last-password-confirm'] = time() - 3600;
		$this->assertSame(403, $this->prefsController()->save(['private_password' => false, 'show_videos' => true])->getStatus());
	}

	public function testPasswordPromptCanBeTurnedOffAfterConfirmingOrUnlocking(): void {
		$this->prefs->expects($this->exactly(2))->method('setAll');
		$this->session['last-password-confirm'] = time() - 60;
		$this->assertSame(200, $this->prefsController()->save(['private_password' => false])->getStatus());
		$this->session = [];
		$this->unlocked = true;
		$this->assertSame(200, $this->prefsController()->save(['private_password' => false])->getStatus());
	}

	public function testOtherPrefsNeedNoPassword(): void {
		$this->prefs->expects($this->exactly(2))->method('setAll');
		$this->assertSame(200, $this->prefsController()->save(['show_videos' => false])->getStatus());
		$this->assertSame(200, $this->prefsController()->save(['private_password' => true])->getStatus());
	}
}
