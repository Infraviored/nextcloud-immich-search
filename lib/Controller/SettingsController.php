<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\BackgroundJob\RebuildPeopleIndexNow;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\ImmichException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;

/** Admin only (no #[NoAdminRequired]): the key gives access to everyone's index. */
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private Config $config,
		private ImmichClient $immich,
		private IJobList $jobs,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Connection and the two settings that predate the settings page. Only what is sent is
	 * changed; an empty key keeps the stored one, so the form never has to show it.
	 */
	public function save(string $url, string $apiKey = '', ?string $pathPattern = null, ?string $faceMode = null, ?int $minSize = null): JSONResponse {
		return $this->respond(function () use ($url, $apiKey, $pathPattern, $faceMode, $minSize) {
			$pattern = $pathPattern !== null ? $this->validPattern($pathPattern) : null;
			if ($faceMode !== null && !in_array($faceMode, Config::FACE_MODES, true)) {
				throw new \InvalidArgumentException('faceMode');
			}
			$user = $this->check($url, $apiKey);
			$this->config->setUrl($url);
			if ($pattern !== null) {
				$this->config->setPathPattern($pattern);
			}
			if ($faceMode !== null) {
				$this->config->setFaceMode($faceMode);
			}
			if ($minSize !== null) {
				$this->config->setMinSize($minSize);
			}
			if ($apiKey !== '') {
				$this->config->setApiKey($apiKey);
			}
			return $user;
		});
	}

	public function test(string $url, string $apiKey = ''): JSONResponse {
		return $this->respond(fn () => $this->check($url, $apiKey));
	}

	/** Rebuild the people index at the next cron run instead of within the hour. */
	public function reindex(): JSONResponse {
		$this->jobs->add(RebuildPeopleIndexNow::class);
		return new JSONResponse(['ok' => true]);
	}

	private function respond(callable $fn): JSONResponse {
		try {
			return new JSONResponse(['ok' => true, 'user' => $fn()]);
		} catch (\InvalidArgumentException|ImmichException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** @return string the Immich account the key belongs to */
	private function check(string $url, string $apiKey): string {
		try {
			$url = Config::normalizeUrl($url);
		} catch (\InvalidArgumentException) {
			throw new \InvalidArgumentException($this->l->t('The address must start with http:// or https://'));
		}
		$key = $apiKey !== '' ? $apiKey : $this->config->getApiKey();
		if ($key === '') {
			throw new \InvalidArgumentException($this->l->t('API key is missing'));
		}
		$me = $this->immich->jsonWith($url, $key, 'GET', '/users/me');
		return (string)($me['email'] ?? $me['name'] ?? '?');
	}

	private function validPattern(string $pattern): string {
		try {
			return Config::normalizePathPattern($pattern);
		} catch (\InvalidArgumentException) {
			throw new \InvalidArgumentException($this->l->t('The path must start with / and contain {user} exactly once'));
		}
	}
}
