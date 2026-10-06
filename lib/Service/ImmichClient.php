<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Thin client for the Immich REST API with the instance's service key.
 */
class ImmichClient {
	private const TIMEOUT = 30;

	public function __construct(
		private Config $config,
		private IClientService $clientService,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/** JSON request. Throws ImmichException on transport errors and non-2xx answers. */
	public function json(string $method, string $endpoint, ?array $body = null): array {
		return $this->jsonWith($this->config->getUrl(), $this->config->getApiKey(), $method, $endpoint, $body);
	}

	/** Same as json(), with explicit credentials (used to test settings before saving). */
	public function jsonWith(string $url, string $key, string $method, string $endpoint, ?array $body = null): array {
		$options = $this->options($key, ['Accept' => 'application/json']);
		if ($body !== null) {
			$options['body'] = json_encode($body);
			$options['headers']['Content-Type'] = 'application/json';
		}
		return $this->decode($this->send($url, $method, $endpoint, $options), $method, $endpoint);
	}

	/**
	 * Body of a 2xx answer. Empty (204, or Immich answering a command with nothing) is an
	 * empty result; anything else must be JSON. An HTML page from a proxy in front of
	 * Immich would otherwise pass as "no hits" and, say, a search as "nothing there".
	 */
	private function decode(IResponse $resp, string $method, string $endpoint): array {
		$body = trim((string)$resp->getBody());
		if ($resp->getStatusCode() === 204 || $body === '') {
			return [];
		}
		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->logger->warning("Immich $method $endpoint: HTTP " . $resp->getStatusCode() . ' but no JSON: ' . json_encode(substr($body, 0, 200)), ['app' => 'glimpse']);
			throw new ImmichException($this->l->t('Immich gave an answer that could not be read'), 502);
		}
		return is_array($decoded) ? $decoded : [];
	}

	/** @return array{body: string, type: string} */
	public function binary(string $endpoint): array {
		$resp = $this->send($this->config->getUrl(), 'GET', $endpoint, $this->options($this->config->getApiKey(), []));
		return ['body' => (string)$resp->getBody(), 'type' => $resp->getHeader('Content-Type') ?: 'application/octet-stream'];
	}

	private function options(string $key, array $headers): array {
		return [
			'headers' => ['x-api-key' => $key] + $headers,
			'timeout' => self::TIMEOUT,
			'http_errors' => false,
		];
	}

	private function send(string $url, string $method, string $endpoint, array $options): IResponse {
		if ($url === '') {
			throw new ImmichException($this->l->t('Glimpse is not set up yet'), 412);
		}
		$client = $this->clientService->newClient();
		try {
			$resp = match ($method) {
				'GET' => $client->get($url . '/api' . $endpoint, $options),
				'POST' => $client->post($url . '/api' . $endpoint, $options),
				'PUT' => $client->put($url . '/api' . $endpoint, $options),
				'DELETE' => $client->delete($url . '/api' . $endpoint, $options),
			};
		} catch (\Throwable $e) {
			throw new ImmichException($this->l->t('Immich is not reachable'), 502, $e);
		}
		$status = $resp->getStatusCode();
		if ($status === 401 || $status === 403) {
			throw new ImmichException($this->l->t('Immich rejected the API key (HTTP %s)', [$status]), 502);
		}
		if ($status === 404) {
			throw new ImmichException($this->l->t('Not found'), 404);
		}
		if ($status < 200 || $status >= 300) {
			// Immich explains rejected requests in "message"; log it, it names the bad field.
			$why = json_decode((string)$resp->getBody(), true)['message'] ?? '';
			$this->logger->warning("Immich $method $endpoint: HTTP $status " . json_encode($why), ['app' => 'glimpse']);
			throw new ImmichException($this->l->t('Immich answered with HTTP %s', [$status]), 502);
		}
		return $resp;
	}
}
