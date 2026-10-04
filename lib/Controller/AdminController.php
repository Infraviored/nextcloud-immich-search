<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Controller;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\ImmichException;
use OCA\ImmichSearch\Service\IndexAdmin;
use OCA\ImmichSearch\Service\SmallImagePruner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
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
		return $this->guard(function () use ($settings) {
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
			$applied = null;
			if ($this->index->managedPatterns() !== $before) {
				$applied = $this->index->applyExclusions();
			}
			return ['settings' => $this->config->all(), 'exclusionsApplied' => $applied];
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

	private function guard(callable $fn): JSONResponse {
		try {
			return new JSONResponse($fn());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('Admin: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
