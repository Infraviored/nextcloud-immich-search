<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\AppInfo;

use OCA\Glimpse\Dashboard\MemoriesWidget;
use OCA\Glimpse\Listener\LoadFilesScripts;
use OCA\Glimpse\Listener\TrackMoves;
use OCA\Glimpse\Search\ImmichProvider;
use OCA\Glimpse\Search\PeopleProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'glimpse';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Global search bar on every page, and the search in the Android app.
		$context->registerSearchProvider(ImmichProvider::class);
		$context->registerSearchProvider(PeopleProvider::class);
		$context->registerDashboardWidget(MemoriesWidget::class);
		// Files app: "similar pictures" action and the sidebar tab
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, LoadFilesScripts::class);
		$context->registerEventListener(\OCA\Files\Event\LoadSidebar::class, LoadFilesScripts::class);
		$context->registerEventListener(BeforeNodeRenamedEvent::class, TrackMoves::class);
		$context->registerEventListener(NodeRenamedEvent::class, TrackMoves::class);
	}

	public function boot(IBootContext $context): void {
	}
}
