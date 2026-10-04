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
use OCA\ImmichSearch\Service\ImmichClient;
use OCA\ImmichSearch\Service\ImmichException;
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

class ApiController extends Controller {
	private const PAGE_SIZE = 100;
	private const IMAGE_CACHE_SECONDS = 86400;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private Config $config,
		private AssetSearch $search,
		private ImmichClient $immich,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function search(string $q = '', int $page = 1, string $person = '', string $type = ''): JSONResponse {
		$q = trim($q);
		if ($q === '' && $person === '') {
			return new JSONResponse(['items' => [], 'nextPage' => null]);
		}
		return $this->guard(fn () => new JSONResponse(
			$this->search->search($this->uid(), $q, $page, self::PAGE_SIZE, $person, $type)
		));
	}

	/**
	 * Only for assets whose file the user can reach in Nextcloud.
	 * No CSRF check: loaded by <img> tags, which cannot send the request token. Read-only.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function thumbnail(string $id, string $size = 'thumbnail'): Response {
		if (!preg_match(AssetSearch::UUID, $id)) {
			return new JSONResponse(['error' => $this->l->t('Invalid ID')], Http::STATUS_BAD_REQUEST);
		}
		$size = $size === 'preview' ? 'preview' : 'thumbnail';
		return $this->guard(function () use ($id, $size) {
			if (!$this->search->maySee($this->uid(), $id)) {
				return new JSONResponse(['error' => $this->l->t('Not found')], Http::STATUS_NOT_FOUND);
			}
			$img = $this->immich->binary('/assets/' . $id . '/thumbnail?size=' . $size);
			$resp = new DataDisplayResponse($img['body'], Http::STATUS_OK, ['Content-Type' => $img['type']]);
			$resp->cacheFor(self::IMAGE_CACHE_SECONDS, false);
			return $resp;
		});
	}

	private function uid(): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new ImmichException($this->l->t('Not logged in'), Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isConfigured()) {
			throw new ImmichException($this->l->t('Photo search is not set up yet'), Http::STATUS_PRECONDITION_FAILED);
		}
		return $uid;
	}

	/** Turns ImmichException into a JSON error with its status; anything else is logged as a bug. */
	private function guard(callable $fn): Response {
		try {
			return $fn();
		} catch (ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('Photo search failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
