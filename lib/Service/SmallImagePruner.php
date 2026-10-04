<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use Psr\Log\LoggerInterface;

/**
 * Takes tiny images out of Immich for good.
 *
 * Immich can only exclude by path, not by size, and reads the size only after importing.
 * So this runs right behind it: images whose shorter side is below the minimum are
 * trashed in Immich (the Nextcloud file is untouched – the mount is read-only) and their
 * path is added to the libraries' exclusion patterns, so they are not imported again when
 * Immich empties its trash. A folder that holds only tiny images is excluded as a whole.
 *
 * Immich allows at most 128 exclusion patterns per library, so the entries are packed into
 * brace patterns "{/a/x.png,/b/icons/*,…}" (see pack()). Each must stay under 10000
 * characters: longer ones make Immich's disk crawl fail for the whole library (tested on
 * 3.2: 7.6 kB works and excludes; 28 kB and 70 kB break the scan).
 *
 * Tested on Immich 3.2: a trashed library asset stays trashed across library scans.
 */
class SmallImagePruner {
	private const PAGE = 1000;
	/** A folder with at least this many tiny images and no normal one is excluded as a whole. */
	private const FOLDER_MIN = 3;
	/** Longest brace pattern, safely under Immich's 10000-character limit. */
	private const PACK_MAX = 8000;

	public function __construct(
		private ImmichClient $immich,
		private Config $config,
		private IndexAdmin $index,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param bool $full look at every image, not only those changed since the last run
	 * @return array{checked: int, pruned: int, patterns: int}
	 */
	public function run(bool $full = false): array {
		$min = (int)$this->config->get('min_size');
		if ($min <= 0 || !$this->config->get('prune_small')) {
			return ['checked' => 0, 'pruned' => 0, 'patterns' => count($this->config->getState('pruned_entries'))];
		}
		$state = $this->config->getState('prune');
		$since = $full ? null : ($state['since'] ?? null);
		$started = gmdate('Y-m-d\TH:i:s.v\Z');

		$small = [];      // id => path
		$folders = [];    // folder => [small, normal]
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
				$checked++;
				$w = (int)($a['width'] ?? 0);
				$h = (int)($a['height'] ?? 0);
				$folder = dirname((string)$a['originalPath']);
				$folders[$folder] ??= [0, 0];
				if ($w > 0 && $h > 0 && min($w, $h) < $min) {
					$small[$a['id']] = (string)$a['originalPath'];
					$folders[$folder][0]++;
				} else {
					$folders[$folder][1]++;
				}
			}
			$page = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
		}

		$patterns = $this->config->getState('pruned_entries');
		foreach ($small as $path) {
			$folder = dirname($path);
			[$tiny, $normal] = $folders[$folder];
			// Whole folder only when this run saw all of it (a full run) and it is all tiny.
			$patterns[] = ($since === null && $normal === 0 && $tiny >= self::FOLDER_MIN)
				? self::escape($folder) . '/*'
				: self::escape($path);
		}
		$patterns = array_values(array_unique($patterns));

		if ($small !== []) {
			// Exclude first, then trash: if the trash call fails, the next scan still skips them.
			$this->config->setState('pruned_entries', $patterns);
			$this->index->applyExclusions(false);
			foreach (array_chunk(array_keys($small), 500) as $ids) {
				$this->immich->json('DELETE', '/assets', ['ids' => $ids, 'force' => false]);
			}
			$this->logger->info(sprintf('Pruned %d tiny images (%d checked)', count($small), $checked), ['app' => 'immich_search']);
		}
		$total = (int)($state['total'] ?? 0) + count($small);
		$this->config->setState('prune', ['since' => $started, 'last' => time(), 'pruned' => count($small), 'total' => $total]);
		return ['checked' => $checked, 'pruned' => count($small), 'patterns' => count($patterns)];
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
