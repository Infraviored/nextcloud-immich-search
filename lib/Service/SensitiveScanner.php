<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Service;

use OCA\Glimpse\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Looks at every picture once with the nudity detector (docker/detector: NudeNet) and keeps
 * what it found in `glimpse_sensitive`. It sees Immich's preview of each asset, never the
 * original file. NudeNet tells covered from uncovered: a bikini is FEMALE_BREAST_COVERED,
 * a nude FEMALE_BREAST_EXPOSED.
 *
 * Assets are listed through Immich's metadata search, in two streams (timeline, and archive
 * for the stills Glimpse takes from videos). The first pass pages through everything;
 * afterwards only what Immich changed since (`updatedAfter`).
 */
class SensitiveScanner {
	/** The detector classes Glimpse keeps; all others (faces, covered parts, feet) are dropped. */
	public const CLASSES = [
		'FEMALE_BREAST_EXPOSED', 'FEMALE_GENITALIA_EXPOSED', 'MALE_GENITALIA_EXPOSED',
		'BUTTOCKS_EXPOSED', 'ANUS_EXPOSED', 'MALE_BREAST_EXPOSED',
	];
	/** Scores below this are not kept: the lowest threshold a user can pick. */
	public const FLOOR = 0.2;
	private const STATE = 'sensitive_scan';
	private const LOCK = 'glimpse/sensitive-scan';
	private const PAGE = 200;
	private const STREAMS = ['timeline', 'archive'];

	public function __construct(
		private Config $config,
		private ImmichClient $immich,
		private IDBConnection $db,
		private IClientService $clients,
		private ILockingProvider $locks,
		private LoggerInterface $logger,
	) {
	}

	public function enabled(): bool {
		return (bool)$this->config->get('sensitive_detection') && $this->url() !== '';
	}

	/**
	 * Scan for up to $seconds. Returns how many pictures were looked at.
	 *
	 * @throws \OCP\Lock\LockedException when another run is going on
	 */
	public function run(int $seconds): int {
		$this->locks->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$end = time() + $seconds;
			$done = 0;
			foreach (self::STREAMS as $stream) {
				while (time() < $end) {
					$n = $this->page($stream, $end);
					if ($n === null) {
						break; // this stream is up to date
					}
					$done += $n;
				}
			}
			return $done;
		} finally {
			$this->locks->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** How far it is: pictures looked at, how many Immich has, whether the first pass is through. */
	public function status(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('glimpse_sensitive');
		$scanned = (int)$qb->executeQuery()->fetchOne();
		$total = null;
		try {
			$total = (int)($this->immich->json('GET', '/assets/statistics')['total'] ?? 0)
				+ (int)($this->immich->json('GET', '/assets/statistics?visibility=archive')['total'] ?? 0);
		} catch (ImmichException) {
		}
		$state = $this->config->getState(self::STATE);
		return [
			'scanned' => $scanned,
			'total' => $total,
			'firstPassDone' => count(array_filter(self::STREAMS, fn ($s) => isset($state[$s]['since']))) === count(self::STREAMS),
		];
	}

	/** Is the detector there? Error message, or null if it answers. */
	public function check(): ?string {
		if ($this->url() === '') {
			return 'The address of the detector is not set';
		}
		try {
			$resp = $this->clients->newClient()->get($this->url() . '/ping', ['timeout' => 5, 'http_errors' => false]);
			return $resp->getStatusCode() === 200 && str_contains((string)$resp->getBody(), 'pong')
				? null : 'The detector answered ' . $resp->getStatusCode();
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Changes when something new was scanned, at most every ten minutes: per-user lists built
	 * from the table are built again then (during the first pass not after every picture).
	 */
	public function version(): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('scanned'))->from('glimpse_sensitive');
		return (string)intdiv((int)$qb->executeQuery()->fetchOne(), 600);
	}

	/**
	 * Assets the detector found something on (any kept class at or above the floor), with the
	 * stills folded into their video: [assetId => ['path' => ..., 'type' => ..., 'scores' => [class => score]]].
	 */
	public function candidates(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('asset_id', 'path', 'type', 'video_id', 'scores')->from('glimpse_sensitive')
			->where($qb->expr()->gte('top', $qb->createNamedParameter(self::FLOOR, IQueryBuilder::PARAM_STR)));
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		$videos = [];
		foreach ($rows as $r) {
			$scores = json_decode((string)$r['scores'], true) ?: [];
			$id = $r['video_id'] ?: $r['asset_id'];
			if ($r['video_id']) {
				$videos[$id] = true;
			}
			$out[$id] ??= ['path' => null, 'type' => 'VIDEO', 'scores' => []];
			if (!$r['video_id']) {
				$out[$id]['path'] = $r['path'];
				$out[$id]['type'] = $r['type'];
			}
			foreach ($scores as $class => $score) {
				$out[$id]['scores'][$class] = max($out[$id]['scores'][$class] ?? 0, (float)$score);
			}
		}
		// Videos known only through their stills: their own path is in their own row
		$missing = array_keys(array_filter($out, fn ($a) => $a['path'] === null));
		foreach (array_chunk($missing, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('asset_id', 'path', 'type')->from('glimpse_sensitive')
				->where($qb->expr()->in('asset_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			foreach ($qb->executeQuery()->fetchAll() as $r) {
				$out[$r['asset_id']]['path'] = $r['path'];
				$out[$r['asset_id']]['type'] = $r['type'];
			}
		}
		return array_filter($out, fn ($a) => $a['path'] !== null);
	}

	/**
	 * One page of one stream. Null when the stream has nothing more to do.
	 */
	private function page(string $stream, int $end): ?int {
		$state = $this->config->getState(self::STATE);
		$s = $state[$stream] ?? [];
		$page = (int)($s['page'] ?? 1);
		$body = ['page' => $page, 'size' => self::PAGE, 'visibility' => $stream];
		if (isset($s['since'])) {
			$body['updatedAfter'] = $s['since'];
		}
		$started = $s['started'] ?? gmdate('Y-m-d\TH:i:s.000\Z');
		$res = $this->immich->json('POST', '/search/metadata', $body)['assets'] ?? [];
		$items = $res['items'] ?? [];
		$known = $this->scannedAt(array_column($items, 'id'));
		$done = 0;
		foreach ($items as $a) {
			if (time() >= $end) {
				return $done; // the same page again next time; done ones are skipped
			}
			if (!in_array($a['type'] ?? '', ['IMAGE', 'VIDEO'], true)) {
				continue;
			}
			$changed = strtotime((string)($a['updatedAt'] ?? '')) ?: 0;
			if (isset($known[$a['id']]) && $known[$a['id']] >= $changed) {
				continue;
			}
			$this->scan($a);
			$done++;
		}
		if (isset($res['nextPage'])) {
			$s['page'] = (int)$res['nextPage'];
			$s['started'] = $started;
		} else {
			// Through: from now on only what changed since this pass started
			$s = ['since' => $started];
		}
		$state[$stream] = $s;
		$this->config->setState(self::STATE, $state);
		return isset($res['nextPage']) || $done > 0 ? $done : null;
	}

	/** @return array<string, int> assetId => when it was scanned */
	private function scannedAt(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('asset_id', 'scanned')->from('glimpse_sensitive')
			->where($qb->expr()->in('asset_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$out[$r['asset_id']] = (int)$r['scanned'];
		}
		return $out;
	}

	private function scan(array $a): void {
		$scores = [];
		try {
			$img = $this->immich->binary('/assets/' . $a['id'] . '/thumbnail?size=preview');
			if ($img['body'] !== '' && str_starts_with((string)$img['type'], 'image/')) {
				$scores = $this->detect($img['body']);
			}
		} catch (ImmichException $e) {
			$this->logger->info('Sensitive scan, no preview for ' . $a['id'] . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		$still = VideoScenes::parseName((string)($a['originalFileName'] ?? ''));
		$row = [
			'path' => $a['originalPath'] ?? null,
			'type' => $a['type'],
			'video_id' => $still[0] ?? null,
			'scores' => json_encode((object)$scores),
			'top' => $scores === [] ? 0.0 : max($scores),
			'scanned' => time(),
		];
		$qb = $this->db->getQueryBuilder();
		$qb->delete('glimpse_sensitive')->where($qb->expr()->eq('asset_id', $qb->createNamedParameter($a['id'])));
		$qb->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('glimpse_sensitive')->values(['asset_id' => $qb->createNamedParameter($a['id'])]
			+ array_map(fn ($v) => $qb->createNamedParameter($v, is_float($v) ? IQueryBuilder::PARAM_STR : (is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)), $row));
		$qb->executeStatement();
	}

	/**
	 * Classes Glimpse keeps, with the highest score of each, from the floor up.
	 *
	 * @return array<string, float>
	 */
	private function detect(string $image): array {
		$resp = $this->clients->newClient()->post($this->url() . '/detect', [
			'body' => $image,
			'headers' => ['Content-Type' => 'application/octet-stream'],
			'timeout' => 30,
		]);
		$found = json_decode((string)$resp->getBody(), true)['detections'] ?? null;
		if (!is_array($found)) {
			throw new \RuntimeException('The detector answered without detections');
		}
		$out = [];
		foreach ($found as $d) {
			$class = (string)($d['class'] ?? '');
			$score = round((float)($d['score'] ?? 0), 3);
			if (in_array($class, self::CLASSES, true) && $score >= self::FLOOR) {
				$out[$class] = max($out[$class] ?? 0, $score);
			}
		}
		return $out;
	}

	private function url(): string {
		return rtrim((string)$this->config->get('sensitive_detector_url'), '/');
	}
}
