<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichException;
use OCA\Glimpse\Service\IndexAdmin;
use OCA\Glimpse\Service\SmallImagePruner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
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
		private IL10N $l,
		private LoggerInterface $logger,
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

	private function guard(callable $fn): JSONResponse {
		try {
			return new JSONResponse($fn());
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
