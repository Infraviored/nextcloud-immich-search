<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\PrivateArea;
use OCA\ImmichSearch\Service\UserPrefs;
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

	#[NoAdminRequired]
	public function get(): JSONResponse {
		$p = $this->privateArea->get($this->uid());
		return new JSONResponse([
			'folders' => $p['folders'],
			'people' => $p['people'],
			'files' => count($p['files']),
			'unlocked' => $this->privateArea->isUnlocked(),
			'askPassword' => $this->prefs->get($this->uid(), 'private_password'),
		]);
	}

	#[NoAdminRequired]
	public function setLists(array $folders = [], array $people = []): JSONResponse {
		$p = $this->privateArea->setLists($this->uid(), $folders, $people);
		return new JSONResponse(['folders' => $p['folders'], 'people' => $p['people']]);
	}

	#[NoAdminRequired]
	public function setFiles(array $fileIds = [], bool $on = true): JSONResponse {
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

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
