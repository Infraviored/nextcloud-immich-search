<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\Permissions;
use OCA\ImmichSearch\Service\UserPrefs;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private Config $config,
		private Permissions $permissions,
		private UserPrefs $prefs,
		private IInitialState $initialState,
		private IEventDispatcher $events,
		private IAppManager $appManager,
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
		$isAdmin = $this->permissions->isAdmin($this->uid());
		$this->initialState->provideInitialState('page', [
			'configured' => $this->config->isConfigured(),
			'isAdmin' => $isAdmin,
			'canManagePeople' => $this->permissions->canManagePeople($this->uid()),
			// Albums live in the Photos app; the action is offered only if it is there.
			'photosEnabled' => $this->appManager->isEnabledForUser('photos'),
		]);
		$this->initialState->provideInitialState('prefs', $this->prefs->all($this->uid()));
		if ($isAdmin) {
			$this->provideAdminState();
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}

	/** Same data the admin settings section gets, for the in-app settings dialog. */
	private function provideAdminState(): void {
		$this->initialState->provideInitialState('admin', [
			'url' => $this->config->getUrl(),
			'hasKey' => $this->config->hasApiKey(),
			'pathPattern' => $this->config->getPathPattern(),
			'faceMode' => $this->config->getFaceMode(),
			'minSize' => $this->config->getMinSize(),
		]);
	}

	/** Resolved per call: with basic auth the user is only logged in after the controller is built. */
	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
