<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Which Immich people may each Nextcloud user see?
 *
 * Immich keeps one set of people for the whole (shared) index. A person is shown to a
 * user only if they appear in at least one picture that user can open in Nextcloud;
 * the face shown for them is cut from such a picture (see FaceCropper), never from
 * Immich's own person thumbnail, which may come from a private picture.
 *
 * Working that out means walking every person's pictures, so it runs in the background
 * (BuildPeopleIndex, hourly) and the result is stored per user in app data:
 *   {"updated": 1730000000, "people": {"<personId>": {"count": 12, "asset": "<assetId>"}}}
 */
class PeopleIndex {
	private IAppData $appData;

	private const FOLDER = 'people';
	private const PAGE = 1000;

	public function __construct(
		private ImmichClient $immich,
		private Config $config,
		private IndexSignature $signature,
		private AssetSearch $assets,
		private IUserManager $users,
		IAppDataFactory $appDataFactory,
		private LoggerInterface $logger,
	) {
		// Via the factory: search providers are built outside the app's DI container.
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * Rebuild for all enabled users, unless nothing it depends on changed (IndexSignature).
	 *
	 * @return array{0: int, 1: int, 2: bool} [people checked, seconds, skipped]
	 */
	public function rebuild(bool $force = false): array {
		$start = microtime(true);
		$signature = $this->signature->compute();
		$last = $this->config->getState('people_index');
		if (!$force && ($last['signature'] ?? '') === $signature && time() - ($last['updated'] ?? 0) < IndexSignature::MAX_AGE) {
			return [(int)($last['people'] ?? 0), 0, true];
		}
		$uids = [];
		$this->users->callForSeenUsers(function (IUser $u) use (&$uids) {
			if ($u->isEnabled()) {
				$uids[] = $u->getUID();
			}
		});

		$result = array_fill_keys($uids, []);
		$people = $this->allPeople();
		foreach ($people as $person) {
			$pending = array_fill_keys($uids, true);
			for ($page = 1; $page !== null;) {
				$res = $this->immich->json('POST', '/search/metadata', [
					'personIds' => [$person['id']], 'page' => $page, 'size' => self::PAGE, 'order' => 'desc',
				])['assets'] ?? [];
				foreach ($res['items'] ?? [] as $asset) {
					foreach ($uids as $uid) {
						if ($this->assets->visibleItem($uid, $asset, false) === null) {
							continue;
						}
						if (isset($pending[$uid])) {
							// Newest visible picture: becomes the face shown for this person.
							$result[$uid][$person['id']] = ['count' => 0, 'asset' => $asset['id']];
							unset($pending[$uid]);
						}
						$result[$uid][$person['id']]['count']++;
					}
				}
				$page = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
			}
		}

		$folder = $this->folder();
		foreach ($result as $uid => $visible) {
			$folder->newFile($uid . '.json', json_encode(['updated' => time(), 'people' => $visible]));
		}
		$secs = (int)round(microtime(true) - $start);
		$this->config->setState('people_index', ['updated' => time(), 'seconds' => $secs, 'people' => count($people), 'users' => count($uids), 'signature' => $signature]);
		$this->logger->info(sprintf('People index rebuilt: %d people, %d users, %ds', count($people), count($uids), $secs), ['app' => 'glimpse']);
		return [count($people), $secs, false];
	}

	/** @return array{updated: ?int, people: array<string, array{count: int, asset: string}>} */
	public function forUser(string $uid): array {
		try {
			$data = json_decode($this->folder()->getFile($uid . '.json')->getContent(), true);
			return ['updated' => $data['updated'] ?? null, 'people' => $data['people'] ?? []];
		} catch (NotFoundException) {
			return ['updated' => null, 'people' => []];
		}
	}

	/** Record a merge right away, so the user does not wait an hour for the index to catch up. */
	public function applyMerge(string $uid, string $target, array $sources): void {
		$data = $this->forUser($uid);
		foreach ($sources as $id) {
			$src = $data['people'][$id] ?? null;
			if ($src === null) {
				continue;
			}
			if (isset($data['people'][$target])) {
				$data['people'][$target]['count'] += $src['count'];
			} else {
				$data['people'][$target] = $src;
			}
			unset($data['people'][$id]);
		}
		$this->folder()->newFile($uid . '.json', json_encode(['updated' => $data['updated'], 'people' => $data['people']]));
	}

	/** @return list<array> all Immich people, hidden ones included */
	public function allPeople(): array {
		$all = [];
		for ($page = 1; ; $page++) {
			$res = $this->immich->json('GET', '/people?withHidden=true&size=500&page=' . $page);
			array_push($all, ...($res['people'] ?? []));
			if (empty($res['hasNextPage'])) {
				return $all;
			}
		}
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
