<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\UserPrefs;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** The user's private area: what is private, and unlocking it for this session. */
class PrivateController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private PrivateArea $privateArea,
		private UserPrefs $prefs,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * While locked, only how much is private: the folder names and people would give away
	 * what the private area is meant to hide.
	 */
	#[NoAdminRequired]
	public function get(): JSONResponse {
		return new JSONResponse($this->describe($this->privateArea->get($this->uid())));
	}

	/** While locked, entries can only be added; taking something out needs unlocking. */
	#[NoAdminRequired]
	public function setLists(array $folders = [], array $people = []): JSONResponse {
		$locked = !$this->privateArea->isUnlocked();
		return new JSONResponse($this->describe($this->privateArea->setLists($this->uid(), $folders, $people, $locked)));
	}

	#[NoAdminRequired]
	public function setFiles(array $fileIds = [], bool $on = true): JSONResponse {
		if (!$on && !$this->privateArea->isUnlocked()) {
			return new JSONResponse(['error' => 'locked'], Http::STATUS_FORBIDDEN);
		}
		$p = $this->privateArea->setFiles($this->uid(), $fileIds, $on);
		return new JSONResponse(['files' => count($p['files'])]);
	}

	/** With the password: Nextcloud asks for it if it was not confirmed in the last minutes. */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function unlockWithPassword(): JSONResponse {
		$this->privateArea->unlock();
		return new JSONResponse(['unlocked' => true]);
	}

	/** Without the password, only if the user turned that off. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function unlock(): JSONResponse {
		if ($this->prefs->get($this->uid(), 'private_password')) {
			return new JSONResponse(['error' => 'password'], Http::STATUS_FORBIDDEN);
		}
		$this->privateArea->unlock();
		return new JSONResponse(['unlocked' => true]);
	}

	#[NoAdminRequired]
	public function lock(): JSONResponse {
		$this->privateArea->lock();
		return new JSONResponse(['unlocked' => false]);
	}

	private function describe(array $p): array {
		$unlocked = $this->privateArea->isUnlocked();
		return [
			'folders' => $unlocked ? $p['folders'] : [],
			'people' => $unlocked ? $p['people'] : [],
			'files' => count($p['files']),
			'folderCount' => count($p['folders']),
			'peopleCount' => count($p['people']),
			'unlocked' => $unlocked,
			'locked' => !$unlocked,
			'askPassword' => $this->prefs->get($this->uid(), 'private_password'),
		];
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
