<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCA\ImmichSearch\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Geotagged pictures per user, for the map.
 *
 * Immich's map markers carry no file path, so they cannot be checked against Nextcloud's
 * permissions one by one without a request each. Instead this walks the library once in
 * the background (with the people index) and stores, per user, the markers of the
 * pictures that user may see, compactly: [assetId, fileId, lat, lon, video, timestamp].
 */
class MapIndex {
	private const FOLDER = 'map';
	private const PAGE = 1000;
	private IAppData $appData;

	public function __construct(
		private ImmichClient $immich,
		private AssetSearch $assets,
		private IUserManager $users,
		private Config $config,
		private IndexSignature $signature,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** @return int geotagged pictures seen, -1 if skipped because nothing changed */
	public function rebuild(bool $force = false): int {
		$signature = $this->signature->compute();
		$last = $this->config->getState('map_index');
		if (!$force && ($last['signature'] ?? '') === $signature && time() - ($last['updated'] ?? 0) < IndexSignature::MAX_AGE) {
			return -1;
		}
		$uids = [];
		$this->users->callForSeenUsers(function (IUser $u) use (&$uids) {
			if ($u->isEnabled()) {
				$uids[] = $u->getUID();
			}
		});
		$markers = array_fill_keys($uids, []);
		$seen = 0;
		for ($page = 1; $page !== null;) {
			$res = $this->immich->json('POST', '/search/metadata', ['page' => $page, 'size' => self::PAGE, 'withExif' => true])['assets'] ?? [];
			foreach ($res['items'] ?? [] as $a) {
				$lat = $a['exifInfo']['latitude'] ?? null;
				$lon = $a['exifInfo']['longitude'] ?? null;
				if ($lat === null || $lon === null) {
					continue;
				}
				$seen++;
				foreach ($uids as $uid) {
					$item = $this->assets->visibleItem($uid, $a, false);
					if ($item !== null) {
						$markers[$uid][] = [$a['id'], $item['fileId'], round((float)$lat, 5), round((float)$lon, 5),
							$item['type'] === 'VIDEO' ? 1 : 0, strtotime((string)$item['date']) ?: 0];
					}
				}
			}
			$page = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
		}
		$folder = $this->folder();
		foreach ($markers as $uid => $list) {
			$folder->newFile($uid . '.json', json_encode(['updated' => time(), 'markers' => $list]));
		}
		$this->config->setState('map_index', ['updated' => time(), 'seen' => $seen, 'signature' => $signature]);
		return $seen;
	}

	/** Raw JSON for the user (sent as it is; can be several MB). */
	public function forUser(string $uid): string {
		try {
			return $this->folder()->getFile($uid . '.json')->getContent();
		} catch (NotFoundException) {
			return json_encode(['updated' => null, 'markers' => []]);
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
