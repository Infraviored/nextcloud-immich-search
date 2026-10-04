<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Listener;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * In the Files app: the "Similar pictures" action and the sidebar tab with the people in a
 * picture (js/immich_search-files.mjs).
 *
 * @template-implements IEventListener<Event>
 */
class LoadFilesScripts implements IEventListener {
	public function __construct(
		private Config $config,
	) {
	}

	public function handle(Event $event): void {
		if ($this->config->isConfigured()) {
			Util::addScript(Application::APP_ID, Application::APP_ID . '-files');
		}
	}
}
