<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\FileTransfer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class FilesController extends Controller {
	private const MAX_FILES = 2000;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private FileTransfer $transfer,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @param list<int> $fileIds */
	#[NoAdminRequired]
	public function transfer(array $fileIds, string $target, string $mode = 'move'): JSONResponse {
		if ($fileIds === [] || count($fileIds) > self::MAX_FILES) {
			return new JSONResponse(['error' => 'fileIds'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new JSONResponse($this->transfer->transfer($this->uid(), array_map('intval', $fileIds), $target, $mode === 'copy'));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Resolved per call: with basic auth the user is only logged in after the controller is built. */
	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
