<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

/**
 * Things Immich already works out for the whole library – "on this day" memories and
 * duplicate groups – narrowed down to what one user may see.
 */
class Discover {
	public function __construct(
		private ImmichClient $immich,
		private AssetSearch $assets,
	) {
	}

	/** @return list<array{year: int, items: list<array>}> today's memories, one per year */
	public function onThisDay(string $uid, string $date): array {
		$out = [];
		foreach ($this->immich->json('GET', '/memories?for=' . rawurlencode($date)) as $m) {
			if (($m['type'] ?? '') !== 'on_this_day') {
				continue;
			}
			$items = array_values(array_filter(array_map(fn ($a) => $this->assets->visibleItem($uid, $a), $m['assets'] ?? [])));
			if ($items !== []) {
				$out[] = ['year' => (int)($m['data']['year'] ?? 0), 'items' => $items];
			}
		}
		usort($out, static fn ($a, $b) => $b['year'] <=> $a['year']);
		return $out;
	}

	/**
	 * Duplicate groups with at least two pictures the user may see, with what is needed to
	 * choose: size, resolution, date, path. Immich's suggestion of which to keep comes along.
	 *
	 * @return list<array{id: string, keep: list<string>, items: list<array>}>
	 */
	public function duplicates(string $uid): array {
		$out = [];
		foreach ($this->immich->json('GET', '/duplicates') as $g) {
			$items = [];
			foreach ($g['assets'] ?? [] as $a) {
				$item = $this->assets->visibleItem($uid, $a);
				if ($item !== null) {
					$item['bytes'] = (int)($a['exifInfo']['fileSizeInByte'] ?? 0);
					$item['width'] = (int)($a['width'] ?? $a['exifInfo']['exifImageWidth'] ?? 0);
					$item['height'] = (int)($a['height'] ?? $a['exifInfo']['exifImageHeight'] ?? 0);
					$items[] = $item;
				}
			}
			if (count($items) >= 2) {
				$out[] = ['id' => $g['duplicateId'], 'keep' => $g['suggestedKeepAssetIds'] ?? [], 'items' => $items];
			}
		}
		return $out;
	}

	/** "These are not duplicates": Immich forgets the group. */
	public function dismissDuplicate(string $uid, string $groupId): void {
		$this->immich->json('DELETE', '/duplicates/' . rawurlencode($groupId));
	}
}
