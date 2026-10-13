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
use OCA\Glimpse\Service\Discover;
use OCA\Glimpse\Service\FolderBrowser;
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\MapIndex;
use OCA\Glimpse\Service\Permissions;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\TileProxy;
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
		private PrivateArea $privateArea,
		private Permissions $permissions,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param string $before ISO date: from this point back in time (year links)
	 * @param string $after ISO date: from this point forward to today, oldest first
	 */
	#[NoAdminRequired]
	public function timeline(int $page = 1, string $before = '', string $type = '', string $after = '', string $person = ''): Response {
		return $this->guard(function () use ($page, $before, $type, $after, $person) {
			// Private people while locked: nothing to show by id (as in the search)
			if ($person !== '' && in_array($person, $this->privateArea->hiddenPeople($this->uid()), true)) {
				return ['items' => [], 'nextPage' => null] + ($page === 1 ? ['years' => []] : []);
			}
			$res = $this->assets->timeline($this->uid(), $before !== '' ? $before : null, $page, self::PAGE_SIZE, $type, $after !== '' ? $after : null, $person);
			$res['items'] = $this->privateArea->filter($this->uid(), $res['items']);
			$seenYear = null;
			if ($page === 1 && $before === '' && $after === '' && isset($res['items'][0]['date'])) {
				$seenYear = (int)substr((string)$res['items'][0]['date'], 0, 4);
			}
			if ($page !== 1) {
				return $res;
			}
			return $res + ['years' => $person !== '' ? $this->assets->personYears($this->uid(), $person) : $this->assets->years($this->uid(), $seenYear)];
		});
	}

	#[NoAdminRequired]
	public function memories(string $date = ''): Response {
		$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
		return $this->guard(fn () => array_values(array_filter(array_map(
			fn ($m) => ['year' => $m['year'], 'items' => $this->privateArea->filter($this->uid(), $m['items'])],
			$this->discover->onThisDay($this->uid(), $date)), static fn ($m) => $m['items'] !== [])));
	}

	#[NoAdminRequired]
	public function duplicates(): Response {
		return $this->guard(fn () => array_values(array_filter(array_map(
			fn ($g) => ['items' => $this->privateArea->filter($this->uid(), $g['items'])] + $g,
			$this->discover->duplicates($this->uid())), static fn ($g) => count($g['items']) >= 2)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function dismissDuplicate(string $id): Response {
		return $this->guard(function () use ($id) {
			// Gone for everyone afterwards: only if the user sees every picture of the group.
			// Admins may anyway; they can clean up Immich directly too.
			$uid = $this->uid();
			if (!$this->permissions->isAdmin($uid) && !$this->discover->seesWholeGroup($uid, $id)) {
				throw new ImmichException($this->l->t('Only possible if you can see all pictures of this group'), Http::STATUS_FORBIDDEN);
			}
			$this->discover->dismissDuplicate($uid, $id);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function folder(string $path = '/'): Response {
		return $this->guard(function () use ($path) {
			$uid = $this->uid();
			$res = $this->folders->list($uid, $path);
			// A private folder stays closed until unlocked; its private subfolders are not listed.
			$locked = !$this->privateArea->isUnlocked();
			if ($locked && $this->privateArea->inPrivateFolder($uid, $res['path'])) {
				return ['path' => $res['path'], 'folders' => [], 'items' => [], 'truncated' => false, 'private' => true];
			}
			if ($locked) {
				$res['folders'] = array_values(array_filter($res['folders'], fn ($f) => !$this->privateArea->inPrivateFolder($uid, $f['path'])));
			}
			$res['items'] = $this->privateArea->filter($uid, $res['items']);
			return $res + ['private' => false];
		});
	}

	#[NoAdminRequired]
	public function items(string $ids = ''): Response {
		$fileIds = array_slice(array_filter(array_map('intval', explode(',', $ids))), 0, 1000);
		return $this->guard(fn () => $this->privateArea->filter($this->uid(), $this->folders->items($this->uid(), $fileIds)));
	}

	#[NoAdminRequired]
	public function map(): Response {
		$uid = $this->uid();
		$json = $this->map->forUser($uid);
		$p = $this->privateArea->get($uid);
		if (!$this->privateArea->isUnlocked() && ($p['folders'] !== [] || $p['files'] !== [] || $p['assets'] !== [])) {
			$data = json_decode($json, true);
			// Markers: [assetId, fileId, lat, lon, video, timestamp, path]
			$data['markers'] = array_values(array_filter($data['markers'], fn ($m) => !$this->privateArea->isPrivate($uid,
				['id' => $m[0], 'fileId' => $m[1], 'path' => $m[6] ?? ''])));
			$json = json_encode($data);
		}
		// Not cached by the browser: unlocking must show the private markers right away.
		return new DataDisplayResponse($json, Http::STATUS_OK, ['Content-Type' => 'application/json']);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	// A map view needs a few dozen tiles; this still allows brisk panning, not mirroring
	#[UserRateLimit(limit: 600, period: 60)]
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
