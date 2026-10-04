<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\AssetSearch;
use OCA\ImmichSearch\Service\FaceCropper;
use OCA\ImmichSearch\Service\ImmichClient;
use OCA\ImmichSearch\Service\ImmichException;
use OCA\ImmichSearch\Service\PeopleIndex;
use OCA\ImmichSearch\Service\Permissions;
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
			$people = [];
			foreach ($this->index->allPeople() as $p) {
				$v = $mine['people'][$p['id']] ?? null;
				if ($v !== null) {
					$people[] = [
						'id' => $p['id'],
						'name' => (string)($p['name'] ?? ''),
						'hidden' => (bool)($p['isHidden'] ?? false),
						'count' => $v['count'],
					];
				}
			}
			usort($people, static function ($a, $b) {
				if (($a['name'] === '') !== ($b['name'] === '')) {
					return $a['name'] === '' ? 1 : -1;
				}
				return $a['name'] !== '' ? strcasecmp($a['name'], $b['name']) : $b['count'] <=> $a['count'];
			});
			return new JSONResponse([
				'people' => $people,
				'updated' => $mine['updated'],
				'canManage' => $this->permissions->canManagePeople($this->uid()),
			]);
		});
	}

	/** No CSRF check: loaded by <img> tags. Read-only. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function face(string $id): Response {
		return $this->guard(function () use ($id) {
			$asset = $this->visible($id)['asset'];
			$resp = new DataDisplayResponse($this->faces->face($id, $asset), Http::STATUS_OK, ['Content-Type' => 'image/jpeg']);
			$resp->cacheFor(86400, false);
			return $resp;
		});
	}

	#[NoAdminRequired]
	public function update(string $id, ?string $name = null, ?bool $hidden = null): JSONResponse {
		return $this->guard(function () use ($id, $name, $hidden) {
			$this->mayManage($id);
			$body = [];
			if ($name !== null) {
				$body['name'] = mb_substr(trim($name), 0, self::MAX_NAME);
			}
			if ($hidden !== null) {
				$body['isHidden'] = $hidden;
			}
			$p = $this->immich->json('PUT', '/people/' . $id, $body);
			return new JSONResponse(['id' => $id, 'name' => (string)($p['name'] ?? ''), 'hidden' => (bool)($p['isHidden'] ?? false)]);
		});
	}

	/** Merge $ids into $id: they become one person, named like $id. */
	#[NoAdminRequired]
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
