<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Immich smart (CLIP) search, filtered by Nextcloud's own permissions.
 *
 * Immich indexes every user's Nextcloud files read-only as external libraries
 * under one service account. Config::getPathPattern() says where: with
 * "/mnt/nextcloud/{user}/", the hit "/mnt/nextcloud/alice/Photos/a.jpg" is
 * alice's "/Photos/a.jpg". It is shown to the searching user only if that file
 * is reachable in their own Nextcloud (own file or shared with them), under the
 * path they know it by ("/Shared/Photos/a.jpg" for a share recipient).
 *
 * Assets that passed the check are remembered per user with their file id, so the
 * thumbnail endpoint can authorize without asking Immich again. Each use checks that
 * the file still resolves for the user: a revoked share ends access right away.
 */
class AssetSearch {
	public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	private const TYPES = ['IMAGE', 'VIDEO'];
	private const GRANT_TTL = 86400;
	/** Immich pages fetched at most per call when the permission filter drops most hits. */
	private const MAX_PAGES_PER_CALL = 5;
	/** With a tag filter, which keeps only a few hits: Immich page size and pages per call. */
	private const FILTERED_PAGE_SIZE = 500;
	private const MAX_PAGES_PER_CALL_FILTERED = 10;
	/** Owner lookups kept in memory, enough for one person's pictures in PeopleIndex. */
	private const OWNER_MEMO = 5000;
	/** Share types that can bring someone else's file into a user's tree. */
	private const SHARE_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_CIRCLE, IShare::TYPE_ROOM, IShare::TYPE_DECK];
	private const YEARS_TTL = 3600;

	private ICache $grants;
	private ICache $yearCache;
	/** Videos behind stills, so a search does not ask Immich for each one again */
	private ICache $videos;
	/** @var array<string, ?array> */
	private array $videoMemo = [];
	/** @var array<string, ?string> */
	private array $scopes = [];
	/** @var array<string, ?Folder> */
	private array $folders = [];
	/** @var array<string, array{0: ?string, 1: ?Node}> */
	private array $owners = [];
	private ?int $minSize = null;

	public function __construct(
		private ImmichClient $immich,
		private Config $config,
		private IRootFolder $rootFolder,
		private IUserManager $users,
		ICacheFactory $cacheFactory,
		private IShareManager $shares,
	) {
		// "_v2": grants hold the file id now; older entries (just 1) must not count
		$this->grants = $cacheFactory->createDistributed('glimpse_grants_v2');
		$this->yearCache = $cacheFactory->createDistributed('glimpse_years');
		$this->videos = $cacheFactory->createDistributed('glimpse_videos');
	}

	/**
	 * @param string $q free text; empty means newest first (useful with $person)
	 * @param ?array<int, true> $onlyFiles keep only these file ids (a tag filter), null for all.
	 *                                     Best only those the user can open: once all are found, the search stops.
	 * @return array{items: list<array>, nextPage: ?int} only items the user may see. Keeps
	 *                                                   fetching Immich pages until half a page is filled, so a user who may see little
	 *                                                   does not get a string of empty pages.
	 */
	public function search(string $uid, string $q, int $page, int $size, string $person = '', string $type = '', string $similar = '', ?array $onlyFiles = null): array {
		return $this->collect($uid, $page, $size, fn (int $p, int $s) => $this->immichPage($q, $p, $s, $person, $type, $similar), $similar, $onlyFiles, true);
	}

	/**
	 * Everything the user may see, newest first, taken before $before (ISO date) if given.
	 * The cursor is the Immich page within that window.
	 */
	/** @param ?array<int, true> $onlyFiles as in search() */
	public function timeline(string $uid, ?string $before, int $page, int $size, string $type = '', ?string $after = null, string $person = '', ?array $onlyFiles = null): array {
		if ($person !== '' && !preg_match(self::UUID, $person)) {
			return ['items' => [], 'nextPage' => null];
		}
		return $this->collect($uid, $page, $size, function (int $p, int $s) use ($uid, $before, $after, $type, $person) {
			// From a point in time back to the past, or (after) forward to today, oldest first
			// "timeline": without the stills Glimpse took from videos (VideoScenes)
			$body = ['page' => $p, 'size' => $s, 'order' => $after !== null ? 'asc' : 'desc', 'visibility' => 'timeline'];
			if ($before !== null) {
				$body['takenBefore'] = $before;
			}
			if ($after !== null) {
				$body['takenAfter'] = $after;
			}
			if (($own = $this->ownScope($uid)) !== null) {
				$body['originalPath'] = $own;
			}
			if (in_array($type, self::TYPES, true)) {
				$body['type'] = $type;
			}
			// One person's timeline (their page)
			if ($person !== '' && preg_match(self::UUID, $person)) {
				$body['personIds'] = [$person];
			}
			return $this->immich->json('POST', '/search/metadata', $body)['assets'] ?? [];
		}, '', $onlyFiles);
	}

	/**
	 * Years that have pictures the user may see, newest first.
	 *
	 * Immich's buckets cover every user's library. Someone who only sees their own files
	 * gets just the years their own folder has; with shares the full list stays (their
	 * pictures can be from any of those years).
	 */
	public function years(string $uid = '', ?int $seenYear = null): array {
		$own = $uid !== '' ? $this->ownScope($uid) : null;
		if ($own === null) {
			return $this->bucketYears();
		}
		$cached = $this->yearCache->get($uid);
		if (is_array($cached)) {
			if ($seenYear !== null && $seenYear > 0 && !in_array($seenYear, $cached, true)) {
				$this->yearCache->remove($uid);
			} else {
				return $cached;
			}
		}
		$years = $this->bucketYears();
		if ($years === []) {
			$this->yearCache->set($uid, [], self::YEARS_TTL);
			return [];
		}

		// Bound candidate years by finding the user's newest and oldest pictures
		$newest = $this->immich->json('POST', '/search/metadata', [
			'size' => 1,
			'originalPath' => $own,
			'order' => 'desc',
			'takenAfter' => '1970-01-01T00:00:00.000Z',
		])['assets']['items'][0] ?? null;
		if ($newest === null) {
			$this->yearCache->set($uid, [], self::YEARS_TTL);
			return [];
		}
		$newestYear = (int)substr((string)($newest['localDateTime'] ?? $newest['fileCreatedAt'] ?? $newest['date'] ?? ''), 0, 4);

		$oldest = $this->immich->json('POST', '/search/metadata', [
			'size' => 1,
			'originalPath' => $own,
			'order' => 'asc',
			'takenAfter' => '1970-01-01T00:00:00.000Z',
		])['assets']['items'][0] ?? null;
		$oldestYear = $oldest !== null ? (int)substr((string)($oldest['localDateTime'] ?? $oldest['fileCreatedAt'] ?? $oldest['date'] ?? ''), 0, 4) : $newestYear;

		$candidates = ($newestYear > 0 && $oldestYear > 0)
			? array_values(array_filter($years, fn (int $y) => $y >= $oldestYear && $y <= $newestYear))
			: $years;

		$mine = [];
		foreach ($candidates as $y) {
			if (($y === $newestYear || $y === $oldestYear) && $newestYear > 0) {
				$mine[] = $y;
				continue;
			}
			$has = ($this->immich->json('POST', '/search/metadata', [
				'size' => 1,
				'originalPath' => $own,
				'takenAfter' => sprintf('%04d-01-01T00:00:00.000Z', $y),
				'takenBefore' => sprintf('%04d-01-01T00:00:00.000Z', $y + 1),
			])['assets']['items'] ?? []) !== [];
			if ($has) {
				$mine[] = $y;
			}
		}
		$this->yearCache->set($uid, $mine, self::YEARS_TTL);
		return $mine;
	}

	/**
	 * Years in which a person is in pictures the user may see, newest first: Immich's buckets
	 * for that person, and for someone who sees only their own files, just the years their own
	 * folder has a picture of the person in (cached like years()).
	 *
	 * @return list<int>
	 */
	public function personYears(string $uid, string $person, ?int $seenYear = null): array {
		if (!preg_match(self::UUID, $person)) {
			return [];
		}
		$own = $this->ownScope($uid);
		$key = $uid . '/' . $person;
		$cached = $this->yearCache->get($key);
		if (is_array($cached)) {
			if ($seenYear !== null && $seenYear > 0 && !in_array($seenYear, $cached, true)) {
				$this->yearCache->remove($key);
			} else {
				return $cached;
			}
		}
		$years = [];
		foreach ($this->immich->json('GET', '/timeline/buckets?visibility=timeline&personId=' . rawurlencode($person)) as $b) {
			$years[(int)substr((string)$b['timeBucket'], 0, 4)] = true;
		}
		krsort($years);
		$years = array_keys($years);
		if ($own === null || $years === []) {
			$this->yearCache->set($key, $years, self::YEARS_TTL);
			return $years;
		}
		$mine = array_values(array_filter($years, fn (int $y) => ($this->immich->json('POST', '/search/metadata', [
			'size' => 1,
			'originalPath' => $own,
			'personIds' => [$person],
			'visibility' => 'timeline',
			'takenAfter' => sprintf('%04d-01-01T00:00:00.000Z', $y),
			'takenBefore' => sprintf('%04d-01-01T00:00:00.000Z', $y + 1),
		])['assets']['items'] ?? []) !== []));
		$this->yearCache->set($key, $mine, self::YEARS_TTL);
		return $mine;
	}

	/** @return list<int> all years present across the instance, newest first */
	private function bucketYears(): array {
		$years = [];
		foreach ($this->immich->json('GET', '/timeline/buckets') as $b) {
			$years[(int)substr((string)$b['timeBucket'], 0, 4)] = true;
		}
		krsort($years);
		return array_keys($years);
	}

	/**
	 * The part of Immich's paths that only the user's own files have, when nothing is
	 * shared with them: Immich can then skip everyone else's pictures itself instead of
	 * this app paging through them. Null when the user may see files of others.
	 */
	private function ownScope(string $uid): ?string {
		if (array_key_exists($uid, $this->scopes)) {
			return $this->scopes[$uid];
		}
		$pattern = $this->config->getPathPattern();
		if (!str_contains($pattern, Config::USER_PLACEHOLDER)) {
			return $this->scopes[$uid] = null;
		}
		$pattern = rtrim($pattern, '/') . '/';
		$scope = str_replace(Config::USER_PLACEHOLDER, $uid, $pattern);
		if (!str_ends_with($scope, '/')) {
			$scope .= '/';
		}
		foreach (self::SHARE_TYPES as $type) {
			// Types whose app is not installed give an empty list
			if ($this->shares->getSharedWith($uid, $type, null, 1) !== []) {
				$scope = null;
				break;
			}
		}
		return $this->scopes[$uid] = $scope;
	}

	/**
	 * Page through Immich results until half a page of visible items is filled.
	 *
	 * @param callable(int $page, int $size): array $fetch returns Immich's "assets" block
	 * @param ?array<int, true> $onlyFiles keep only these file ids, null for all
	 * @param bool $stills a still taken from a video stands for the video (searches only)
	 */
	private function collect(string $uid, int $page, int $size, callable $fetch, string $skipId = '', ?array $onlyFiles = null, bool $stills = false): array {
		$size = max(1, min(200, $size));
		$items = [];
		$next = max(1, $page);
		if ($onlyFiles === []) {
			return ['items' => [], 'nextPage' => null];
		}
		$maxPages = $onlyFiles === null ? self::MAX_PAGES_PER_CALL : self::MAX_PAGES_PER_CALL_FILTERED;
		// The cursor is an Immich page, so a filtered search keeps its page size on every call
		$fetchSize = $onlyFiles === null ? $size : self::FILTERED_PAGE_SIZE;
		$want = $onlyFiles === null ? intdiv($size + 1, 2) : $size;
		// A video found by several of its stills (or by its own) is listed once, at its best place
		$seen = [];
		for ($i = 0; $i < $maxPages && $next !== null && count($items) < $want; $i++) {
			if ($onlyFiles !== null && $page <= 1 && count($items) >= count($onlyFiles)) {
				break;
			}
			$assets = $fetch($next, $fetchSize);
			foreach ($assets['items'] ?? [] as $a) {
				if ($a['id'] === $skipId) {
					continue; // "more like this": the picture itself is its best match
				}
				$item = $this->visibleItem($uid, $a, true, $stills);
				if ($item !== null && $item['id'] !== $skipId && !isset($seen[$item['fileId']]) && ($onlyFiles === null || isset($onlyFiles[$item['fileId']]))) {
					$seen[$item['fileId']] = true;
					$items[] = $item;
				}
			}
			$next = isset($assets['nextPage']) ? (int)$assets['nextPage'] : null;
		}
		// A search from the start that found every tagged file has nothing more to look for
		if ($onlyFiles !== null && $page <= 1 && count(array_unique(array_column($items, 'fileId'))) >= count($onlyFiles)) {
			$next = null;
		}
		return ['items' => $items, 'nextPage' => $next];
	}

	/**
	 * The Immich asset of a Nextcloud file the user can reach: back from the file to the
	 * owner's path, then to the path Immich sees (the reverse of nodeFor()).
	 */
	public function assetForFile(string $uid, int $fileId): ?array {
		$path = $this->immichPathOf($uid, $fileId);
		if ($path === null) {
			return null;
		}
		$hit = $this->immich->json('POST', '/search/metadata', ['originalPath' => $path, 'size' => 1])['assets']['items'][0] ?? null;
		return $hit !== null && ($hit['originalPath'] ?? '') === $path ? $this->visibleItem($uid, $hit) : null;
	}

	/** Where Immich sees a file $uid can reach, or null if they cannot. */
	public function immichPathOf(string $uid, int $fileId): ?string {
		$node = $this->userFolder($uid)?->getFirstNodeById($fileId);
		$owner = $node?->getOwner()?->getUID();
		$ownerNode = $owner !== null ? $this->userFolder($owner)?->getFirstNodeById($fileId) : null;
		if ($ownerNode === null) {
			return null;
		}
		$rel = ltrim($this->userFolder($owner)->getRelativePath($ownerNode->getPath()), '/');
		return str_replace(Config::USER_PLACEHOLDER, $owner, $this->config->getPathPattern()) . $rel;
	}

	/** May $uid see this asset? Asks Immich for its path if the user has not been shown it yet. */
	public function maySee(string $uid, string $assetId): bool {
		if (!preg_match(self::UUID, $assetId)) {
			return false;
		}
		$fileId = $this->grants->get($uid . '/' . $assetId);
		if (is_int($fileId) && $this->userFolder($uid)?->getFirstNodeById($fileId) !== null) {
			return true;
		}
		try {
			$asset = $this->immich->json('GET', '/assets/' . $assetId);
		} catch (ImmichException) {
			return false;
		}
		return $this->visibleItem($uid, $asset, true, true) !== null;
	}

	/** The video a still was taken from, or null if Immich no longer has it. */
	private function videoAsset(string $id): ?array {
		if (array_key_exists($id, $this->videoMemo)) {
			return $this->videoMemo[$id];
		}
		$cached = $this->videos->get($id);
		if (!is_array($cached)) {
			try {
				$a = $this->immich->json('GET', '/assets/' . $id);
				$cached = ($a['type'] ?? '') === 'VIDEO' && !($a['isTrashed'] ?? false)
					? array_intersect_key($a, array_flip(['id', 'type', 'originalPath', 'originalMimeType', 'localDateTime', 'fileCreatedAt', 'width', 'height', 'duration']))
					: [];
			} catch (ImmichException) {
				$cached = [];
			}
			$this->videos->set($id, $cached, 3600);
		}
		return $this->videoMemo[$id] = $cached !== [] ? $cached : null;
	}

	/** The owner's file behind an Immich path (whoever asks), or null. */
	public function ownerFile(string $immichPath): ?Node {
		return $this->ownerNode($immichPath)[1];
	}

	/** @return list<array{id: string, name: string}> named, visible people, sorted by name */
	public function people(): array {
		$raw = $this->immich->json('GET', '/people?withHidden=false&size=1000')['people'] ?? [];
		$people = [];
		foreach ($raw as $p) {
			if (($p['name'] ?? '') !== '') {
				$people[] = ['id' => $p['id'], 'name' => $p['name']];
			}
		}
		usort($people, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $people;
	}

	private function immichPage(string $q, int $page, int $size, string $person, string $type, string $similar = ''): array {
		$body = ['page' => $page, 'size' => $size];
		if (in_array($type, self::TYPES, true)) {
			$body['type'] = $type;
		}
		if ($person !== '' && preg_match(self::UUID, $person)) {
			$body['personIds'] = [$person];
		}
		if ($similar !== '' && preg_match(self::UUID, $similar)) {
			// "More like this": rank by the picture's own embedding instead of a text
			$endpoint = '/search/smart';
			$body['queryAssetId'] = $similar;
		} elseif ($q !== '') {
			$endpoint = '/search/smart';
			$body['query'] = $q;
		} else {
			// Smart search needs text to rank by; metadata search lists by date instead.
			$endpoint = '/search/metadata';
			$body['order'] = 'desc';
			$body['visibility'] = 'timeline';
		}
		return $this->immich->json('POST', $endpoint, $body)['assets'] ?? [];
	}

	/**
	 * The hit as $uid sees it, or null if the file is not reachable for $uid.
	 * $grant remembers the asset for the thumbnail check; bulk callers (PeopleIndex) skip that.
	 */
	public function visibleItem(string $uid, array $a, bool $grant = true, bool $stills = false): ?array {
		// A still Glimpse took from a video (VideoScenes): stands for that video, at that moment
		$still = VideoScenes::parseName((string)($a['originalFileName'] ?? ''));
		if ($still !== null) {
			$video = $stills ? $this->videoAsset($still[0]) : null;
			$item = $video !== null ? $this->visibleItem($uid, $video, $grant) : null;
			if ($item === null) {
				return null;
			}
			if ($grant) {
				$this->grants->set($uid . '/' . $a['id'], $item['fileId'], self::GRANT_TTL);
			}
			return $item + ['at' => $still[1], 'thumbId' => $a['id']];
		}
		if ($this->tooSmallOrIcon($a)) {
			return null;
		}
		$node = $this->nodeFor($uid, $a['originalPath'] ?? '');
		if ($node === null) {
			return null;
		}
		if ($grant) {
			$this->grants->set($uid . '/' . $a['id'], $node->getId(), self::GRANT_TTL);
		}
		return [
			'id' => $a['id'],
			'type' => $a['type'] ?? 'IMAGE',
			'date' => $a['localDateTime'] ?? $a['fileCreatedAt'] ?? null,
			'name' => $node->getName(),
			'path' => $this->userFolder($uid)->getRelativePath($node->getPath()),
			'fileId' => $node->getId(),
			'mime' => $node->getMimetype(),
		];
	}

	/** Vector icons and tiny images: the index matches them to almost any query. */
	private function tooSmallOrIcon(array $a): bool {
		if (str_starts_with((string)($a['originalMimeType'] ?? ''), 'image/svg')) {
			return true;
		}
		$min = $this->minSize ??= $this->config->getMinSize();
		$w = (int)($a['width'] ?? 0);
		$h = (int)($a['height'] ?? 0);
		// Unknown size (0) is let through: videos and some formats have none.
		return $min > 0 && $w > 0 && $h > 0 && min($w, $h) < $min;
	}

	private function nodeFor(string $uid, string $immichPath): ?Node {
		[$owner, $file] = $this->ownerNode($immichPath);
		$mine = $this->userFolder($uid);
		if ($file === null || $mine === null) {
			return null;
		}
		if ($owner === $uid) {
			return $file;
		}
		// A file has the same id everywhere; it resolves in $uid's tree only if shared with them.
		return $mine->getFirstNodeById($file->getId());
	}

	/**
	 * Owner and owner's node for an Immich path, memoized: PeopleIndex asks once per user.
	 * @return array{0: ?string, 1: ?Node}
	 */
	private function ownerNode(string $immichPath): array {
		if (array_key_exists($immichPath, $this->owners)) {
			return $this->owners[$immichPath];
		}
		if (count($this->owners) > self::OWNER_MEMO) {
			$this->owners = [];
		}
		[$owner, $rel] = $this->splitImmichPath($immichPath) ?? [null, null];
		$folder = $owner !== null ? $this->userFolder($owner) : null;
		$node = null;
		if ($folder !== null) {
			try {
				$node = $folder->get($rel);
			} catch (NotFoundException) {
				// moved or deleted since Immich last scanned
			}
		}
		return $this->owners[$immichPath] = [$owner, $node];
	}

	/** "/mnt/nextcloud/alice/Photos/a.jpg" -> ["alice", "Photos/a.jpg"], null if outside the pattern. */
	private function splitImmichPath(string $path): ?array {
		[$before, $after] = explode(Config::USER_PLACEHOLDER, $this->config->getPathPattern(), 2);
		if (!str_starts_with($path, $before)) {
			return null;
		}
		$rest = substr($path, strlen($before));
		$end = $after === '' ? false : strpos($rest, $after);
		if ($end === false || $end === 0) {
			return null;
		}
		$owner = substr($rest, 0, $end);
		$rel = substr($rest, $end + strlen($after));
		return str_contains($owner, '/') || $rel === '' ? null : [$owner, $rel];
	}

	private function userFolder(string $uid): ?Folder {
		if (!array_key_exists($uid, $this->folders)) {
			$this->folders[$uid] = $this->users->userExists($uid) ? $this->rootFolder->getUserFolder($uid) : null;
		}
		return $this->folders[$uid];
	}
}
