<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\FileActions;
use OCA\ImmichSearch\Service\FileTransfer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\ZipResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/** Bulk actions on the selection. Every one runs as the user with Nextcloud's permission checks. */
class FilesController extends Controller {
	private const MAX_FILES = 2000;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private FileTransfer $transfer,
		private FileActions $actions,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @param list<int> $fileIds */
	#[NoAdminRequired]
	public function transfer(array $fileIds, string $target, string $mode = 'move'): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->transfer->transfer($this->uid(), $ids, $target, $mode === 'copy'));
	}

	#[NoAdminRequired]
	public function undo(array $moves = []): JSONResponse {
		return $this->run(array_column($moves, 'fileId'), fn () => $this->actions->undoMove($this->uid(), $moves));
	}

	#[NoAdminRequired]
	public function favorite(array $fileIds, bool $on = true): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->actions->favorite($this->uid(), $ids, $on));
	}

	#[NoAdminRequired]
	public function delete(array $fileIds): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->actions->delete($this->uid(), $ids));
	}

	#[NoAdminRequired]
	public function tags(): JSONResponse {
		return new JSONResponse($this->actions->tags($this->uid()));
	}

	#[NoAdminRequired]
	public function tag(array $fileIds, array $tagIds = [], array $newTags = []): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->actions->tag($this->uid(), $ids, $tagIds, $newTags));
	}

	/** ZIP of the selection; a plain link (GET), so the browser downloads it. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function zip(string $ids = ''): Response {
		$fileIds = array_filter(array_map('intval', explode(',', $ids)));
		if ($fileIds === [] || count($fileIds) > self::MAX_FILES) {
			return new JSONResponse(['error' => 'ids'], Http::STATUS_BAD_REQUEST);
		}
		$zip = new ZipResponse($this->request, $this->l->t('Photos') . '-' . date('Y-m-d'));
		$names = [];
		foreach ($this->actions->readable($this->uid(), $fileIds) as $file) {
			// Same name from different folders: keep both
			$name = $file->getName();
			for ($i = 2; isset($names[$name]); $i++) {
				$name = pathinfo($file->getName(), PATHINFO_FILENAME) . " ($i)." . pathinfo($file->getName(), PATHINFO_EXTENSION);
			}
			$names[$name] = true;
			$zip->addResource($file->fopen('r'), $name, (int)$file->getSize(), $file->getMTime());
		}
		return $zip;
	}

	private function run(array $fileIds, callable $fn): JSONResponse {
		if ($fileIds === [] || count($fileIds) > self::MAX_FILES) {
			return new JSONResponse(['error' => 'fileIds'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new JSONResponse($fn(array_map('intval', $fileIds)));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
