<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private Config $config,
        private IInitialState $initialState,
        private IEventDispatcher $events,
        private IURLGenerator $url,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // Nextcloud's own viewer opens the real file: zoom, video playback, sidebar, download.
        if (class_exists(\OCA\Viewer\Event\LoadViewer::class)) {
            $this->events->dispatchTyped(new \OCA\Viewer\Event\LoadViewer());
        }
        $this->initialState->provideInitialState('page', [
            'configured' => $this->config->isConfigured(),
            'settingsUrl' => $this->url->linkToRoute('settings.AdminSettings.index', ['section' => 'additional']),
        ]);
        Util::addScript(Application::APP_ID, 'search');
        Util::addStyle(Application::APP_ID, 'search');
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
