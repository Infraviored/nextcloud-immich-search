<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\IndexAdmin;
use OCA\Glimpse\Service\Permissions;
use OCA\Glimpse\Service\UserPrefs;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** The current user's own settings, and the status they may see. */
class PrefsController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private UserPrefs $prefs,
		private IndexAdmin $index,
		private Permissions $permissions,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function get(): JSONResponse {
		return new JSONResponse($this->prefs->all($this->uid()));
	}

	#[NoAdminRequired]
	public function save(array $prefs = []): JSONResponse {
		try {
			foreach ($prefs as $key => $value) {
				$this->prefs->set($this->uid(), $key, $value);
			}
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->prefs->all($this->uid()));
	}

	/** Admins get the full picture, everyone else whether indexing is still running. */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		try {
			return new JSONResponse($this->permissions->isAdmin($this->uid())
				? $this->index->status() + ['full' => true]
				: $this->index->publicStatus() + ['full' => false]);
		} catch (ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
		}
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
