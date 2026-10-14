<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\PeopleNames;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\SensitiveContent;
use OCA\Glimpse\Service\TagFilter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
		private PeopleNames $names,
		private PrivateArea $privateArea,
		private SensitiveContent $sensitive,
		private ImmichClient $immich,
		private TagFilter $tagFilter,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	/**
	 * @param bool $literal take $q as it is; otherwise a person's name at its start or end
	 *                      becomes the person filter ("Tara beach" = Tara + "beach")
	 * @param string $tag id of a collaborative tag: only files with it. Alone, it lists them
	 *                    all, newest first, straight from Nextcloud.
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	public function search(string $q = '', int $page = 1, string $person = '', string $type = '', bool $literal = false, string $similar = '', string $tag = ''): JSONResponse {
		$q = trim($q);
		if ($similar !== '') {
			return $this->guard(function () use ($page, $type, $similar, $tag) {
				$uid = $this->uid();
				// Only from a picture the user may see: its embedding says something about it.
				if (!$this->search->maySee($uid, $similar)) {
					throw new ImmichException($this->l->t('Not found'), Http::STATUS_NOT_FOUND);
				}
				return new JSONResponse($this->private($uid, $this->search->search($uid, '', $page, self::PAGE_SIZE, '', $type, $similar, $this->tagged($uid, $tag, $type))) + ['person' => null]);
			});
		}
		if ($q === '' && $person === '' && $tag === '') {
			return new JSONResponse(['items' => [], 'nextPage' => null]);
		}
		if ($q === '' && $person === '') {
			return $this->guard(function () use ($tag, $type) {
				$uid = $this->uid();
				try {
					$res = $this->tagFilter->items($uid, $tag, $type);
				} catch (\InvalidArgumentException) {
					throw new ImmichException($this->l->t('Tag not found'), Http::STATUS_NOT_FOUND);
				}
				return new JSONResponse($this->private($uid, ['items' => $res['items'], 'nextPage' => null]) + ['person' => null, 'truncated' => $res['truncated']]);
			});
		}
		return $this->guard(function () use ($q, $page, $person, $type, $literal, $tag) {
			$uid = $this->uid();
			$only = $this->tagged($uid, $tag, $type);
			$resolved = null;
			// Private people while locked: not recognised by name, and nothing to show by id
			$hidden = $this->privateArea->hiddenPeople($uid);
			if ($person !== '' && in_array($person, $hidden, true)) {
				return new JSONResponse(['items' => [], 'nextPage' => null, 'person' => null]);
			}
			if ($person === '' && !$literal) {
				[$found, $rest] = $this->names->split($uid, $q);
				if ($found !== null && !in_array($found, $hidden, true)) {
					$resolved = ['id' => $found, 'name' => $this->names->visible($uid)[$found]['name'], 'rest' => $rest];
					[$person, $q] = [$found, $rest];
				}
			}
			return new JSONResponse($this->private($uid, $this->search->search($uid, $q, $page, self::PAGE_SIZE, $person, $type, '', $only)) + ['person' => $resolved]);
		});
	}

	/**
	 * @return ?array<int, true> file ids with the tag, null without a tag; with "only
	 *                           sensitive" just those (and with the tag)
	 */
	private function tagged(string $uid, string $tag, string $type): ?array {
		$only = $this->sensitive->onlyFiles($uid);
		if ($tag === '') {
			return $only;
		}
		try {
			$ids = $this->tagFilter->mediaIds($uid, $tag, in_array($type, ['IMAGE', 'VIDEO'], true) ? $type : '');
		} catch (\InvalidArgumentException) {
			throw new ImmichException($this->l->t('Tag not found'), Http::STATUS_NOT_FOUND);
		}
		return $only === null ? $ids : array_intersect_key($ids, $only);
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

	/** The Immich side of a Nextcloud file (Files app integration). */
	#[NoAdminRequired]
	public function assetForFile(int $fileId): JSONResponse {
		return $this->guard(function () use ($fileId) {
			$item = $this->search->assetForFile($this->uid(), $fileId);
			if ($item === null) {
				throw new ImmichException($this->l->t('Not found'), Http::STATUS_NOT_FOUND);
			}
			return new JSONResponse($item);
		});
	}

	/** Leave out what the user marked private, unless unlocked; sensitive pictures by their mode. */
	private function private(string $uid, array $res): array {
		$res['items'] = $this->sensitive->apply($uid, $this->privateArea->filter($uid, $res['items']));
		return $res;
	}

	private function uid(): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new ImmichException($this->l->t('Not logged in'), Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isConfigured()) {
			throw new ImmichException($this->l->t('Glimpse is not set up yet'), Http::STATUS_PRECONDITION_FAILED);
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
			$this->logger->error('Glimpse failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
