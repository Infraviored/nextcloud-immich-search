<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\AppInfo;

use OCA\ImmichSearch\Search\ImmichProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'immich_search';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Global search bar on every page, and the search in the Android app.
        $context->registerSearchProvider(ImmichProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}
