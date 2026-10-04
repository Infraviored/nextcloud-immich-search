<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCA\ImmichSearch\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * Per-user Immich connection. The API key is stored encrypted; the server URL
 * falls back to the instance default (app config "default_url").
 */
class Config {
    private const URL = 'url';
    private const KEY = 'api_key';
    private const DEFAULT_URL = 'default_url';

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
    ) {
    }

    public function getUrl(string $uid): string {
        $url = $this->config->getUserValue($uid, Application::APP_ID, self::URL, '');
        if ($url === '') {
            $url = $this->config->getAppValue(Application::APP_ID, self::DEFAULT_URL, '');
        }
        return rtrim($url, '/');
    }

    public function getApiKey(string $uid): string {
        $stored = $this->config->getUserValue($uid, Application::APP_ID, self::KEY, '');
        if ($stored === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($stored);
        } catch (\Exception) {
            return '';
        }
    }

    public function hasApiKey(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, self::KEY, '') !== '';
    }

    public function isConfigured(string $uid): bool {
        return $this->getUrl($uid) !== '' && $this->hasApiKey($uid);
    }

    public function setUrl(string $uid, string $url): void {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Die Adresse muss mit http:// oder https:// beginnen');
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::URL, $url);
    }

    public function setApiKey(string $uid, string $key): void {
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY, $this->crypto->encrypt(trim($key)));
    }
}
