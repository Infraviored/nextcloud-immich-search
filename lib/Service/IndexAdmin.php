<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Service;

use OCP\Http\Client\IClientService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Everything the admin sees and does about Immich's index: status, what gets indexed
 * (exclusion patterns), libraries per user, nudges (queue missing, rescan).
 */
class IndexAdmin {
	/** Immich queues shown in the status, in pipeline order. */
	public const QUEUES = ['library', 'metadataExtraction', 'thumbnailGeneration', 'smartSearch', 'faceDetection', 'facialRecognition', 'duplicateDetection'];
	/** Immich's limit per library. */
	private const MAX_PATTERNS = 128;
	/** Queues that "queue missing" nudges; each only picks up assets that lack its result. */
	private const NUDGE = ['thumbnailGeneration', 'smartSearch', 'faceDetection'];
	/** Folder names that almost always hold icons and UI graphics, not photos. */
	private const SUSPICIOUS = '/^(icons?|emoji|emojis|sprites?|thumbs?|thumbnails?|\.?cache|node_modules|scalable|cursors?|\.git|site-packages|\d+x\d+)$/i';
	/** Folders that contain installed programs; a whole program is suggested, not each icon folder in it. */
	private const PROGRAM = '/(portable$|^program files|^appdata$|\.app$|^\.?venv$|^site-packages$|^node_modules$|^\.git$)/i';

	public function __construct(
		private ImmichClient $immich,
		private Config $config,
		private IUserManager $users,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private VideoScenes $scenes,
	) {
	}

	/** Full status for admins. */
	public function status(): array {
		$jobs = $this->immich->json('GET', '/jobs');
		$queues = [];
		foreach (self::QUEUES as $q) {
			$c = $jobs[$q]['jobCounts'] ?? [];
			$queues[$q] = [
				'active' => (int)($c['active'] ?? 0),
				'waiting' => (int)($c['waiting'] ?? 0) + (int)($c['delayed'] ?? 0),
				'failed' => (int)($c['failed'] ?? 0),
				'paused' => (bool)($jobs[$q]['queueStatus']['isPaused'] ?? false),
			];
		}
		$stats = $this->immich->json('GET', '/server/statistics');
		$scenes = $this->scenes->progress();
		return [
			// Immich counts the stills taken from videos as photos; they are not the user's
			'photos' => max(0, (int)($stats['photos'] ?? 0) - $scenes['stills']),
			'videos' => (int)($stats['videos'] ?? 0),
			'queues' => $queues,
			'busy' => $this->busy($queues),
			'ml' => $this->mlHealth(),
			'libraries' => $this->libraries(),
			'peopleIndex' => $this->config->getState('people_index'),
			'videoScenes' => $scenes,
			'prune' => $this->config->getState('prune') + ['entries' => count($this->config->getState('pruned_entries'))],
		];
	}

	/** What a normal user may know: is indexing still running, when were people updated. */
	public function publicStatus(): array {
		$jobs = $this->immich->json('GET', '/jobs');
		$queues = [];
		foreach (self::QUEUES as $q) {
			$c = $jobs[$q]['jobCounts'] ?? [];
			$queues[$q] = ['active' => (int)($c['active'] ?? 0), 'waiting' => (int)($c['waiting'] ?? 0) + (int)($c['delayed'] ?? 0)];
		}
		return ['busy' => $this->busy($queues), 'peopleIndex' => $this->config->getState('people_index')];
	}

	private function busy(array $queues): int {
		return array_sum(array_map(static fn ($c) => $c['active'] + $c['waiting'], $queues));
	}

	/** @return array{state: string, ms?: int} "ok", "down", or "unknown" when no ping URL is set */
	private function mlHealth(): array {
		$url = $this->config->get('ml_ping_url');
		if ($url === '') {
			return ['state' => 'unknown'];
		}
		$start = microtime(true);
		try {
			$resp = $this->clientService->newClient()->get(rtrim($url, '/') . '/ping', ['timeout' => 3, 'http_errors' => false]);
			$ok = $resp->getStatusCode() === 200 && str_contains((string)$resp->getBody(), 'pong');
			return ['state' => $ok ? 'ok' : 'down', 'ms' => (int)((microtime(true) - $start) * 1000)];
		} catch (\Throwable) {
			return ['state' => 'down'];
		}
	}

	/** Immich libraries, with the Nextcloud user each one belongs to (from the path pattern). */
	public function libraries(): array {
		[$before, $after] = explode(Config::USER_PLACEHOLDER, $this->config->getPathPattern(), 2);
		$out = [];
		foreach ($this->immich->json('GET', '/libraries') as $lib) {
			$user = null;
			foreach ($lib['importPaths'] ?? [] as $p) {
				$p = rtrim($p, '/') . '/';
				if (str_starts_with($p, $before) && str_ends_with($p, $after)) {
					$user = substr($p, strlen($before), strlen($p) - strlen($before) - strlen($after));
				}
			}
			$stats = $this->immich->json('GET', '/libraries/' . $lib['id'] . '/statistics');
			$out[] = [
				'id' => $lib['id'],
				'name' => $lib['name'] ?? '',
				'user' => $user,
				'importPaths' => $lib['importPaths'] ?? [],
				'refreshedAt' => $lib['refreshedAt'] ?? null,
				'photos' => (int)($stats['photos'] ?? 0),
				'videos' => (int)($stats['videos'] ?? 0),
			];
		}
		return $out;
	}

	/** Nextcloud users that have no library yet. */
	public function usersWithoutLibrary(): array {
		$have = array_filter(array_column($this->libraries(), 'user'));
		$missing = [];
		$this->users->callForSeenUsers(function ($u) use ($have, &$missing) {
			if ($u->isEnabled() && !in_array($u->getUID(), $have, true)) {
				$missing[] = ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName()];
			}
		});
		return $missing;
	}

	/** Library for a Nextcloud user, owned by the service account. The mount must exist already. */
	public function createLibrary(string $uid): array {
		if (!$this->users->userExists($uid)) {
			throw new \InvalidArgumentException('user');
		}
		$me = $this->immich->json('GET', '/users/me');
		$path = rtrim(str_replace(Config::USER_PLACEHOLDER, $uid, $this->config->getPathPattern()), '/');
		// Only this user's pruned entries, packed for this library, like applyExclusions() does.
		$patterns = $this->managedPatterns([$path]);
		self::checkLimit(count($patterns));
		$this->rememberManaged($patterns);
		$lib = $this->immich->json('POST', '/libraries', [
			'ownerId' => $me['id'],
			'name' => 'Nextcloud ' . $uid,
			'importPaths' => [$path],
			'exclusionPatterns' => $patterns,
		]);
		$this->immich->json('POST', '/libraries/' . $lib['id'] . '/scan');
		return $lib;
	}

	/**
	 * Write the configured exclusions into every library and rescan. Patterns the admin
	 * added in Immich by hand are kept: only the ones this app wrote before are replaced.
	 *
	 * @param ?list<string> $prunedEntries entries of SmallImagePruner to write instead of the
	 *                                     stored ones (it saves them only once they are written)
	 */
	public function applyExclusions(bool $scan = true, ?array $prunedEntries = null): int {
		$old = $this->config->getState('managed_patterns');
		// Check every library before writing any, so a pattern list that does not fit is not
		// half applied.
		$plan = [];
		$all = [];
		foreach ($this->immich->json('GET', '/libraries') as $lib) {
			$new = $this->managedPatterns($lib['importPaths'] ?? [], $prunedEntries);
			array_push($all, ...$new);
			$own = array_values(array_diff($lib['exclusionPatterns'] ?? [], $old, $new));
			self::checkLimit(count($own) + count($new));
			$plan[$lib['id']] = array_values(array_unique([...$own, ...$new]));
		}
		$all = array_values(array_unique($all));
		// Known as ours before anything is written: should a library fail halfway, the patterns
		// already written elsewhere must not look like the admin's own on the next run.
		$this->rememberManaged($all);
		foreach ($plan as $id => $patterns) {
			$this->immich->json('PUT', '/libraries/' . $id, ['exclusionPatterns' => $patterns]);
			if ($scan) {
				$this->immich->json('POST', '/libraries/' . $id . '/scan');
			}
		}
		$this->config->setState('managed_patterns', $all);
		return count($all);
	}

	/** Add patterns to those known as written by this app. */
	private function rememberManaged(array $patterns): void {
		$old = $this->config->getState('managed_patterns');
		$union = array_values(array_unique([...$old, ...$patterns]));
		if ($union !== $old) {
			$this->config->setState('managed_patterns', $union);
		}
	}

	private static function checkLimit(int $count): void {
		if ($count > self::MAX_PATTERNS) {
			throw new \InvalidArgumentException(sprintf('Too many exclusion patterns for Immich (%d, at most %d per library)', $count, self::MAX_PATTERNS));
		}
	}

	/**
	 * @param ?array $importPaths only pruned entries under these paths (per library); null = all
	 * @param ?list<string> $prunedEntries instead of the stored ones
	 * @return list<string> glob patterns from the excluded formats and folders
	 */
	public function managedPatterns(?array $importPaths = null, ?array $prunedEntries = null): array {
		$patterns = [];
		foreach ($this->config->get('excluded_formats') as $ext) {
			$ext = ltrim(strtolower($ext), '.*');
			if (preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
				// Immich matches case-sensitively; cover the usual spellings.
				array_push($patterns, "**/*.$ext", '**/*.' . strtoupper($ext));
			}
		}
		foreach ($this->config->get('excluded_folders') as $folder) {
			$patterns[] = self::folderPattern($folder);
		}
		// Paths of tiny images taken out by SmallImagePruner, packed into a few brace patterns
		// because Immich allows only 128 patterns per library.
		$entries = $prunedEntries ?? $this->config->getState('pruned_entries');
		if ($importPaths !== null) {
			$entries = array_values(array_filter($entries, static function ($e) use ($importPaths) {
				foreach ($importPaths as $p) {
					if (str_starts_with($e, SmallImagePruner::escape(rtrim($p, '/')) . '/')) {
						return true;
					}
				}
				return false;
			}));
		}
		array_push($patterns, ...SmallImagePruner::pack($entries));
		return array_values(array_unique($patterns));
	}

	/**
	 * Pattern for an entry of "excluded_folders": a glob (anything with "*", e.g. a stored
	 * suggestion) as it is, a folder name or path made literal: "(1)" or "{…}" in it would
	 * otherwise be read as glob syntax and, turned into a regex, can fail the library scan.
	 */
	public static function folderPattern(string $folder): string {
		$folder = trim($folder);
		return str_contains($folder, '*') ? $folder : '**/' . SmallImagePruner::escape(trim($folder, '/')) . '/**';
	}

	/**
	 * Folders Immich indexed whose name says "icons, caches, program files". Collapsed to
	 * the outermost such folder, as Immich-relative paths with their library user.
	 *
	 * @return list<array{path: string, pattern: string}>
	 */
	public function folderSuggestions(): array {
		$roots = [];
		foreach ($this->immich->json('GET', '/view/folder/unique-paths') as $path) {
			$parts = explode('/', trim($path, '/'));
			$hit = null;
			foreach ($parts as $i => $part) {
				if (preg_match(self::SUSPICIOUS, $part)) {
					$hit = $i;
					break;
				}
			}
			if ($hit === null) {
				continue;
			}
			// Inside a program folder (GIMPPortable/…/icons): suggest the program folder.
			foreach (array_slice($parts, 0, $hit, true) as $i => $part) {
				if (preg_match(self::PROGRAM, $part)) {
					$hit = $i;
					break;
				}
			}
			$roots['/' . implode('/', array_slice($parts, 0, $hit + 1))] = true;
		}
		$roots = array_keys($roots);
		sort($roots);
		$outer = [];
		foreach ($roots as $r) {
			if ($outer === [] || !str_starts_with($r . '/', end($outer) . '/')) {
				$outer[] = $r;
			}
		}
		$excluded = $this->managedPatterns();
		[$before] = explode(Config::USER_PLACEHOLDER, $this->config->getPathPattern(), 2);
		$out = [];
		foreach ($outer as $r) {
			$pattern = SmallImagePruner::escape($r) . '/**';
			if (in_array($pattern, $excluded, true)) {
				continue;
			}
			// Shown as "user: path/in/their/files"
			$label = str_starts_with($r, $before) ? substr($r, strlen($before)) : $r;
			$label = preg_replace('#^([^/]+)/(.*)$#', '$1: $2', $label);
			$out[] = ['path' => $r, 'label' => $label, 'pattern' => $pattern];
		}
		return $out;
	}

	/** Queue whatever lacks a thumbnail, embedding or face detection. */
	public function queueMissing(): void {
		foreach (self::NUDGE as $q) {
			$this->immich->json('PUT', '/jobs/' . $q, ['command' => 'start', 'force' => false]);
		}
	}

	public function scanAll(): void {
		foreach ($this->immich->json('GET', '/libraries') as $lib) {
			$this->immich->json('POST', '/libraries/' . $lib['id'] . '/scan');
		}
	}
}
