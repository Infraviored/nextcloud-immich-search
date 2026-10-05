<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Settings;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\Config;
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
		$this->initialState->provideInitialState('admin', [
			'url' => $this->config->getUrl(),
			'hasKey' => $this->config->hasApiKey(),
			'pathPattern' => $this->config->getPathPattern(),
			'faceMode' => $this->config->getFaceMode(),
			'minSize' => $this->config->getMinSize(),
		]);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
