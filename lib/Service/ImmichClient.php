<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\Http\Client\IClientService;

/**
 * Thin client for the Immich REST API, acting with one user's API key.
 */
class ImmichClient {
    private const TIMEOUT = 30;

    public function __construct(
        private Config $config,
        private IClientService $clientService,
    ) {
    }

    /** JSON request. Throws ImmichException on transport errors and non-2xx answers. */
    public function json(string $uid, string $method, string $endpoint, ?array $body = null): array {
        return $this->jsonWith($this->config->getUrl($uid), $this->config->getApiKey($uid), $method, $endpoint, $body);
    }

    /** Same as json(), with explicit credentials (used to test settings before saving). */
    public function jsonWith(string $url, string $key, string $method, string $endpoint, ?array $body = null): array {
        $options = $this->options($key, ['Accept' => 'application/json']);
        if ($body !== null) {
            $options['body'] = json_encode($body);
            $options['headers']['Content-Type'] = 'application/json';
        }
        $resp = $this->send($url, $method, $endpoint, $options);
        $decoded = json_decode((string)$resp->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{body: string, type: string} */
    public function binary(string $uid, string $endpoint): array {
        $resp = $this->send($this->config->getUrl($uid), 'GET', $endpoint, $this->options($this->config->getApiKey($uid), []));
        return ['body' => (string)$resp->getBody(), 'type' => $resp->getHeader('Content-Type') ?: 'application/octet-stream'];
    }

    private function options(string $key, array $headers): array {
        return [
            'headers' => ['x-api-key' => $key] + $headers,
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ];
    }

    private function send(string $url, string $method, string $endpoint, array $options): \OCP\Http\Client\IResponse {
        if ($url === '') {
            throw new ImmichException('Immich ist nicht eingerichtet', 412);
        }
        $client = $this->clientService->newClient();
        try {
            $resp = match ($method) {
                'GET' => $client->get($url . '/api' . $endpoint, $options),
                'POST' => $client->post($url . '/api' . $endpoint, $options),
            };
        } catch (\Throwable $e) {
            throw new ImmichException('Immich nicht erreichbar: ' . $e->getMessage(), 502, $e);
        }
        $status = $resp->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new ImmichException('Immich lehnt den API-Schlüssel ab (HTTP ' . $status . ')', 502);
        }
        if ($status < 200 || $status >= 300) {
            throw new ImmichException('Immich antwortet mit HTTP ' . $status, 502);
        }
        return $resp;
    }
}
