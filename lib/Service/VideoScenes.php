<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Search inside videos: Immich understands a video by one still. Glimpse takes more stills
 * from each video (every 15 s or so, at most 40) and hands them to Immich as archived
 * pictures named "glimpse-frame_<video id>_<ms>.jpg". Immich analyses them like any picture,
 * so a text search finds the moment; AssetSearch turns such a hit back into the video,
 * opened at that moment.
 *
 * Stills are cut with ffmpeg from the file in Nextcloud and uploaded through Immich's API:
 * Immich itself only reads the users' folders and never sees Glimpse's app data.
 */
class VideoScenes {
	public const PREFIX = 'glimpse-frame_';
	/** Still names: glimpse-frame_<uuid>_<milliseconds>.jpg */
	public const NAME = '/^glimpse-frame_([0-9a-f-]{36})_(\d+)\.jpg$/';
	private const STATE = 'video_scenes';
	private const LOCK = 'glimpse/video-scenes';
	private const PAGE = 500;
	/** Longest side of a still: enough for the analysis (384 px) and a sharp tile */
	private const WIDTH = 768;
	private const FAILED = -1;
	private const NO_FILE = -2;

	public function __construct(
		private Config $config,
		private ImmichClient $immich,
		private AssetSearch $assets,
		private ILockingProvider $locks,
		private LoggerInterface $logger,
	) {
	}

	public function enabled(): bool {
		return (bool)$this->config->get('video_scenes');
	}

	public static function ffmpeg(): ?string {
		foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg'] as $path) {
			if (is_executable($path)) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * When to take stills, in seconds. Short clips: two; then one every 15 s; long videos
	 * spread 40 over their length. The start is left out: Immich already has a still from there.
	 *
	 * @return list<float>
	 */
	public static function moments(float $duration): array {
		if ($duration < 6) {
			return [];
		}
		if ($duration < 30) {
			return [round($duration * 0.4, 1), round($duration * 0.8, 1)];
		}
		$step = max(15.0, $duration / 40);
		$out = [];
		for ($t = $step / 2; $t < $duration - 1; $t += $step) {
			$out[] = round($t, 1);
		}
		return $out;
	}

	/** Immich's duration in seconds: milliseconds since Immich 3, "H:MM:SS.mmm" before; 0 when missing. */
	public static function seconds(int|string|null $duration): float {
		if (is_int($duration)) {
			return $duration / 1000;
		}
		if ($duration === null || !preg_match('/^(\d+):(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', $duration, $m)) {
			return 0.0;
		}
		return (int)$m[1] * 3600 + (int)$m[2] * 60 + (float)$m[3];
	}

	/** @return ?array{0: string, 1: float} video id and second of a still's file name */
	public static function parseName(string $name): ?array {
		return preg_match(self::NAME, $name, $m) ? [$m[1], (int)$m[2] / 1000] : null;
	}

	/**
	 * Cut stills from videos not done yet, newest first, for up to $seconds.
	 *
	 * @return array{videos: int, stills: int, failed: int}
	 * @throws LockedException when another run is going on
	 */
	public function run(int $seconds, ?int $limit = null): array {
		$stats = ['videos' => 0, 'stills' => 0, 'failed' => 0];
		if (!$this->enabled() || self::ffmpeg() === null) {
			return $stats;
		}
		$this->locks->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'Glimpse: video scenes');
		$dirty = false;
		$done = $this->config->getState(self::STATE);
		try {
			$deadline = microtime(true) + $seconds;
			$cursorPage = (int)($this->config->getState('video_scenes_page')['page'] ?? 1);
			$page = 1;
			$checkedPage1 = false;

			while ($page !== null && microtime(true) < $deadline && ($limit === null || $stats['videos'] < $limit)) {
				$res = $this->immich->json('POST', '/search/metadata', [
					'type' => 'VIDEO', 'page' => $page, 'size' => self::PAGE, 'order' => 'desc', 'visibility' => 'timeline',
				])['assets'] ?? [];
				$items = $res['items'] ?? [];
				$hasUnindexed = false;

				foreach ($items as $video) {
					if (isset($done[$video['id']])) {
						continue;
					}
					$hasUnindexed = true;
					if (microtime(true) >= $deadline || ($limit !== null && $stats['videos'] >= $limit)) {
						break 2;
					}
					try {
						$count = $this->index($video);
						$done[$video['id']] = $count;
						$dirty = true;
						$stats['videos']++;
						$stats['stills'] += max(0, $count);
						$stats['failed'] += $count === self::FAILED ? 1 : 0;
					} catch (ImmichException $e) {
						if ($e->getCode() === 502) {
							// (a) Immich unreachable: stop the run without marking the video failed
							$this->logger->warning('Glimpse: Immich unreachable, stopping video scenes run: ' . $e->getMessage(), ['app' => 'glimpse']);
							break 2;
						}
						$done[$video['id']] = self::FAILED;
						$dirty = true;
						$stats['videos']++;
						$stats['failed']++;
					}

					// Keep what is done periodically
					if ($stats['videos'] % 50 === 0) {
						$this->config->setState(self::STATE, $done);
						$dirty = false;
					}
				}

				// If page 1 was completely done and cursor is further, jump to where unindexed videos were left off
				if ($page === 1 && !$hasUnindexed && !$checkedPage1 && $cursorPage > 1) {
					$checkedPage1 = true;
					$page = $cursorPage;
					continue;
				}
				$checkedPage1 = true;

				$next = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
				if ($page > 1 || $hasUnindexed) {
					$cursorPage = $next ?? 1;
					$this->config->setState('video_scenes_page', ['page' => $cursorPage]);
				}
				$page = $next;
			}
		} finally {
			if ($dirty) {
				$this->config->setState(self::STATE, $done);
			}
			$this->locks->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
		return $stats;
	}

	/** @return int stills uploaded, or FAILED / NO_FILE */
	private function index(array $video): int {
		$file = $this->assets->ownerFile($video['originalPath'] ?? '');
		$storage = $file?->getStorage();
		if ($storage === null || (!$storage->isLocal() && !$storage->instanceOfStorage(\OC\Files\Storage\Local::class))) {
			return self::NO_FILE;
		}
		$local = null;
		try {
			$local = $storage->getLocalFile($file->getInternalPath());
		} catch (\Throwable) {
		}
		if (!is_string($local) || !is_readable($local)) {
			return self::NO_FILE;
		}
		$moments = self::moments(self::seconds($video['duration'] ?? null));
		$taken = strtotime((string)($video['fileCreatedAt'] ?? '')) ?: time();
		$count = 0;
		foreach ($moments as $t) {
			$jpeg = $this->still($local, $t);
			if ($jpeg === '') {
				continue;
			}
			$at = gmdate('Y-m-d\TH:i:s', $taken + (int)$t) . '.000Z';
			try {
				$this->immich->upload('/assets', [
					['name' => 'assetData', 'contents' => $jpeg, 'filename' => self::PREFIX . $video['id'] . '_' . (int)round($t * 1000) . '.jpg'],
					['name' => 'fileCreatedAt', 'contents' => $at],
					['name' => 'fileModifiedAt', 'contents' => $at],
					['name' => 'visibility', 'contents' => 'archive'],
				]);
				$count++;
			} catch (ImmichException $e) {
				$this->logger->warning('Glimpse: still of ' . $video['id'] . ' not uploaded: ' . $e->getMessage(), ['app' => 'glimpse']);
				return self::FAILED;
			}
		}
		return $moments !== [] && $count === 0 ? self::FAILED : $count;
	}

	/**
	 * One still as JPEG, '' if ffmpeg could not make it. First the nearest key frame only
	 * (a 4K HEVC frame decodes in 0.3 s instead of 2 s), exactly at $t if that gives nothing.
	 */
	private function still(string $file, float $t): string {
		return $this->ffmpegStill($file, $t, true) ?: $this->ffmpegStill($file, $t, false);
	}

	private function ffmpegStill(string $file, float $t, bool $keyFrame): string {
		$cmd = ['nice', '-n', '15', 'timeout', '60', self::ffmpeg(), '-v', 'error', '-nostdin',
			...($keyFrame ? ['-skip_frame', 'nokey'] : []), '-ss', (string)$t, '-i', $file,
			'-frames:v', '1', '-an', '-vf', 'scale=w=' . self::WIDTH . ':h=' . self::WIDTH . ':force_original_aspect_ratio=decrease',
			'-q:v', '4', '-f', 'image2', '-c:v', 'mjpeg', 'pipe:1'];
		$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		if (!is_resource($proc)) {
			return '';
		}
		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return proc_close($proc) === 0 && is_string($out) ? $out : '';
	}

	/** @return array{videos: int, done: int, stills: int, failed: int} */
	public function status(): array {
		$stats = $this->immich->json('GET', '/assets/statistics?visibility=timeline');
		return ['videos' => (int)($stats['videos'] ?? 0)] + $this->progress();
	}

	/** What is done, without asking Immich. @return array{enabled: bool, ffmpeg: bool, done: int, stills: int, failed: int} */
	public function progress(): array {
		$done = $this->config->getState(self::STATE);
		return [
			'enabled' => $this->enabled(),
			'ffmpeg' => self::ffmpeg() !== null,
			'done' => count($done),
			'stills' => array_sum(array_filter($done, static fn ($n) => $n > 0)),
			'failed' => count(array_filter($done, static fn ($n) => $n === self::FAILED)),
		];
	}

	/**
	 * Remove stills: all of them, or (orphans) those whose video is gone from Immich.
	 *
	 * @return int stills removed
	 */
	public function purge(bool $orphansOnly = false): int {
		$this->locks->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'Glimpse: video scenes');
		try {
			$byVideo = [];
			$page = 1;
			while ($page !== null) {
				// Immich /search/metadata originalFileName performs a prefix/partial match, returning all glimpse-frame_* assets
				$res = $this->immich->json('POST', '/search/metadata', [
					'originalFileName' => self::PREFIX, 'visibility' => 'archive', 'page' => $page, 'size' => 1000,
				])['assets'] ?? [];
				foreach ($res['items'] ?? [] as $a) {
					if (($parsed = self::parseName((string)($a['originalFileName'] ?? ''))) !== null) {
						$byVideo[$parsed[0]][] = $a['id'];
					}
				}
				$page = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
			}
			$done = $this->config->getState(self::STATE);
			$liveVideos = [];
			if ($orphansOnly) {
				// Batch existence check: list live timeline videos in 1000-item pages instead of N+1 requests
				$vPage = 1;
				while ($vPage !== null) {
					$res = $this->immich->json('POST', '/search/metadata', [
						'type' => 'VIDEO', 'visibility' => 'timeline', 'page' => $vPage, 'size' => 1000,
					])['assets'] ?? [];
					foreach ($res['items'] ?? [] as $v) {
						$liveVideos[$v['id']] = true;
					}
					$vPage = isset($res['nextPage']) ? (int)$res['nextPage'] : null;
				}
				// Retry failed videos once a day and clean up done state for deleted videos
				foreach ($done as $videoId => $count) {
					if ($count === self::FAILED || !isset($liveVideos[$videoId])) {
						unset($done[$videoId]);
					}
				}
			}
			$gone = [];
			foreach ($byVideo as $videoId => $ids) {
				if ($orphansOnly && isset($liveVideos[$videoId])) {
					continue;
				}
				array_push($gone, ...$ids);
				unset($done[$videoId]);
			}
			foreach (array_chunk($gone, 500) as $chunk) {
				$this->immich->json('DELETE', '/assets', ['ids' => $chunk, 'force' => true]);
			}
			$this->config->setState(self::STATE, $orphansOnly ? $done : []);
			return count($gone);
		} finally {
			$this->locks->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
