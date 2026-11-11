<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\HiddenFolders;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class HiddenFoldersController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private HiddenFolders $hiddenFolders,
		private ?IRootFolder $rootFolder,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function get(): JSONResponse {
		return new JSONResponse(['folders' => $this->hiddenFolders->get($this->uid())]);
	}

	#[NoAdminRequired]
	public function hide(int $fileId = 0): JSONResponse {
		$uid = $this->uid();
		if ($fileId <= 0) {
			return new JSONResponse(['error' => $this->l->t('Not found')], Http::STATUS_NOT_FOUND);
		}
		$userFolder = $this->rootFolder?->getUserFolder($uid);
		$node = $userFolder?->getFirstNodeById($fileId);
		if ($node === null) {
			return new JSONResponse(['error' => $this->l->t('Not found')], Http::STATUS_NOT_FOUND);
		}
		$relPath = $userFolder->getRelativePath($node->getPath());
		$folderPath = $node instanceof Folder ? $relPath : dirname($relPath);
		$clean = '/' . trim($folderPath, '/');
		if ($clean === '/') {
			return new JSONResponse(['error' => $this->l->t('The root folder cannot be hidden')], Http::STATUS_BAD_REQUEST);
		}
		try {
			$folders = $this->hiddenFolders->addFolder($uid, $clean);
		} catch (\InvalidArgumentException) {
			return new JSONResponse(['error' => $this->l->t('The root folder cannot be hidden')], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['folder' => $clean, 'folders' => $folders]);
	}

	#[NoAdminRequired]
	public function set(array $folders = []): JSONResponse {
		$uid = $this->uid();
		$updated = $this->hiddenFolders->setFolders($uid, $folders);
		return new JSONResponse(['folders' => $updated]);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
