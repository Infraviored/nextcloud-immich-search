<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\ImmichClient;
use OCA\ImmichSearch\Service\ImmichException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private string $userId,
        private Config $config,
        private ImmichClient $immich,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** An empty key keeps the stored one, so the form never has to show it. */
    #[NoAdminRequired]
    public function save(string $url, string $apiKey = ''): JSONResponse {
        try {
            $key = $apiKey !== '' ? $apiKey : $this->config->getApiKey($this->userId);
            $user = $this->check($url, $key);
            $this->config->setUrl($this->userId, $url);
            if ($apiKey !== '') {
                $this->config->setApiKey($this->userId, $apiKey);
            }
            return new JSONResponse(['ok' => true, 'user' => $user]);
        } catch (\InvalidArgumentException|ImmichException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function test(string $url, string $apiKey = ''): JSONResponse {
        try {
            $key = $apiKey !== '' ? $apiKey : $this->config->getApiKey($this->userId);
            return new JSONResponse(['ok' => true, 'user' => $this->check($url, $key)]);
        } catch (\InvalidArgumentException|ImmichException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /** @return string the Immich account the key belongs to */
    private function check(string $url, string $key): string {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Die Adresse muss mit http:// oder https:// beginnen');
        }
        if ($key === '') {
            throw new \InvalidArgumentException('API-Schlüssel fehlt');
        }
        $me = $this->immich->jsonWith($url, $key, 'GET', '/users/me');
        return (string)($me['email'] ?? $me['name'] ?? '?');
    }
}
