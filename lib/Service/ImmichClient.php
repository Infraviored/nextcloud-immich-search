<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IL10N;

/**
 * Thin client for the Immich REST API with the instance's service key.
 */
class ImmichClient {
    private const TIMEOUT = 30;

    public function __construct(
        private Config $config,
        private IClientService $clientService,
        private IL10N $l,
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
        $decoded = json_decode((string)$this->send($url, $method, $endpoint, $options)->getBody(), true);
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
            throw new ImmichException($this->l->t('Photo search is not set up yet'), 412);
        }
        $client = $this->clientService->newClient();
        try {
            $resp = match ($method) {
                'GET' => $client->get($url . '/api' . $endpoint, $options),
                'POST' => $client->post($url . '/api' . $endpoint, $options),
                'PUT' => $client->put($url . '/api' . $endpoint, $options),
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
            throw new ImmichException($this->l->t('Immich answered with HTTP %s', [$status]), 502);
        }
        return $resp;
    }
}
