<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Names of the people a user may see, for search: "Tara" finds the person, "Tara beach"
 * becomes person Tara + text "beach". Immich's list is cached briefly; visibility comes
 * from PeopleIndex.
 */
class PeopleNames {
	private const TTL = 300;
	private ICache $cache;

	public function __construct(
		private PeopleIndex $index,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('glimpse_names');
	}

	/** @return array<string, array{name: string, count: int}> person id => name, named and visible only */
	public function visible(string $uid): array {
		$names = $this->cache->get('all');
		if (!is_array($names)) {
			$names = [];
			foreach ($this->index->allPeople() as $p) {
				if (($p['name'] ?? '') !== '' && !($p['isHidden'] ?? false)) {
					$names[$p['id']] = $p['name'];
				}
			}
			$this->cache->set('all', $names, self::TTL);
		}
		$mine = $this->index->forUser($uid)['people'];
		$out = [];
		foreach ($names as $id => $name) {
			if (isset($mine[$id])) {
				$out[$id] = ['name' => $name, 'count' => $mine[$id]['count']];
			}
		}
		return $out;
	}

	/** People whose name contains $term. @return list<array{id: string, name: string, count: int}> */
	public function find(string $uid, string $term): array {
		$term = mb_strtolower(trim($term));
		$hits = [];
		foreach ($this->visible($uid) as $id => $p) {
			if ($term !== '' && str_contains(mb_strtolower($p['name']), $term)) {
				$hits[] = ['id' => $id] + $p;
			}
		}
		usort($hits, static fn ($a, $b) => $b['count'] <=> $a['count']);
		return $hits;
	}

	/**
	 * Split "Tara beach" into the person and the rest of the query. The name has to stand at
	 * the start or the end, as a whole word; the longest matching name wins.
	 *
	 * @return array{0: ?string, 1: string} [person id or null, remaining text]
	 */
	public function split(string $uid, string $q): array {
		$people = $this->visible($uid);
		uasort($people, static fn ($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
		$lower = mb_strtolower(trim($q));
		foreach ($people as $id => $p) {
			$name = mb_strtolower($p['name']);
			if ($lower === $name) {
				return [$id, ''];
			}
			if (str_starts_with($lower, $name . ' ')) {
				return [$id, trim(mb_substr(trim($q), mb_strlen($name)))];
			}
			if (str_ends_with($lower, ' ' . $name)) {
				return [$id, trim(mb_substr(trim($q), 0, -mb_strlen($name)))];
			}
		}
		return [null, $q];
	}
}
