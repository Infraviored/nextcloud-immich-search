<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\Controller\ApiController;
use OCA\Glimpse\Controller\PeopleController;
use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\FaceCropper;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\PeopleNames;
use OCA\Glimpse\Service\Permissions;
use OCA\Glimpse\Service\PrivateArea;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A private person, while locked, is as unknown as one the user cannot see at all:
 * no face, no sample pictures, no changes, no search by name or id.
 */
class PrivatePeopleTest extends TestCase {
	private const SECRET = '11111111-1111-4111-8111-111111111111';
	private const OPEN = '22222222-2222-4222-8222-222222222222';
	private const ASSET = '33333333-3333-4333-8333-333333333333';

	private IUserSession&MockObject $userSession;
	private PrivateArea&MockObject $area;
	private AssetSearch&MockObject $assets;
	private ImmichClient&MockObject $immich;
	private FaceCropper&MockObject $faces;
	private IL10N&MockObject $l;
	private bool $unlocked = false;
	private bool $assetVisible = true;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->area = $this->createMock(PrivateArea::class);
		$this->area->method('hiddenPeople')->willReturnCallback(fn () => $this->unlocked ? [] : [self::SECRET]);
		$this->area->method('filter')->willReturnArgument(1);
		$this->assets = $this->createMock(AssetSearch::class);
		$this->assets->method('maySee')->willReturnCallback(fn () => $this->assetVisible);
		$this->immich = $this->createMock(ImmichClient::class);
		$this->faces = $this->createMock(FaceCropper::class);
		$this->faces->method('face')->willReturn('jpeg');
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
	}

	private function people(): PeopleController {
		$index = $this->createMock(PeopleIndex::class);
		$index->method('forUser')->willReturn(['updated' => 1, 'people' => [
			self::SECRET => ['count' => 3, 'asset' => self::ASSET],
			self::OPEN => ['count' => 2, 'asset' => self::ASSET],
		]]);
		$permissions = $this->createMock(Permissions::class);
		$permissions->method('canManagePeople')->willReturn(true);
		return new PeopleController($this->createMock(IRequest::class), $this->userSession, $index, $this->faces, $this->immich,
			$permissions, $this->assets, $this->area, $this->createMock(Config::class), $this->l, $this->createMock(LoggerInterface::class));
	}

	public function testPrivatePersonIsNotFoundWhileLocked(): void {
		$this->immich->expects($this->never())->method('json');
		$this->faces->expects($this->exactly(2))->method('face');
		$c = $this->people();
		$this->assertSame(404, $c->face(self::SECRET)->getStatus());
		$this->assertSame(404, $c->samples(self::SECRET)->getStatus());
		$this->assertSame(404, $c->update(self::SECRET, 'New name')->getStatus());
		$this->assertSame(404, $c->merge(self::OPEN, [self::SECRET])->getStatus());
		// Past the checks (the response itself needs a server for its cache headers)
		$this->assertNotSame(404, $c->face(self::OPEN)->getStatus());

		$this->unlocked = true;
		$this->assertNotSame(404, $this->people()->face(self::SECRET)->getStatus());
	}

	public function testDefaultFaceIsCheckedLive(): void {
		$this->assetVisible = false; // the index is older than the share that was taken back
		$this->assertSame(404, $this->people()->face(self::OPEN)->getStatus());
	}

	private function api(): ApiController {
		$config = $this->createMock(Config::class);
		$config->method('isConfigured')->willReturn(true);
		$names = $this->createMock(PeopleNames::class);
		$names->method('split')->willReturnCallback(fn ($uid, $q) => str_starts_with($q, 'Secret ') ? [self::SECRET, substr($q, 7)] : [null, $q]);
		$names->method('visible')->willReturn([self::SECRET => ['name' => 'Secret', 'count' => 3]]);
		return new ApiController($this->createMock(IRequest::class), $this->userSession, $config, $this->assets, $names,
			$this->area, $this->immich, $this->l, $this->createMock(LoggerInterface::class));
	}

	public function testSearchIgnoresPrivatePersonWhileLocked(): void {
		$calls = [];
		$this->assets->method('search')->willReturnCallback(function ($uid, $q, $page, $size, $person) use (&$calls) {
			$calls[] = [$q, $person];
			return ['items' => [], 'nextPage' => null];
		});
		$this->assertNull($this->api()->search('Secret beach')->getData()['person'], 'name not recognised');
		$this->assertSame(['items' => [], 'nextPage' => null, 'person' => null], $this->api()->search('', 1, self::SECRET)->getData());
		$this->assertSame([['Secret beach', '']], $calls, 'the person filter never reached Immich');

		$this->unlocked = true;
		$this->assertSame(self::SECRET, $this->api()->search('Secret beach')->getData()['person']['id']);
	}
}
