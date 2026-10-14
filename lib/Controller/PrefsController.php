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
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\UserPrefs;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/** The current user's own settings, and the status they may see. */
class PrefsController extends Controller {
	/** As long as Nextcloud's own password confirmation holds (PasswordConfirmationMiddleware). */
	private const CONFIRM_SECONDS = 30 * 60;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private UserPrefs $prefs,
		private IndexAdmin $index,
		private Permissions $permissions,
		private PrivateArea $privateArea,
		private ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function get(): JSONResponse {
		return new JSONResponse($this->prefs->all($this->uid()));
	}

	/**
	 * Turning off the password for the private area needs the password itself: otherwise
	 * anyone at an open session could switch it off and unlock without it. Either the private
	 * area is unlocked (which asked for it) or the password was confirmed a moment ago.
	 */
	#[NoAdminRequired]
	public function save(array $prefs = []): JSONResponse {
		$uid = $this->uid();
		if (array_key_exists('private_password', $prefs) && !$prefs['private_password']
			&& $this->prefs->get($uid, 'private_password') && !$this->passwordConfirmed()) {
			return new JSONResponse(['error' => 'password'], Http::STATUS_FORBIDDEN);
		}
		try {
			$this->prefs->setAll($uid, $prefs);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->prefs->all($uid));
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

	private function passwordConfirmed(): bool {
		return $this->privateArea->isUnlocked()
			|| (int)$this->session->get('last-password-confirm') >= time() - self::CONFIRM_SECONDS;
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
