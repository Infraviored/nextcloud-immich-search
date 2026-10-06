<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\ImmichException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImmichClientTest extends TestCase {
	private function client(int $status, string $body): ImmichClient {
		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn($status);
		$resp->method('getBody')->willReturn($body);
		$http = $this->createMock(IClient::class);
		$http->method('get')->willReturn($resp);
		$http->method('post')->willReturn($resp);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return new ImmichClient($this->createMock(Config::class), $service, $l, $this->createMock(LoggerInterface::class));
	}

	public static function answers(): array {
		return [
			'json' => [200, '{"a":1}', ['a' => 1]],
			'no content' => [204, '', []],
			'empty 200' => [200, '', []],
			'json scalar' => [200, 'true', []],
		];
	}

	#[DataProvider('answers')]
	public function testDecodes(int $status, string $body, array $expected): void {
		$this->assertSame($expected, $this->client($status, $body)->jsonWith('http://immich', 'k', 'POST', '/x'));
	}

	public function testAnswerThatIsNotJsonIsAnError(): void {
		$this->expectException(ImmichException::class);
		$this->client(200, '<html>Proxy login</html>')->jsonWith('http://immich', 'k', 'GET', '/search');
	}
}
