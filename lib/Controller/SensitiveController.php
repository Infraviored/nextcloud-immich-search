<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Controller;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\BackgroundJob\RebuildSensitive;
use OCA\Glimpse\Service\SensitiveContent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;

/** The user's sensitive content: how far it is, marking pictures by hand, recomputing. */
class SensitiveController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private SensitiveContent $sensitive,
		private IJobList $jobs,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function status(): JSONResponse {
		$uid = $this->uid();
		if (!$this->sensitive->isEnabled($uid)) {
			return new JSONResponse(['available' => $this->sensitive->isAvailable(), 'enabled' => false]);
		}
		return new JSONResponse(['available' => true, 'enabled' => true, 'queued' => $this->queued($uid)] + $this->sensitive->status($uid));
	}

	/**
	 * @param array<string, ?bool> $marks fileId => true (sensitive), false (not), null (back to
	 *                                    the score)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function mark(array $marks = []): JSONResponse {
		$uid = $this->uid();
		if (!$this->sensitive->isEnabled($uid)) {
			return new JSONResponse(['error' => 'off'], Http::STATUS_CONFLICT);
		}
		$res = $this->sensitive->mark($uid, $marks);
		// Objects also when empty (none of the files was the user's): [] would not decode as a map
		return new JSONResponse(['previous' => (object)$res['previous'], 'sensitive' => (object)$res['sensitive']]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function rebuild(): JSONResponse {
		$uid = $this->uid();
		if (!$this->sensitive->isEnabled($uid)) {
			return new JSONResponse(['error' => 'off'], Http::STATUS_CONFLICT);
		}
		if (!$this->queued($uid)) {
			$this->jobs->add(RebuildSensitive::class, ['uid' => $uid]);
		}
		return new JSONResponse(['queued' => true]);
	}

	private function queued(string $uid): bool {
		return $this->jobs->has(RebuildSensitive::class, ['uid' => $uid]);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}
}
