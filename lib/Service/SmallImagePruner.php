<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Takes tiny images out of Immich for good.
 *
 * Immich can only exclude by path, not by size, and reads the size only after importing.
 * So this runs right behind it: images whose shorter side is below the minimum are
 * trashed in Immich (the Nextcloud file is untouched – the mount is read-only) and their
 * path is added to the libraries' exclusion patterns, so they are not imported again when
 * Immich empties its trash. A folder that holds only tiny images is excluded as a whole,
 * but only for the file types found there ("icons/*.{gif,png}"): videos and photos of
 * other types that land there later are still indexed.
 *
 * Only assets of the Nextcloud libraries are looked at (library set, path under the path
 * pattern): what the service account uploaded itself has no copy in Nextcloud.
 *
 * Immich allows at most 128 exclusion patterns per library, so the entries are packed into
 * brace patterns "{/a/x.png,/b/icons/*.png,…}" (see pack()). Each must stay under 10000
 * characters: longer ones make Immich's disk crawl fail for the whole library (tested on
 * 3.2: 7.6 kB works and excludes; 28 kB and 70 kB break the scan).
 *
 * State: "pruned_entries" is the list of entries (escaped globs) the exclusion patterns are
 * built from; "pruned_paths" maps each entry to the real path it stands for (file, or the
 * folder of a folder entry), so a full run can tell which entries still matter.
 *
 * Tested on Immich 3.2: a trashed library asset stays trashed across library scans.
 */
class SmallImagePruner {
	/** Lock held while entries are computed and written; also taken by callers changing them. */
	public const LOCK = 'glimpse/prune';
	private const PAGE = 1000;
	/** A folder with at least this many tiny images and no normal one is excluded as a whole. */
	private const FOLDER_MIN = 3;
	/** Longest brace pattern, safely under Immich's 10000-character limit. */
	private const PACK_MAX = 8000;

	public function __construct(
		private ImmichClient $immich,
		private Config $config,
		private IndexAdmin $index,
		private IRootFolder $rootFolder,
		private ILockingProvider $locks,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run $fn while no other prune run (background job, occ, admin page) does.
	 *
	 * @throws LockedException when one is running
	 */
	public function exclusive(callable $fn): mixed {
		$this->locks->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'Glimpse: tiny images');
		try {
			return $fn();
		} finally {
			$this->locks->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @param bool $full look at every image, not only those changed since the last run
	 * @return array{checked: int, pruned: int, patterns: int}
	 * @throws LockedException when another run is going on
	 */
	public function run(bool $full = false): array {
		$min = (int)$this->config->get('min_size');
		if ($min <= 0 || !$this->config->get('prune_small')) {
			return ['checked' => 0, 'pruned' => 0, 'patterns' => count($this->config->getState('pruned_entries'))];
		}
		return $this->exclusive(fn () => $this->prune($full, $min));
	}

	/**
	 * Forget every entry and write the exclusions without them. The way out when the list
	 * no longer fits Immich's limits; a full run afterwards builds it up again (it sees the
	 * images trashed before, too).
	 *
	 * @return int exclusion patterns written per library, summed
	 * @throws LockedException when a run is going on
	 */
	public function reset(): int {
		return $this->exclusive(function () {
			// Written first, saved after: a failure leaves the old entries, which still match Immich.
			$applied = $this->index->applyExclusions(false, []);
			$this->clear();
			return $applied;
		});
	}

	/** Forget every entry, without touching Immich (the caller applies the exclusions). Hold the lock. */
	public function clear(): void {
		$this->config->setState('pruned_entries', []);
		$this->config->setState('pruned_paths', []);
	}

	/** @return array{checked: int, pruned: int, patterns: int} */
	private function prune(bool $full, int $min): array {
		$state = $this->config->getState('prune');
		$since = $full ? null : ($state['since'] ?? null);
		$started = gmdate('Y-m-d\TH:i:s.v\Z');
		[$prefix] = explode(Config::USER_PLACEHOLDER, $this->config->getPathPattern(), 2);

		$small = [];      // id => path
		$trash = [];      // ids not in Immich's trash yet
		$folders = [];    // folder => [small, normal, [extension of small ones => true]]
		$seen = [];       // path => true, on a full run
		$checked = 0;
		for ($page = 1; $page !== null;) {
			$body = ['type' => 'IMAGE', 'size' => self::PAGE, 'page' => $page];
			if ($since !== null) {
				$body['updatedAfter'] = $since;
			} else {
				// A full run rebuilds the list, so it includes what an earlier run already trashed.
				$body['withDeleted'] = true;
			}
			$res = $this->immich->json('POST', '/search/metadata', $body)['assets'] ?? [];
			foreach ($res['items'] ?? [] as $a) {
				if (!self::isLibraryAsset($a, $prefix)) {
					continue;
				}
				$checked++;
				$path = (string)$a['originalPath'];
				$w = (int)($a['width'] ?? 0);
				$h = (int)($a['height'] ?? 0);
				$folder = dirname($path);
				$folders[$folder] ??= [0, 0, []];
				if ($since === null) {
					$seen[$path] = true;
				}
				if ($w > 0 && $h > 0 && min($w, $h) < $min) {
					$small[$a['id']] = $path;
					if (empty($a['isTrashed'])) {
						$trash[] = $a['id'];
					}
					$folders[$folder][0]++;
					$folders[$folder][2][pathinfo($path, PATHINFO_EXTENSION)] = true;
				} else {
					$folders[$folder][1]++;
				}
			}
			$page = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
		}

		$oldEntries = $this->config->getState('pruned_entries');
		$oldPaths = $this->config->getState('pruned_paths');
		$found = self::entries($small, $folders, $since === null);
		if ($since === null) {
			// Rebuilt from what this run saw; an old entry Immich no longer has (its trash was
			// emptied) stays as long as the file is still in Nextcloud, or it would come back.
			$paths = $found + self::keep($oldEntries, $oldPaths, $seen, $folders, fn (string $p) => $this->existsInNextcloud($p));
		} else {
			$paths = self::legacyPaths($oldEntries, $oldPaths) + $found;
		}
		$entries = array_keys($paths);
		sort($entries);
		$entries = array_map('strval', $entries);

		if ($entries !== $oldEntries) {
			// Exclude first, then save, then trash: a failed write keeps the old list (which is
			// what Immich has), and a failed trash call is fine as the next scan skips them.
			$this->index->applyExclusions(false, $entries);
			$this->config->setState('pruned_entries', $entries);
			$this->config->setState('pruned_paths', $paths);
		}
		foreach (array_chunk($trash, 500) as $ids) {
			$this->immich->json('DELETE', '/assets', ['ids' => $ids, 'force' => false]);
		}
		if ($trash !== []) {
			$this->logger->info(sprintf('Pruned %d tiny images (%d checked)', count($trash), $checked), ['app' => 'glimpse']);
		}
		$total = (int)($state['total'] ?? 0) + count($trash);
		$this->config->setState('prune', ['since' => $started, 'last' => time(), 'pruned' => count($trash), 'total' => $total]);
		return ['checked' => $checked, 'pruned' => count($trash), 'patterns' => count($entries)];
	}

	/**
	 * An asset of one of the Nextcloud libraries: in a library (not uploaded) and below the
	 * part of the path pattern before "{user}".
	 */
	public static function isLibraryAsset(array $asset, string $prefix): bool {
		return ($asset['libraryId'] ?? null) !== null && $asset['libraryId'] !== ''
			&& str_starts_with((string)($asset['originalPath'] ?? ''), $prefix);
	}

	/**
	 * Entries for the tiny images found, as [entry => real path].
	 *
	 * @param array<string, string> $small id => path
	 * @param array<string, array{0: int, 1: int, 2: array<string, true>}> $folders folder => [tiny, normal, extensions of the tiny ones]
	 * @param bool $whole the run saw every image (only then is a folder known to be all tiny)
	 * @return array<string, string>
	 */
	public static function entries(array $small, array $folders, bool $whole): array {
		$out = [];
		foreach ($small as $path) {
			$folder = dirname($path);
			[$tiny, $normal, $exts] = $folders[$folder];
			if ($whole && $normal === 0 && $tiny >= self::FOLDER_MIN && !isset($exts[''])) {
				$out[self::folderGlob($folder, array_map('strval', array_keys($exts)))] = $folder;
			} else {
				$out[self::escape($path)] = $path;
			}
		}
		return $out;
	}

	/** "folder/*.png" or "folder/*.{gif,png}": only these types, not every file in it. */
	public static function folderGlob(string $folder, array $extensions): string {
		$exts = array_values(array_unique(array_map([self::class, 'escape'], $extensions)));
		sort($exts);
		return self::escape($folder) . '/*.' . (count($exts) === 1 ? $exts[0] : '{' . implode(',', $exts) . '}');
	}

	/**
	 * Old entries a full run did not see again and that still exclude something.
	 *
	 * Seen again (the file, or anything in the folder) means it was judged afresh and is in
	 * the new list if it still qualifies. Not seen: Immich deleted it from its trash; kept
	 * while the file or folder is still in Nextcloud. Old entries with no known real path
	 * (written by earlier versions, with special characters) are kept.
	 *
	 * @param list<string> $entries
	 * @param array<string, string> $paths entry => real path
	 * @param array<string, true> $seen paths this run saw
	 * @param array<string, mixed> $folders folders this run saw
	 * @param callable(string): bool $exists is the file or folder still in Nextcloud
	 * @return array<string, string> entry => real path ('' if unknown)
	 */
	public static function keep(array $entries, array $paths, array $seen, array $folders, callable $exists): array {
		$out = [];
		foreach (self::legacyPaths($entries, $paths) as $entry => $real) {
			if ($real === '') {
				$out[$entry] = '';
			} elseif (!isset($seen[$real]) && !isset($folders[$real]) && $exists($real)) {
				$out[$entry] = $real;
			}
		}
		return $out;
	}

	/**
	 * Entries with their real path; for entries from before "pruned_paths" it is derived when
	 * the entry holds no "?" (escaped character), else ''.
	 *
	 * @return array<string, string>
	 */
	public static function legacyPaths(array $entries, array $paths): array {
		$out = [];
		foreach ($entries as $entry) {
			$entry = (string)$entry;
			$real = $paths[$entry] ?? null;
			if ($real === null) {
				$plain = str_ends_with($entry, '/*') ? substr($entry, 0, -2) : $entry;
				$real = strpbrk($plain, '?*{') === false ? $plain : '';
			}
			$out[$entry] = (string)$real;
		}
		return $out;
	}

	/**
	 * [user, path in their files] for a path as Immich sees it, or null if it is not under
	 * the path pattern.
	 *
	 * @return ?array{0: string, 1: string}
	 */
	public static function nextcloudPath(string $pattern, string $path): ?array {
		[$before, $after] = explode(Config::USER_PLACEHOLDER, $pattern, 2);
		if (!str_starts_with($path, $before)) {
			return null;
		}
		$rest = substr($path, strlen($before));
		$slash = strpos($rest, '/');
		$user = $slash === false ? $rest : substr($rest, 0, $slash);
		$tail = substr($rest, strlen($user)) . '/';
		if ($user === '' || !str_starts_with($tail, $after)) {
			return null;
		}
		return [$user, rtrim(substr($tail, strlen($after)), '/')];
	}

	private function existsInNextcloud(string $path): bool {
		$at = self::nextcloudPath($this->config->getPathPattern(), $path);
		if ($at === null) {
			return false;
		}
		try {
			return $at[1] === '' || $this->rootFolder->getUserFolder($at[0])->nodeExists($at[1]);
		} catch (\OC\User\NoUserException) {
			return false;
		} catch (\Throwable $e) {
			// Unsure: keep the entry rather than let the images back in.
			$this->logger->info('Prune: could not check ' . $path . ': ' . $e->getMessage(), ['app' => 'glimpse']);
			return true;
		}
	}

	/**
	 * Make a literal path safe as a glob, also inside a brace list.
	 *
	 * Special characters become "?" (any one character) instead of being backslash-escaped:
	 * Immich turns each pattern into a regex with picomatch and runs it in Postgres too,
	 * and an escaped "{…}" from a file name like "AlbumArt_{50CD…}.jpg" reaches Postgres as
	 * a repetition count, which fails the library scan. "?" means the same everywhere.
	 */
	public static function escape(string $path): string {
		return preg_replace('/[\\\\*?\[\]{}()!+@,]/', '?', $path);
	}

	/**
	 * Pack entries into as few brace patterns as fit PACK_MAX characters each.
	 *
	 * @param list<string> $entries escaped paths or folder globs
	 * @return list<string>
	 */
	public static function pack(array $entries, int $max = self::PACK_MAX): array {
		$out = [];
		$chunk = [];
		$len = 2;
		foreach ($entries as $e) {
			if ($chunk !== [] && $len + strlen($e) + 1 > $max) {
				$out[] = self::brace($chunk);
				$chunk = [];
				$len = 2;
			}
			$chunk[] = $e;
			$len += strlen($e) + 1;
		}
		if ($chunk !== []) {
			$out[] = self::brace($chunk);
		}
		return $out;
	}

	private static function brace(array $chunk): string {
		return count($chunk) === 1 ? $chunk[0] : '{' . implode(',', $chunk) . '}';
	}
}
