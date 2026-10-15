<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\BackgroundJob\RebuildSensitive;
use OCA\Glimpse\Controller\SensitiveController;
use OCA\Glimpse\Service\SensitiveContent;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SensitiveControllerTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private SensitiveContent&MockObject $sensitive;
	private IJobList&MockObject $jobs;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->sensitive = $this->createMock(SensitiveContent::class);
		$this->jobs = $this->createMock(IJobList::class);
	}

	private function controller(): SensitiveController {
		return new SensitiveController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->sensitive,
			$this->jobs,
		);
	}

	public function testMarkRejectsWhenDisabled(): void {
		$this->sensitive->method('isEnabled')->with('alice')->willReturn(false);

		$res = $this->controller()->mark(['1' => true]);
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		$this->assertSame(['error' => 'off'], $res->getData());
	}

	public function testMarkQueuesRebuildSensitiveIfNotQueued(): void {
		$this->sensitive->method('isEnabled')->with('alice')->willReturn(true);
		$this->sensitive->method('mark')->with('alice', ['1' => true])->willReturn([
			'previous' => [1 => null],
			'sensitive' => [1 => true],
		]);
		$this->jobs->method('has')->with(RebuildSensitive::class, ['uid' => 'alice'])->willReturn(false);
		$this->jobs->expects($this->once())
			->method('add')
			->with(RebuildSensitive::class, ['uid' => 'alice']);

		$res = $this->controller()->mark(['1' => true]);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$this->assertEquals((object)[1 => null], $data['previous']);
		$this->assertEquals((object)[1 => true], $data['sensitive']);
	}

	public function testMarkDoesNotQueueIfAlreadyQueued(): void {
		$this->sensitive->method('isEnabled')->with('alice')->willReturn(true);
		$this->sensitive->method('mark')->with('alice', ['1' => true])->willReturn([
			'previous' => [1 => null],
			'sensitive' => [1 => true],
		]);
		$this->jobs->method('has')->with(RebuildSensitive::class, ['uid' => 'alice'])->willReturn(true);
		$this->jobs->expects($this->never())->method('add');

		$res = $this->controller()->mark(['1' => true]);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
	}
}
