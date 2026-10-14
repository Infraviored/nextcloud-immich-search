<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Controller;

use OCA\Glimpse\Controller\PeopleController;
use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\FaceCropper;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\Permissions;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\SensitiveContent;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Not Tanja": TANJA's faces in the chosen pictures go elsewhere, other faces stay.
 * Picture A1 shows Tanja (face fa) and Gabi (face fb); A2 shows Tanja (fc); A3 is one
 * the user may not see. SECRET is a private person, locked.
 */
class MoveFacesTest extends TestCase {
	private const TANJA = '11111111-1111-4111-8111-111111111111';
	private const GABI = '22222222-2222-4222-8222-222222222222';
	private const SECRET = '44444444-4444-4444-8444-444444444444';
	private const A1 = 'aaaaaaaa-1111-4111-8111-111111111111';
	private const A2 = 'aaaaaaaa-2222-4222-8222-222222222222';
	private const A3 = 'aaaaaaaa-3333-4333-8333-333333333333';
	private const NEW = '55555555-5555-4555-8555-555555555555';

	private ImmichClient&MockObject $immich;
	private PeopleIndex&MockObject $index;
	/** @var list<string> writes sent to Immich: "METHOD endpoint body" */
	private array $writes = [];

	protected function setUp(): void {
		$this->immich = $this->createMock(ImmichClient::class);
		$this->immich->method('json')->willReturnCallback(function ($m, $e, $b = null) {
			if ($m === 'GET') {
				return match ($e) {
					'/faces?id=' . self::A1 => [['id' => 'fa', 'person' => ['id' => self::TANJA]], ['id' => 'fb', 'person' => ['id' => self::GABI]]],
					'/faces?id=' . self::A2 => [['id' => 'fc', 'person' => ['id' => self::TANJA]]],
					default => [],
				};
			}
			$this->writes[] = "$m $e " . json_encode($b);
			return $m === 'POST' ? ['id' => self::NEW] : [];
		});
		$this->index = $this->createMock(PeopleIndex::class);
		$this->index->method('forUser')->willReturn(['updated' => 1, 'people' => [
			self::TANJA => ['count' => 9, 'asset' => self::A1],
			self::GABI => ['count' => 4, 'asset' => self::A1],
			self::SECRET => ['count' => 1, 'asset' => self::A2],
		]]);
	}

	private function controller(): PeopleController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$area = $this->createMock(PrivateArea::class);
		$area->method('hiddenPeople')->willReturn([self::SECRET]);
		$assets = $this->createMock(AssetSearch::class);
		$assets->method('maySee')->willReturnCallback(fn ($uid, $id) => $id !== self::A3);
		$permissions = $this->createMock(Permissions::class);
		$permissions->method('canManagePeople')->willReturn(true);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return new PeopleController($this->createMock(IRequest::class), $session, $this->index, $this->createMock(FaceCropper::class),
			$this->immich, $permissions, $assets, $area, $this->sensitive(), $this->createMock(Config::class), $l, $this->createMock(LoggerInterface::class));
	}

	public function testOnlyThePersonsFacesMoveToAnother(): void {
		$this->index->expects($this->once())->method('applyMove')->with('u', self::TANJA, self::GABI, [self::A1, self::A2]);
		$res = $this->controller()->moveFaces(self::TANJA, [self::A1, self::A2, self::A3], self::GABI)->getData();
		$this->assertSame([self::A1, self::A2], $res['moved']);
		$this->assertSame([self::A3], $res['failed'], 'a picture the user may not see is left alone');
		$this->assertSame(['PUT /faces/' . self::GABI . ' {"id":"fa"}', 'PUT /faces/' . self::GABI . ' {"id":"fc"}'], $this->writes);
	}

	public function testUnnamedMakesOnePersonForAll(): void {
		$res = $this->controller()->moveFaces(self::TANJA, [self::A1, self::A2], '', '', true)->getData();
		$this->assertSame(self::NEW, $res['personId']);
		$this->assertSame(['POST /people {"name":""}', 'PUT /faces/' . self::NEW . ' {"id":"fa"}', 'PUT /faces/' . self::NEW . ' {"id":"fc"}'], $this->writes);
	}

	public function testNoNewPersonWithoutAFaceToMove(): void {
		$res = $this->controller()->moveFaces(self::GABI, [self::A2], '', 'Lena')->getData();
		$this->assertSame([], $res['moved']);
		$this->assertSame([], $this->writes);
	}

	public function testNotAFaceDeletesOnlyThoseFaces(): void {
		$this->index->expects($this->once())->method('applyMove')->with('u', self::TANJA, null, [self::A1]);
		$this->controller()->moveFaces(self::TANJA, [self::A1], '', '', false, true);
		$this->assertSame(['DELETE /faces/fa {"force":false}'], $this->writes);
	}

	public function testPrivateOrSamePersonIsRefused(): void {
		$c = $this->controller();
		$this->assertSame(404, $c->moveFaces(self::TANJA, [self::A1], self::SECRET)->getStatus(), 'private target while locked');
		$this->assertSame(404, $c->moveFaces(self::SECRET, [self::A2], self::GABI)->getStatus(), 'private source while locked');
		$this->assertSame(400, $c->moveFaces(self::TANJA, [self::A1], self::TANJA)->getStatus());
		$this->assertSame(400, $c->moveFaces(self::TANJA, [], self::GABI)->getStatus());
		$this->assertSame([], $this->writes);
	}

	/** Sensitive content off: items pass unchanged. */
	private function sensitive(): SensitiveContent {
		$sensitive = $this->createMock(SensitiveContent::class);
		$sensitive->method('apply')->willReturnArgument(1);
		$sensitive->method('mode')->willReturn('all');
		return $sensitive;
	}
}
