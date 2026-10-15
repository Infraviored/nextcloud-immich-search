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
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\IndexAdmin;
use OCA\Glimpse\Service\SensitiveContent;
use OCA\Glimpse\Service\SmallImagePruner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Admin only (no #[NoAdminRequired] anywhere): index status, what gets indexed,
 * libraries, unified search and people settings.
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private Config $config,
		private IndexAdmin $index,
		private SmallImagePruner $pruner,
		private SensitiveContent $sensitive,
		private IL10N $l,
		private LoggerInterface $logger,
		private AssetSearch $assets,
		private IUserSession $userSession,
		private ?IRootFolder $rootFolder = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function settings(): JSONResponse {
		return new JSONResponse($this->config->all());
	}

	/** Partial update; exclusions are pushed to Immich right away. */
	public function saveSettings(array $settings = []): JSONResponse {
		$save = function () use ($settings) {
			$before = $this->index->managedPatterns();
			foreach ($settings as $key => $value) {
				if (!isset(Config::SETTINGS[$key])) {
					throw new \InvalidArgumentException($this->l->t('Unknown setting: %s', [$key]));
				}
				try {
					$this->config->set($key, $value);
				} catch (\InvalidArgumentException) {
					throw new \InvalidArgumentException($this->l->t('Invalid value for %s', [$key]));
				}
			}
			// Switched off: the tiny images are no longer kept out, so their entries go (also
			// when sent again after an earlier attempt failed).
			if (array_key_exists('prune_small', $settings) && !$this->config->get('prune_small')) {
				$this->pruner->clear();
			}
			$applied = null;
			if ($this->index->managedPatterns() !== $before) {
				$applied = $this->index->applyExclusions();
			}
			return ['settings' => $this->config->all(), 'exclusionsApplied' => $applied];
		};
		// What changes the exclusions runs under the prune lock: a run going on at the same
		// time would write its own idea of them (and of the pruned entries) back over these.
		$exclusions = array_intersect_key($settings, array_flip(['excluded_formats', 'excluded_folders', 'prune_small'])) !== [];
		return $this->guard(fn () => $exclusions ? $this->pruner->exclusive($save) : $save());
	}

	/**
	 * "Ignore this folder" from the viewer: the folder of $fileId joins the excluded folders,
	 * as its path in Immich, so Immich drops its pictures on the next scan.
	 */
	public function excludeFolder(int $fileId = 0): JSONResponse {
		return $this->guard(function () use ($fileId) {
			$uid = $this->userSession->getUser()?->getUID() ?? '';
			if ($this->rootFolder !== null) {
				$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
				if ($node !== null) {
					$owner = $node->getOwner()?->getUID();
					$ownerFolder = $owner !== null ? $this->rootFolder->getUserFolder($owner) : null;
					$ownerNode = $ownerFolder?->getFirstNodeById($fileId);
					$parent = $node->getParent();
					$ownerParent = $ownerNode?->getParent();
					if ($parent === null || $ownerParent === null || $parent->getId() !== $ownerParent->getId()) {
						throw new \InvalidArgumentException($this->l->t('Only folders you own or that are shared as a whole can be ignored'));
					}
				}
			}
			$path = $this->assets->immichPathOf($uid, $fileId);
			if ($path === null || !str_contains($path, '/')) {
				return new JSONResponse(['error' => $this->l->t('This file is not indexed by Glimpse')], Http::STATUS_NOT_FOUND);
			}
			$ncPath = SmallImagePruner::nextcloudPath($this->config->getPathPattern(), $path);
			if ($ncPath === null || !str_contains($ncPath[1], '/')) {
				throw new \InvalidArgumentException($this->l->t('The root folder cannot be ignored'));
			}
			$folder = '/' . ltrim(substr($ncPath[1], 0, (int)strrpos($ncPath[1], '/')), '/');
			$pattern = SmallImagePruner::escape(substr($path, 0, strrpos($path, '/'))) . '/**';
			return $this->pruner->exclusive(function () use ($pattern, $folder) {
				$folders = $this->config->get('excluded_folders');
				if (in_array($pattern, $folders, true)) {
					return ['pattern' => $pattern, 'folder' => $folder, 'exclusionsApplied' => null];
				}
				$this->config->set('excluded_folders', [...$folders, $pattern]);
				$applied = $this->index->applyExclusions();
				return ['pattern' => $pattern, 'folder' => $folder, 'exclusionsApplied' => $applied];
			});
		});
	}

	public function status(): JSONResponse {
		return $this->guard(fn () => $this->index->status());
	}

	public function libraries(): JSONResponse {
		return $this->guard(fn () => [
			'libraries' => $this->index->libraries(),
			'missing' => $this->index->usersWithoutLibrary(),
			'pathPattern' => $this->config->getPathPattern(),
		]);
	}

	public function createLibrary(string $uid): JSONResponse {
		return $this->guard(fn () => $this->index->createLibrary($uid));
	}

	public function folderSuggestions(): JSONResponse {
		return $this->guard(fn () => $this->index->folderSuggestions());
	}

	public function queueMissing(): JSONResponse {
		return $this->guard(function () {
			$this->index->queueMissing();
			return ['ok' => true];
		});
	}

	/** Check every image now (the background job only looks at new ones). */
	public function prune(): JSONResponse {
		return $this->guard(fn () => $this->pruner->run(true));
	}

	public function scan(): JSONResponse {
		return $this->guard(function () {
			$this->index->scanAll();
			return ['ok' => true];
		});
	}

	/** Is everything there for recognising sensitive content (database access, machine learning)? */
	public function sensitiveCheck(): JSONResponse {
		return $this->guard(fn () => $this->sensitive->check());
	}

	private function guard(callable $fn): JSONResponse {
		try {
			$res = $fn();
			return $res instanceof JSONResponse ? $res : new JSONResponse($res);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (LockedException) {
			return new JSONResponse(['error' => $this->l->t('Tiny images are being taken out of Immich right now. Try again in a minute.')], Http::STATUS_CONFLICT);
		} catch (ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('Admin: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
