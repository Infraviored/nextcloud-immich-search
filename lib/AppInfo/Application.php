<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\AppInfo;

use OCA\ImmichSearch\Dashboard\MemoriesWidget;
use OCA\ImmichSearch\Listener\LoadFilesScripts;
use OCA\ImmichSearch\Listener\TrackMoves;
use OCA\ImmichSearch\Search\ImmichProvider;
use OCA\ImmichSearch\Search\PeopleProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'immich_search';

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
