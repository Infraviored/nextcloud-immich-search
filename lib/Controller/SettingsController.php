<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\ImmichClient;
use OCA\ImmichSearch\Service\ImmichException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/** Admin only (no #[NoAdminRequired]): the key gives access to everyone's index. */
class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private Config $config,
        private ImmichClient $immich,
        private IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** An empty key keeps the stored one, so the form never has to show it. */
    public function save(string $url, string $apiKey = '', string $pathPattern = Config::DEFAULT_PATH_PATTERN): JSONResponse {
        return $this->respond(function () use ($url, $apiKey, $pathPattern) {
            $pattern = $this->validPattern($pathPattern);
            $user = $this->check($url, $apiKey);
            $this->config->setUrl($url);
            $this->config->setPathPattern($pattern);
            if ($apiKey !== '') {
                $this->config->setApiKey($apiKey);
            }
            return $user;
        });
    }

    public function test(string $url, string $apiKey = ''): JSONResponse {
        return $this->respond(fn () => $this->check($url, $apiKey));
    }

    private function respond(callable $fn): JSONResponse {
        try {
            return new JSONResponse(['ok' => true, 'user' => $fn()]);
        } catch (\InvalidArgumentException|ImmichException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /** @return string the Immich account the key belongs to */
    private function check(string $url, string $apiKey): string {
        try {
            $url = Config::normalizeUrl($url);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException($this->l->t('The address must start with http:// or https://'));
        }
        $key = $apiKey !== '' ? $apiKey : $this->config->getApiKey();
        if ($key === '') {
            throw new \InvalidArgumentException($this->l->t('API key is missing'));
        }
        $me = $this->immich->jsonWith($url, $key, 'GET', '/users/me');
        return (string)($me['email'] ?? $me['name'] ?? '?');
    }

    private function validPattern(string $pattern): string {
        try {
            return Config::normalizePathPattern($pattern);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException($this->l->t('The path must start with / and contain {user} exactly once'));
        }
    }
}
