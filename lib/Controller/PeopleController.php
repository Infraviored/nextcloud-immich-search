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
use OCA\Glimpse\Service\FaceCropper;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\PeopleIndex;
use OCA\Glimpse\Service\Permissions;
use OCA\Glimpse\Service\PrivateArea;
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

/**
 * People as the current user may see them: only those appearing in pictures the user
 * can open (PeopleIndex), with faces cut from such pictures (FaceCropper).
 */
class PeopleController extends Controller {
	private const MAX_NAME = 100;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private PeopleIndex $index,
		private FaceCropper $faces,
		private ImmichClient $immich,
		private Permissions $permissions,
		private AssetSearch $assets,
		private PrivateArea $privateArea,
		private Config $config,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Named people first (by name), then unnamed ones by number of pictures. */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->guard(function () {
			$mine = $this->index->forUser($this->uid());
			// Unnamed clusters with very few pictures are mostly noise (background faces).
			$minCount = max(1, (int)$this->config->get('people_min_count'));
			$private = $this->privateArea->get($this->uid())['people'];
			$hidden = $this->privateArea->hiddenPeople($this->uid());
			$people = [];
			foreach ($this->index->allPeople() as $p) {
				$v = $mine['people'][$p['id']] ?? null;
				if ($v !== null && !in_array($p['id'], $hidden, true) && (($p['name'] ?? '') !== '' || $v['count'] >= $minCount)) {
					$people[] = [
						'id' => $p['id'],
						'name' => (string)($p['name'] ?? ''),
						'hidden' => (bool)($p['isHidden'] ?? false),
						'favorite' => (bool)($p['isFavorite'] ?? false),
						'private' => in_array($p['id'], $private, true),
						'birthDate' => $p['birthDate'] ?? null,
						'count' => $v['count'],
					];
				}
			}
			usort($people, static function ($a, $b) {
				if ($a['favorite'] !== $b['favorite']) {
					return $a['favorite'] ? -1 : 1;
				}
				if (($a['name'] === '') !== ($b['name'] === '')) {
					return $a['name'] === '' ? 1 : -1;
				}
				// Most pictures first everywhere: the people page, the navigation and every person picker
				return ($b['count'] <=> $a['count']) ?: strcasecmp($a['name'], $b['name']);
			});
			return new JSONResponse([
				'people' => $people,
				'updated' => $mine['updated'],
				'canManage' => $this->permissions->canManagePeople($this->uid()),
			]);
		});
	}

	/**
	 * The person's face, cut from a picture the user may see: the one the people index
	 * picked, or $asset (one of samples()). No CSRF check: loaded by <img> tags. Read-only.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function face(string $id, string $asset = ''): Response {
		return $this->guard(function () use ($id, $asset) {
			$default = $this->visible($id)['asset'];
			if ($asset === '') {
				$asset = $default;
			} else {
				$this->assetVisible($asset);
			}
			$resp = new DataDisplayResponse($this->faces->face($id, $asset), Http::STATUS_OK, ['Content-Type' => 'image/jpeg']);
			$resp->cacheFor(86400, false);
			return $resp;
		});
	}

	/** @param string|null $birthDate YYYY-MM-DD, '' to remove */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function update(string $id, ?string $name = null, ?bool $hidden = null, ?bool $favorite = null, ?string $birthDate = null): JSONResponse {
		return $this->guard(function () use ($id, $name, $hidden, $favorite, $birthDate) {
			$this->mayManage($id);
			$body = [];
			if ($favorite !== null) {
				$body['isFavorite'] = $favorite;
			}
			if ($birthDate !== null) {
				if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
					throw new ImmichException($this->l->t('Invalid date'), Http::STATUS_BAD_REQUEST);
				}
				$body['birthDate'] = $birthDate === '' ? null : $birthDate;
			}
			if ($name !== null) {
				$body['name'] = mb_substr(trim($name), 0, self::MAX_NAME);
			}
			if ($hidden !== null) {
				$body['isHidden'] = $hidden;
			}
			$p = $this->immich->json('PUT', '/people/' . $id, $body);
			return new JSONResponse(['id' => $id, 'name' => (string)($p['name'] ?? ''), 'hidden' => (bool)($p['isHidden'] ?? false),
				'favorite' => (bool)($p['isFavorite'] ?? false), 'birthDate' => $p['birthDate'] ?? null]);
		});
	}

	/**
	 * A few different pictures of the person the user may see, newest, middle and oldest of
	 * the recent ones – so one bad crop does not decide who someone is.
	 *
	 * @return list<string> asset ids
	 */
	#[NoAdminRequired]
	public function samples(string $id, int $count = 3): JSONResponse {
		return $this->guard(function () use ($id, $count) {
			$this->visible($id);
			$res = $this->immich->json('POST', '/search/metadata', ['personIds' => [$id], 'size' => 60, 'order' => 'desc'])['assets'] ?? [];
			$ids = [];
			foreach ($res['items'] ?? [] as $a) {
				if ($this->assets->visibleItem($this->uid(), $a) !== null) {
					$ids[] = $a['id'];
				}
			}
			$count = max(1, min(6, $count));
			if (count($ids) > $count) {
				// spread over the list instead of three shots of the same afternoon
				$step = (count($ids) - 1) / ($count - 1);
				$ids = array_map(static fn ($i) => $ids[(int)round($i * $step)], range(0, $count - 1));
			}
			return new JSONResponse(array_values(array_unique($ids)));
		});
	}

	/**
	 * Faces Immich found in one picture, as boxes relative to the picture (0..1).
	 * People are named only if the user may see them (they are in this picture, so normally yes).
	 */
	#[NoAdminRequired]
	public function faces(string $assetId): JSONResponse {
		return $this->guard(function () use ($assetId) {
			$this->assetVisible($assetId);
			$visible = $this->index->forUser($this->uid())['people'];
			$out = [];
			foreach ($this->immich->json('GET', '/faces?id=' . $assetId) as $f) {
				$w = max(1, (int)$f['imageWidth']);
				$h = max(1, (int)$f['imageHeight']);
				$pid = $f['person']['id'] ?? null;
				$out[] = [
					'id' => $f['id'],
					'x' => $f['boundingBoxX1'] / $w, 'y' => $f['boundingBoxY1'] / $h,
					'w' => ($f['boundingBoxX2'] - $f['boundingBoxX1']) / $w, 'h' => ($f['boundingBoxY2'] - $f['boundingBoxY1']) / $h,
					'personId' => $pid,
					'personName' => $pid !== null ? (string)($f['person']['name'] ?? '') : null,
					'known' => $pid !== null && isset($visible[$pid]),
				];
			}
			return new JSONResponse(['faces' => $out, 'canManage' => $this->permissions->canManagePeople($this->uid())]);
		});
	}

	/**
	 * Give one face to another person, or to a new one ($newName). The face must be in a
	 * picture the user may see; an existing target person must be visible to the user.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function reassignFace(string $faceId, string $assetId, string $personId = '', string $newName = ''): JSONResponse {
		return $this->guard(function () use ($faceId, $assetId, $personId, $newName) {
			$this->faceInAsset($faceId, $assetId);
			if (trim($newName) !== '') {
				$personId = $this->immich->json('POST', '/people', ['name' => mb_substr(trim($newName), 0, self::MAX_NAME)])['id'];
			} else {
				$this->mayManage($personId);
			}
			$this->immich->json('PUT', '/faces/' . $personId, ['id' => $faceId]);
			return new JSONResponse(['ok' => true, 'personId' => $personId]);
		});
	}

	/** "This is nobody": a wrongly detected face. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function deleteFace(string $faceId, string $assetId): JSONResponse {
		return $this->guard(function () use ($faceId, $assetId) {
			$this->faceInAsset($faceId, $assetId);
			$this->immich->json('DELETE', '/faces/' . $faceId, ['force' => false]);
			return new JSONResponse(['ok' => true]);
		});
	}

	private function assetVisible(string $assetId): void {
		if (!preg_match(AssetSearch::UUID, $assetId) || !$this->assets->maySee($this->uid(), $assetId)) {
			throw new ImmichException($this->l->t('Not found'), Http::STATUS_NOT_FOUND);
		}
	}

	private function faceInAsset(string $faceId, string $assetId): void {
		if (!$this->permissions->canManagePeople($this->uid())) {
			throw new ImmichException($this->l->t('You are not allowed to change people'), Http::STATUS_FORBIDDEN);
		}
		$this->assetVisible($assetId);
		$ids = array_column($this->immich->json('GET', '/faces?id=' . $assetId), 'id');
		if (!in_array($faceId, $ids, true)) {
			throw new ImmichException($this->l->t('Not found'), Http::STATUS_NOT_FOUND);
		}
	}

	/** Merge $ids into $id: they become one person, named like $id. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function merge(string $id, array $ids = []): JSONResponse {
		return $this->guard(function () use ($id, $ids) {
			$ids = array_values(array_diff(array_unique($ids), [$id]));
			if ($ids === []) {
				throw new ImmichException($this->l->t('Nothing to merge'), Http::STATUS_BAD_REQUEST);
			}
			foreach ([$id, ...$ids] as $p) {
				$this->mayManage($p);
			}
			$this->immich->json('POST', '/people/' . $id . '/merge', ['ids' => $ids]);
			$this->index->applyMerge($this->uid(), $id, $ids);
			return new JSONResponse(['ok' => true]);
		});
	}

	/** @return array{count: int, asset: string} */
	private function visible(string $id): array {
		if (!preg_match(AssetSearch::UUID, $id)) {
			throw new ImmichException($this->l->t('Invalid ID'), Http::STATUS_BAD_REQUEST);
		}
		$v = $this->index->forUser($this->uid())['people'][$id] ?? null;
		if ($v === null) {
			throw new ImmichException($this->l->t('Not found'), Http::STATUS_NOT_FOUND);
		}
		return $v;
	}

	private function mayManage(string $id): void {
		if (!$this->permissions->canManagePeople($this->uid())) {
			throw new ImmichException($this->l->t('You are not allowed to change people'), Http::STATUS_FORBIDDEN);
		}
		$this->visible($id);
	}

	private function guard(callable $fn): Response {
		try {
			return $fn();
		} catch (ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('People: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/** Resolved per call: with basic auth the user is only logged in after the controller is built. */
	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
