<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\FileActions;
use OCA\Glimpse\Service\FileTransfer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\ZipResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/** Bulk actions on the selection. Every one runs as the user with Nextcloud's permission checks. */
class FilesController extends Controller {
	private const MAX_FILES = 2000;
	/** Also keeps the archive below the 4 GiB a plain (non-ZIP64) ZIP can hold. */
	public const MAX_ZIP_BYTES = 4 * 1000 ** 3;

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
	#[UserRateLimit(limit: 60, period: 60)]
	public function transfer(array $fileIds, string $target, string $mode = 'move'): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->transfer->transfer($this->uid(), $ids, $target, $mode === 'copy'));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function undo(array $moves = []): JSONResponse {
		if (!self::validMoves($moves)) {
			return new JSONResponse(['error' => 'moves'], Http::STATUS_BAD_REQUEST);
		}
		return $this->run(array_column($moves, 'fileId'), fn () => $this->actions->undoMove($this->uid(), $moves));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function favorite(array $fileIds, bool $on = true): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->actions->favorite($this->uid(), $ids, $on));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(array $fileIds): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->actions->delete($this->uid(), $ids));
	}

	#[NoAdminRequired]
	public function tags(): JSONResponse {
		return new JSONResponse($this->actions->tags($this->uid()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function tag(array $fileIds, array $tagIds = [], array $newTags = []): JSONResponse {
		return $this->run($fileIds, fn ($ids) => $this->actions->tag($this->uid(), $ids, $tagIds, $newTags));
	}

	/**
	 * ZIP of the selection. A form POST (fields "ids", comma separated, and "requesttoken"),
	 * so the browser downloads it and a large selection does not hit URL length limits.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function zip(string $ids = ''): Response {
		$fileIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids)))));
		if ($fileIds === []) {
			return new JSONResponse(['error' => $this->l->t('No files selected')], Http::STATUS_BAD_REQUEST);
		}
		if (count($fileIds) > self::MAX_FILES) {
			return new JSONResponse(['error' => $this->l->t('At most %d files can be downloaded at once', [self::MAX_FILES])], Http::STATUS_BAD_REQUEST);
		}
		['files' => $files, 'failed' => $failed] = $this->actions->readable($this->uid(), $fileIds);
		$total = array_sum(array_map(static fn ($f) => (float)$f->getSize(), $files));
		if ($total > self::MAX_ZIP_BYTES) {
			return new JSONResponse(['error' => $this->l->t('The selection is too large to download at once (at most %s GB)', [self::MAX_ZIP_BYTES / 1000 ** 3])], Http::STATUS_BAD_REQUEST);
		}
		$zip = new ZipResponse($this->request, $this->l->t('Photos') . '-' . date('Y-m-d'));
		$names = [];
		$added = 0;
		foreach ($files as $file) {
			try {
				$fh = $file->fopen('r');
			} catch (\Throwable) {
				$fh = false;
			}
			if (!is_resource($fh)) {
				$failed[] = ['fileId' => $file->getId(), 'name' => $file->getName(), 'error' => $this->l->t('Could not be read')];
				continue;
			}
			$zip->addResource($fh, self::uniqueName($names, $file->getName()), (int)$file->getSize(), $file->getMTime());
			$added++;
		}
		if ($added === 0) {
			return new JSONResponse(['error' => $this->l->t('None of the selected files can be downloaded'), 'failed' => $failed], Http::STATUS_BAD_REQUEST);
		}
		if ($failed !== []) {
			// The download is the only answer the browser shows, so say inside it what is missing
			$lines = array_map(static fn ($f) => ($f['name'] !== '' ? $f['name'] : '#' . $f['fileId']) . ': ' . $f['error'], $failed);
			$note = fopen('php://temp', 'w+b');
			fwrite($note, implode("\n", $lines) . "\n");
			$size = ftell($note);
			rewind($note);
			$zip->addResource($note, self::uniqueName($names, $this->l->t('Not included') . '.txt'), $size, time());
		}
		return $zip;
	}

	/** Same name from different folders: keep both, as "name (2).jpg". */
	private static function uniqueName(array &$names, string $name): string {
		$out = $name;
		for ($i = 2; isset($names[$out]); $i++) {
			$ext = pathinfo($name, PATHINFO_EXTENSION);
			$out = pathinfo($name, PATHINFO_FILENAME) . " ($i)" . ($ext !== '' ? ".$ext" : '');
		}
		$names[$out] = true;
		return $out;
	}

	/** Each entry as FileTransfer reported it: {fileId: int, from: string, fromName?: string}. */
	public static function validMoves(mixed $moves): bool {
		if (!is_array($moves) || $moves === []) {
			return false;
		}
		foreach ($moves as $m) {
			if (!is_array($m) || !isset($m['fileId'], $m['from'])
				|| !(is_int($m['fileId']) || (is_string($m['fileId']) && ctype_digit($m['fileId'])))
				|| !is_string($m['from'])
				|| (isset($m['fromName']) && !is_string($m['fromName']))) {
				return false;
			}
		}
		return true;
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
