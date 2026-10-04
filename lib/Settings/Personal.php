<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Settings;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
    public function __construct(
        private ?string $userId,
        private Config $config,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('settings', [
            'url' => $this->config->getUrl((string)$this->userId),
            'hasKey' => $this->config->hasApiKey((string)$this->userId),
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
