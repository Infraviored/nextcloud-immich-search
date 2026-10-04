<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\AssetSearch;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\Discover;
use OCA\ImmichSearch\Service\FolderBrowser;
use OCA\ImmichSearch\Service\ImmichException;
use OCA\ImmichSearch\Service\MapIndex;
use OCA\ImmichSearch\Service\TileProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** Timeline, memories, duplicates, folders and the map: the browsing side of the app. */
class BrowseController extends Controller {
	private const PAGE_SIZE = 120;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private Config $config,
		private AssetSearch $assets,
		private Discover $discover,
		private FolderBrowser $folders,
		private MapIndex $map,
		private TileProxy $tiles,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @param string $before ISO date: jump to this point in time (year links) */
	#[NoAdminRequired]
	public function timeline(int $page = 1, string $before = '', string $type = ''): Response {
		return $this->guard(fn () => $this->assets->timeline($this->uid(), $before !== '' ? $before : null, $page, self::PAGE_SIZE, $type)
			+ ($page === 1 ? ['years' => $this->assets->years()] : []));
	}

	#[NoAdminRequired]
	public function memories(string $date = ''): Response {
		$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
		return $this->guard(fn () => $this->discover->onThisDay($this->uid(), $date));
	}

	#[NoAdminRequired]
	public function duplicates(): Response {
		return $this->guard(fn () => $this->discover->duplicates($this->uid()));
	}

	#[NoAdminRequired]
	public function dismissDuplicate(string $id): Response {
		return $this->guard(function () use ($id) {
			// Only for groups the user actually sees two pictures of
			$mine = array_column($this->discover->duplicates($this->uid()), 'id');
			if (!in_array($id, $mine, true)) {
				throw new ImmichException($this->l->t('Not found'), Http::STATUS_NOT_FOUND);
			}
			$this->discover->dismissDuplicate($this->uid(), $id);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function folder(string $path = '/'): Response {
		return $this->guard(fn () => $this->folders->list($this->uid(), $path));
	}

	#[NoAdminRequired]
	public function items(string $ids = ''): Response {
		$fileIds = array_slice(array_filter(array_map('intval', explode(',', $ids))), 0, 1000);
		return $this->guard(fn () => $this->folders->items($this->uid(), $fileIds));
	}

	#[NoAdminRequired]
	public function map(): Response {
		$resp = new DataDisplayResponse($this->map->forUser($this->uid()), Http::STATUS_OK, ['Content-Type' => 'application/json']);
		$resp->cacheFor(300, false);
		return $resp;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tile(int $z, int $x, int $y): Response {
		try {
			$resp = new DataDisplayResponse($this->tiles->tile($z, $x, $y), Http::STATUS_OK, ['Content-Type' => 'image/png']);
			$resp->cacheFor(7 * 86400, false);
			return $resp;
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'tile'], Http::STATUS_NOT_FOUND);
		}
	}

	private function guard(callable $fn): Response {
		try {
			return new JSONResponse($fn());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $this->l->t('Not found')], Http::STATUS_NOT_FOUND);
		} catch (ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('Browse: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
