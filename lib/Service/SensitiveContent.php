<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

/**
 * Optional: nudity, so each user can show such pictures marked, hide them or see only them.
 * Off unless the admin allows it (`sensitive_detection`, with the detector's address) and the
 * user turns it on.
 *
 * What the detector found is per picture and the same for everyone (SensitiveScanner). Per
 * user are: which body parts count (categories), how sure the detector must be (threshold),
 * and marks by hand, which always win. A user's list holds only files they can see.
 *
 * Marks are stored per user in app data, `sensitive/<uid>.json`: {"marks": {fileId: bool}}.
 */
class SensitiveContent {
	/** What a user can choose to count, and the detector classes behind each. */
	public const CATEGORIES = [
		'breast' => ['FEMALE_BREAST_EXPOSED'],
		'genitals' => ['FEMALE_GENITALIA_EXPOSED', 'MALE_GENITALIA_EXPOSED'],
		'buttocks' => ['BUTTOCKS_EXPOSED', 'ANUS_EXPOSED'],
		'chest' => ['MALE_BREAST_EXPOSED'],
	];
	/** Thresholds offered to the user, in percent. */
	public const THRESHOLDS = [20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95];
	private const FOLDER = 'sensitive';
	private const MODES = ['all', 'hide', 'only'];
	private const LIST_TTL = 3600;

	private IAppData $appData;
	private ICache $lists;
	/** @var array<string, array> */
	private array $cache = [];
	/** @var array<string, array> */
	private array $visible = [];

	public function __construct(
		private UserPrefs $prefs,
		private SensitiveScanner $scanner,
		private AssetSearch $assets,
		private IRootFolder $rootFolder,
		private IConfig $ncConfig,
		IAppDataFactory $appDataFactory,
		ICacheFactory $cacheFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
		$this->lists = $cacheFactory->createDistributed('glimpse_sensitive');
	}

	/** The admin allows it (and set up the detector). */
	public function isAvailable(): bool {
		return $this->scanner->enabled();
	}

	/** Allowed and turned on by this user. */
	public function isEnabled(string $uid): bool {
		return $this->isAvailable() && (bool)$this->prefs->get($uid, 'sensitive_enabled');
	}

	/** "all", "hide" or "only"; "all" whenever the feature is off. */
	public function mode(string $uid): string {
		$mode = $this->isEnabled($uid) ? (string)$this->prefs->get($uid, 'sensitive_mode') : 'all';
		return in_array($mode, self::MODES, true) ? $mode : 'all';
	}

	public function get(string $uid): array {
		if (!isset($this->cache[$uid])) {
			try {
				$data = json_decode($this->folder()->getFile($uid . '.json')->getContent(), true) ?: [];
			} catch (NotFoundException) {
				$data = [];
			}
			$this->cache[$uid] = $data + ['marks' => []];
		}
		return $this->cache[$uid];
	}

	/** @return array<int, true> the files that count as sensitive for $uid right now */
	public function flagged(string $uid): array {
		return self::pick($this->visibleScores($uid), $this->marks($uid), $this->categories($uid), $this->threshold($uid));
	}

	/**
	 * Mark each item (`sensitive: true`) and filter by the user's mode. $global: Nextcloud's
	 * search bar and dashboard, where "only" makes no sense and everything is shown then.
	 * Unchanged when the feature is off.
	 */
	public function apply(string $uid, array $items, bool $global = false): array {
		if (!$this->isEnabled($uid)) {
			return $items;
		}
		$flagged = $this->flagged($uid);
		$mode = $this->mode($uid);
		$out = [];
		foreach ($items as $item) {
			$on = isset($flagged[(int)($item['fileId'] ?? 0)]);
			if (($mode === 'hide' && $on) || (!$global && $mode === 'only' && !$on)) {
				continue;
			}
			$out[] = $on ? $item + ['sensitive' => true] : $item;
		}
		return $out;
	}

	/** Keep a file id? For lists that are not items (map markers). */
	public function keeps(string $uid, int $fileId, ?array $flagged = null): bool {
		$mode = $this->mode($uid);
		if ($mode === 'all') {
			return true;
		}
		$on = isset(($flagged ?? $this->flagged($uid))[$fileId]);
		return $mode === 'hide' ? !$on : $on;
	}

	/** For "only": the files to search in (as AssetSearch's $onlyFiles), else null. */
	public function onlyFiles(string $uid): ?array {
		return $this->mode($uid) === 'only' ? $this->flagged($uid) : null;
	}

	/**
	 * Mark files by hand: true sensitive, false not, null back to what the detector says. Only
	 * files the user can reach.
	 *
	 * @param array<int|string, ?bool> $marks
	 * @return array{previous: array<int, ?bool>, sensitive: array<int, bool>}
	 */
	public function mark(string $uid, array $marks): array {
		$data = $this->get($uid);
		$folder = $this->rootFolder->getUserFolder($uid);
		$previous = [];
		foreach (array_slice($marks, 0, 1000, true) as $id => $on) {
			$id = (int)$id;
			if ($id <= 0 || $folder->getFirstNodeById($id) === null) {
				continue;
			}
			$previous[$id] = isset($data['marks'][$id]) ? (bool)$data['marks'][$id] : null;
			if ($on === null) {
				unset($data['marks'][$id]);
			} else {
				$data['marks'][$id] = (bool)$on;
			}
		}
		$this->save($uid, $data);
		$flagged = $this->flagged($uid);
		$now = [];
		foreach (array_keys($previous) as $id) {
			$now[$id] = isset($flagged[$id]);
		}
		return ['previous' => $previous, 'sensitive' => $now];
	}

	/** For the settings: scan progress, and how many each threshold would flag with the user's categories. */
	public function status(string $uid): array {
		$scores = $this->visibleScores($uid);
		$marks = $this->marks($uid);
		$categories = $this->categories($uid);
		$counts = [];
		foreach (self::THRESHOLDS as $t) {
			$counts[$t] = count(self::pick($scores, $marks, $categories, $t / 100));
		}
		return [
			'scan' => $this->scanner->status(),
			'marked' => count(array_filter($marks)),
			'unmarked' => count($marks) - count(array_filter($marks)),
			'counts' => $counts,
		];
	}

	/** Users who turned it on. @return list<string> */
	public function users(): array {
		return $this->ncConfig->getUsersForUserValue(Application::APP_ID, 'pref_sensitive_enabled', '1');
	}

	/**
	 * Which files count: a mark wins; otherwise the highest score among the chosen categories.
	 *
	 * @param array<int, array<string, float>> $scores fileId => class => score
	 * @param array<int, bool> $marks
	 * @param list<string> $categories
	 * @return array<int, true>
	 */
	public static function pick(array $scores, array $marks, array $categories, float $threshold): array {
		$classes = [];
		foreach ($categories as $c) {
			array_push($classes, ...(self::CATEGORIES[$c] ?? []));
		}
		$out = [];
		foreach ($scores as $id => $byClass) {
			if (($marks[$id] ?? true) === false) {
				continue;
			}
			foreach ($classes as $class) {
				if (($byClass[$class] ?? 0) >= $threshold) {
					$out[(int)$id] = true;
					break;
				}
			}
		}
		foreach ($marks as $id => $on) {
			if ($on) {
				$out[(int)$id] = true;
			}
		}
		return $out;
	}

	/** @return list<string> categories the user counts */
	public function categories(string $uid): array {
		return array_values(array_filter(array_keys(self::CATEGORIES), fn ($c) => (bool)$this->prefs->get($uid, 'sensitive_' . $c)));
	}

	/**
	 * What the detector found on files $uid can see: fileId => class => score. Built from the
	 * shared table, mapped to the user's file ids; cached until something new was scanned.
	 *
	 * @return array<int, array<string, float>>
	 */
	public function visibleScores(string $uid): array {
		if (isset($this->visible[$uid])) {
			return $this->visible[$uid];
		}
		$key = $uid . '/' . $this->scanner->version();
		$cached = $this->lists->get($key);
		if (is_array($cached)) {
			return $this->visible[$uid] = $cached;
		}
		$out = [];
		foreach ($this->scanner->candidates() as $assetId => $a) {
			$item = $this->assets->visibleItem($uid, ['id' => $assetId, 'originalPath' => $a['path'], 'type' => $a['type']], false);
			if ($item !== null) {
				foreach ($a['scores'] as $class => $score) {
					$out[$item['fileId']][$class] = max($out[$item['fileId']][$class] ?? 0, $score);
				}
			}
		}
		$this->lists->set($key, $out, self::LIST_TTL);
		return $this->visible[$uid] = $out;
	}

	private function threshold(string $uid): float {
		$t = (int)$this->prefs->get($uid, 'sensitive_threshold');
		return max(SensitiveScanner::FLOOR, min(0.99, $t / 100));
	}

	/** @return array<int, bool> */
	private function marks(string $uid): array {
		$out = [];
		foreach ($this->get($uid)['marks'] as $id => $on) {
			$out[(int)$id] = (bool)$on;
		}
		return $out;
	}

	private function save(string $uid, array $data): void {
		$this->folder()->newFile($uid . '.json', json_encode(['marks' => (object)$data['marks']]));
		$this->cache[$uid] = $data;
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
