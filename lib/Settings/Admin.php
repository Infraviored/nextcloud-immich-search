<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Settings;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
    public function __construct(
        private Config $config,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('settings', [
            'url' => $this->config->getUrl(),
            'hasKey' => $this->config->hasApiKey(),
            'pathPattern' => $this->config->getPathPattern(),
        ]);
        Util::addScript(Application::APP_ID, 'settings');
        return new TemplateResponse(Application::APP_ID, 'settings');
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 50;
    }
}
