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
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Optional: each user says what counts as sensitive for them, and can show such pictures
 * marked, hide them or see only them. Off unless the admin allows it (`sensitive_detection`)
 * and the user turns it on.
 *
 * No extra model or service: Immich's search embeddings are read from its database.
 *
 * - Terms: the user searches, picks the last result that still counts ("Up to here") and
 *   the term is saved with that picture's similarity as its limit. A picture counts when it
 *   is at least that close to any of the user's terms.
 * - Marks by hand always win for their own file. From MIN_POSITIVE marks as sensitive on, they
 *   also train a linear SVM per user (SensitiveModel); pictures on its sensitive side count
 *   too. The boundary is the one it learned: the marks are the user's way to move it. The
 *   review (training the filter) sorts by its score.
 *
 * What each build learned goes to the Nextcloud log at debug level ("Sensitive: ..."), to see
 * whether the model learns from the marks and how far it moves.
 *
 * Stored per user in app data, `sensitive/<uid>.json`:
 * {"terms": [{"q", "min", "model"}], "marks": {fileId: bool}, "auto": [fileId],
 *  "assets": {fileId: assetId}, "counts": {q: n}, "byModel": n, "change": {added, removed},
 *  "svm": {"w", "b", "key", "positives", "negatives"} or null, "learned": [positives, negatives],
 *  "signature": "...", "built": time, "error": null}
 */
class SensitiveContent {
	public const MAX_TERMS = 30;
	/** The two sides of the review, see review(). */
	public const SIDES = ['hidden', 'visible'];
	private const FOLDER = 'sensitive';
	/** Embedded terms per model, shared by all users (not a user's file name). */
	private const TERMS_FOLDER = 'sensitive-terms';
	private const MODES = ['all', 'hide', 'only'];
	/** Rebuilt at least this often even when nothing seems to have changed. */
	private const MAX_AGE = 86400;

	private IAppData $appData;
	/** @var array<string, array> */
	private array $cache = [];
	private ?string $model = null;

	public function __construct(
		private Config $config,
		private UserPrefs $prefs,
		private ImmichDb $db,
		private ImmichClient $immich,
		private AssetSearch $assets,
		private IRootFolder $rootFolder,
		private IClientService $clients,
		private IConfig $ncConfig,
		private PrivateArea $privateArea,
		IAppDataFactory $appDataFactory,
		private LoggerInterface $logger,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/** The admin allows it. */
	public function isAvailable(): bool {
		return (bool)$this->config->get('sensitive_detection');
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
				$data = json_decode($this->folder(self::FOLDER)->getFile($uid . '.json')->getContent(), true) ?: [];
			} catch (NotFoundException) {
				$data = [];
			}
			// Files of the first version (scores of a trained model) keep only their marks
			$this->cache[$uid] = $data + ['terms' => [], 'marks' => [], 'auto' => [], 'assets' => [], 'counts' => [], 'byModel' => 0,
				'svm' => null, 'learned' => [0, 0], 'signature' => '', 'built' => 0, 'error' => null];
		}
		return $this->cache[$uid];
	}

	/** @return array<int, true> the files that count as sensitive for $uid right now */
	public function flagged(string $uid): array {
		$data = $this->get($uid);
		return self::withMarks($data['auto'], $this->bools($data['marks']));
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
	 * "Only sensitive" without paging through the whole library: the sensitive pictures straight
	 * from Immich's database, best first for a text ($q), else newest first (or, with $after,
	 * oldest first). Null when not in "only" mode; the caller pages through Immich then.
	 *
	 * @return ?array{items: list<array>, nextPage: ?int}
	 */
	public function onlyPage(string $uid, string $q, ?string $before, ?string $after, string $type, int $page, int $size): ?array {
		if ($this->mode($uid) !== 'only' || !$this->db->isEnabled()) {
			return null;
		}
		$data = $this->get($uid);
		$assets = [];
		foreach ($this->flagged($uid) as $id => $_) {
			if (isset($data['assets'][$id])) {
				$assets[] = $data['assets'][$id];
			}
		}
		if ($assets === []) {
			return ['items' => [], 'nextPage' => null];
		}
		$iso = fn ($col) => 'to_char(a."' . $col . '" AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"\') AS "' . $col . '"';
		$params = ['{' . implode(',', $assets) . '}'];
		$where = ['a.id = ANY(?::uuid[])', 'a."deletedAt" IS NULL'];
		if (in_array($type, ['IMAGE', 'VIDEO'], true)) {
			$where[] = 'a.type = ?';
			$params[] = $type;
		}
		if ($before !== null && ($t = strtotime($before)) !== false) {
			$where[] = 'a."fileCreatedAt" < to_timestamp(?)';
			$params[] = $t;
		}
		if ($after !== null && ($t = strtotime($after)) !== false) {
			$where[] = 'a."fileCreatedAt" >= to_timestamp(?)';
			$params[] = $t;
		}
		$join = '';
		if (trim($q) !== '') {
			$join = ' LEFT JOIN smart_search s ON s."assetId" = a.id';
			$order = 's.embedding <=> ?::vector';
			$params[] = SensitiveModel::vector($this->termEmbedding($this->model(), trim($q)));
		} else {
			$order = 'a."fileCreatedAt" ' . ($after !== null ? 'ASC' : 'DESC');
		}
		$page = max(1, $page);
		$stmt = $this->db->pdo('20s')->prepare('SELECT a.id, a."originalPath", a."originalFileName", a.type, ' . $iso('localDateTime') . ', ' . $iso('fileCreatedAt') . '
			FROM asset a' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ', a.id LIMIT ' . ($size + 1) . ' OFFSET ' . (($page - 1) * $size));
		$stmt->execute($params);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		$items = [];
		foreach (array_slice($rows, 0, $size) as $row) {
			$item = $this->assets->visibleItem($uid, $row);
			if ($item !== null) {
				$items[] = $item;
			}
		}
		return ['items' => $items, 'nextPage' => count($rows) > $size ? $page + 1 : null];
	}

	/**
	 * Mark files by hand: true sensitive, false not, null back to what the terms say. Only
	 * files the user can reach. The pictures like them follow on the next build.
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
		$data['marksChanged'] = time();
		$this->save($uid, $data);
		$this->logger->debug('Sensitive: {uid} marked {n} files ({on} sensitive, {off} not, {cleared} cleared)', [
			'app' => Application::APP_ID, 'uid' => $uid, 'n' => count($previous),
			'on' => count(array_filter($marks, fn ($on) => $on === true)),
			'off' => count(array_filter($marks, fn ($on) => $on === false)),
			'cleared' => count(array_filter($marks, fn ($on) => $on === null)),
		]);
		$flagged = $this->flagged($uid);
		$now = [];
		foreach (array_keys($previous) as $id) {
			$now[$id] = isset($flagged[$id]);
		}
		return ['previous' => $previous, 'sensitive' => $now];
	}

	/**
	 * "Up to here": save $q as a term, with the similarity of the picture $assetId (the one the
	 * user picked in the results for $q) as its limit. A term already saved gets the new limit.
	 */
	public function setTerm(string $uid, string $q, string $assetId): void {
		$q = trim($q);
		if ($q === '' || mb_strlen($q) > 200 || !preg_match('/^[0-9a-f-]{36}$/', $assetId)) {
			throw new \InvalidArgumentException('Term or picture missing');
		}
		if (!$this->assets->maySee($uid, $assetId)) {
			throw new \InvalidArgumentException('Picture not found');
		}
		$model = $this->model();
		$stmt = $this->db->pdo('20s')->prepare('SELECT -(embedding <#> ?::vector) FROM smart_search WHERE "assetId" = ?');
		$stmt->execute([SensitiveModel::vector($this->termEmbedding($model, $q)), $assetId]);
		$sim = $stmt->fetchColumn();
		if ($sim === false) {
			throw new \InvalidArgumentException('Immich has no search data for this picture');
		}
		$data = $this->get($uid);
		$terms = array_values(array_filter($data['terms'], fn ($t) => mb_strtolower($t['q']) !== mb_strtolower($q)));
		if (count($terms) >= self::MAX_TERMS) {
			throw new \InvalidArgumentException('Too many terms');
		}
		// A hair below, so the picture itself is in
		$terms[] = ['q' => $q, 'min' => round((float)$sim - 0.00005, 5), 'model' => $model];
		$data['terms'] = $terms;
		$this->save($uid, $data);
	}

	public function removeTerm(string $uid, string $q): void {
		$data = $this->get($uid);
		$data['terms'] = array_values(array_filter($data['terms'], fn ($t) => mb_strtolower($t['q']) !== mb_strtolower(trim($q))));
		$this->save($uid, $data);
	}

	/** For the settings: the terms with what each brings in, marks, and what marks moved. */
	public function status(string $uid): array {
		$data = $this->get($uid);
		$marks = $this->bools($data['marks']);
		$model = null;
		try {
			$model = $this->model();
		} catch (\Throwable) {
		}
		return [
			'built' => $data['built'] ?: null,
			'error' => $data['error'],
			'terms' => array_map(fn ($t) => [
				'q' => $t['q'],
				'count' => (int)($data['counts'][$t['q']] ?? 0),
				// Made with another search model: its limit means nothing now, set it again
				'stale' => $model !== null && ($t['model'] ?? '') !== $model,
			], $data['terms']),
			'flagged' => count($this->flagged($uid)),
			'change' => $data['change'] ?? null,
			'marked' => count(array_filter($marks)),
			'unmarked' => count($marks) - count(array_filter($marks)),
			// What the SVM learns from, and what it brings in on top of the terms
			// The marks as they are now (the model learns from them with the next build)
			'learned' => ['positives' => count(array_filter($marks)), 'negatives' => count($marks) - count(array_filter($marks))],
			'minPositive' => SensitiveModel::MIN_POSITIVE,
			'model' => $data['svm'] !== null,
			'byModel' => (int)$data['byModel'],
		];
	}

	/** Has anything changed that the last build did not see (pictures, model, marks)? */
	public function needsRebuild(string $uid): bool {
		$data = $this->get($uid);
		return $data['built'] === 0
			|| $data['built'] < time() - self::MAX_AGE
			|| ($data['marksChanged'] ?? 0) > $data['built']
			|| $data['signature'] !== $this->signature();
	}

	/**
	 * Work out which files count: the terms, and from enough marks the SVM trained on them. A
	 * second or two; the model is trained again only when the marks changed. Failures are stored
	 * for the settings page and rethrown.
	 *
	 * @return array{flagged: int, terms: int, byModel: int, model: bool}
	 */
	public function rebuild(string $uid): array {
		$data = $this->get($uid);
		try {
			$signature = $this->signature();
			$model = $this->model();
			$pdo = $this->db->pdo('120s');
			$terms = array_values(array_filter($data['terms'], fn ($t) => ($t['model'] ?? '') === $model));
			$rows = [];
			// Seconds per part, kept for finding what is slow
			$took = [];
			$lap = microtime(true);
			$time = function (string $part) use (&$took, &$lap) {
				$took[$part] = round(microtime(true) - $lap, 2);
				$lap = microtime(true);
			};
			$scope = $this->assets->ownScope($uid);
			[$hits, $byTerm] = $this->termHits($pdo, $model, $terms, $rows, $scope);
			$time('terms');

			// What earlier builds found out about files and assets, while the pictures are the same
			$fresh = $data['signature'] === $signature && $data['built'] >= time() - self::MAX_AGE;
			$known = $data['markAssets'] ?? [];
			$fileOf = $fresh ? ($data['fileOf'] ?? []) : [];
			$fileAssets = [];
			$markAssets = $this->markAssets($pdo, $uid, $this->bools($data['marks']), $fileAssets, $known);
			$time('marks');
			$positives = count(array_filter($markAssets));
			$data['learned'] = [$positives, count($markAssets) - $positives];
			$key = md5(SensitiveModel::VERSION . '|' . $signature . '|' . json_encode($markAssets));
			$before = $data['svm'];
			$trained = null;
			if ($positives < SensitiveModel::MIN_POSITIVE) {
				$data['svm'] = null;
			} elseif (($data['svm']['key'] ?? '') !== $key) {
				// Marks the last model did not learn from: how it and the new one score them
				$newMarks = array_diff_key($markAssets, $before['scored'] ?? []);
				$trained = $this->train($pdo, $markAssets, $before, $newMarks);
				$data['svm'] = !empty($trained['svm']['w'])
					? $trained['svm'] + ['key' => $key, 'scored' => array_fill_keys(array_keys($markAssets), true)]
					: null;
			}
			$time('train');
			$modelHits = $data['svm'] !== null ? $this->modelHits($pdo, $data['svm'], $rows, $scope) : [];
			$time('model');

			[$auto, $counts, $byModel] = $this->toFiles($pdo, $uid, $hits, $modelHits, $byTerm, $terms, $rows, $fileAssets, $fileOf);
			$data['markAssets'] = $known;
			$data['fileOf'] = $fileOf;
			$time('files');
			$data['took'] = $took;
			// What this build changed, for the wizard ("12 more hidden, 3 fewer") and the log
			$was = array_flip($data['auto'] ?? []);
			$now = array_flip($auto);
			$data['change'] = ['added' => count(array_diff_key($now, $was)), 'removed' => count(array_diff_key($was, $now))];
			$this->logBuild($uid, $data, $auto, $byModel, $trained);
			$data['auto'] = $auto;
			$data['assets'] = $fileAssets;
			$data['counts'] = $counts;
			$data['byModel'] = $byModel;
			$data['signature'] = $signature;
			$data['built'] = time();
			$data['error'] = null;
		} catch (\Throwable $e) {
			$data['error'] = $e->getMessage();
			$data['built'] = time();
			$this->save($uid, $data);
			throw $e;
		}
		$this->save($uid, $data);
		return ['flagged' => count($this->flagged($uid)), 'terms' => count($terms), 'byModel' => $byModel, 'model' => $data['svm'] !== null];
	}

	/**
	 * One page of the review (training the filter), straight from Immich's database, only
	 * pictures $uid can see. $offset counts rows of the query, so pictures the user cannot see
	 * do not cost a page; the answer says where the next page starts.
	 *
	 * - hidden: what the filter hides and the user did not mark, the least sensitive-looking
	 *   first (where it hides too much)
	 * - visible: what it does not hide, not marked "not"; the most sensitive-looking first
	 *   (where it misses), or with $q the closest to $q (to start, or for a new topic)
	 *
	 * @return array{items: list<array>, nextPage: ?int}
	 */
	public function review(string $uid, string $side, string $q, int $offset, int $size): array {
		$start = microtime(true);
		$page = $this->reviewPage($uid, $side, $q, $offset, $size);
		$this->logger->debug('Sensitive: {uid} review {side} from {offset}: {n} pictures in {took}s', [
			'app' => Application::APP_ID, 'uid' => $uid, 'side' => $side, 'offset' => $offset,
			'n' => count($page['items']), 'took' => round(microtime(true) - $start, 2),
		]);
		return $page;
	}

	/** @return array{items: list<array>, nextPage: ?int} */
	private function reviewPage(string $uid, string $side, string $q, int $offset, int $size): array {
		$data = $this->get($uid);
		$svm = !empty($data['svm']['w']) ? $data['svm'] : null;
		$model = $this->model();
		$flagged = $this->flagged($uid);
		$marks = $this->bools($data['marks']);
		$assetsOf = fn (array $fileIds) => array_values(array_unique(array_filter(array_map(fn ($id) => $data['assets'][$id] ?? null, $fileIds))));
		$params = [];
		$where = ['a."deletedAt" IS NULL', 'a."originalFileName" NOT LIKE \'glimpse-frame\\_%\''];
		if (($scope = $this->assets->ownScope($uid)) !== null) {
			$where[] = 'starts_with(a."originalPath", ?)';
			$params[] = $scope;
		}
		$terms = array_values(array_filter($data['terms'], fn ($t) => ($t['model'] ?? '') === $model));
		$score = $svm !== null ? '-(s.embedding <#> ?::vector) + ?' : null;
		$scoreParams = $svm !== null ? [SensitiveModel::vector($svm['w']), (float)$svm['b']] : [];
		if ($side === 'visible') {
			$where[] = 'NOT (a.id = ANY(?::uuid[]))';
			$params[] = '{' . implode(',', $assetsOf(array_merge(array_keys($flagged), array_keys(array_filter($marks, fn ($on) => !$on))))) . '}';
			if (trim($q) !== '') {
				$order = 's.embedding <=> ?::vector';
				$orderParams = [SensitiveModel::vector($this->termEmbedding($model, trim($q)))];
			} elseif ($score !== null) {
				$order = $score . ' DESC';
				$orderParams = $scoreParams;
			} else {
				// No filter yet: the user starts with a term
				return ['items' => [], 'nextPage' => null];
			}
		} elseif ($side === 'hidden') {
			$auto = array_diff_key($flagged, array_filter($marks));
			if ($auto === []) {
				return ['items' => [], 'nextPage' => null];
			}
			$where[] = 'a.id = ANY(?::uuid[])';
			$params[] = '{' . implode(',', $assetsOf(array_keys($auto))) . '}';
			if ($score !== null) {
				$order = $score . ' ASC';
				$orderParams = $scoreParams;
			} elseif ($terms !== []) {
				// Without a model: how far past the limit of its closest term
				$order = 'GREATEST(' . implode(', ', array_fill(0, count($terms), '-(s.embedding <#> ?::vector) - ?')) . ') ASC';
				$orderParams = [];
				foreach ($terms as $t) {
					$orderParams[] = SensitiveModel::vector($this->termEmbedding($model, $t['q']));
					$orderParams[] = (float)$t['min'];
				}
			} else {
				$order = 'a."fileCreatedAt" DESC';
				$orderParams = [];
			}
		} else {
			throw new \InvalidArgumentException('Unknown side');
		}
		$sql = 'SELECT a.id, a."originalPath", a."originalFileName", a.type, '
			. 'to_char(a."localDateTime" AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"\') AS "localDateTime" '
			. 'FROM asset a JOIN smart_search s ON s."assetId" = a.id WHERE ' . implode(' AND ', $where)
			. ' ORDER BY ' . $order . ', a.id LIMIT ? OFFSET ?';
		$stmt = $this->db->pdo('30s')->prepare($sql);
		$items = [];
		$offset = max(0, $offset);
		$batch = max($size, 100);
		$locked = !$this->privateArea->isUnlocked();
		// Fill the page: rows the user cannot see are skipped, a few batches at most
		for ($round = 0; $round < 20 && count($items) < $size; $round++) {
			$stmt->execute([...$params, ...$orderParams, $batch, $offset]);
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($rows as $row) {
				$offset++;
				$item = $this->assets->visibleItem($uid, $row);
				if ($item !== null) {
					if ($locked && $this->privateArea->isPrivate($uid, $item)) {
						continue;
					}
					$fileId = (int)$item['fileId'];
					if ($side === 'visible' && (isset($flagged[$fileId]) || isset($marks[$fileId]))) {
						continue;
					}
					if ($side === 'hidden' && !isset($auto[$fileId])) {
						continue;
					}
					$items[] = $item + (isset($flagged[$fileId]) ? ['sensitive' => true] : []);
					if (count($items) >= $size) {
						return ['items' => $items, 'nextPage' => $offset];
					}
				}
			}
			if (count($rows) < $batch) {
				return ['items' => $items, 'nextPage' => null];
			}
		}
		return ['items' => $items, 'nextPage' => $offset];
	}

	/**
	 * A file's own mark wins over everything.
	 *
	 * @param list<int> $auto
	 * @param array<int, bool> $marks
	 * @return array<int, true>
	 */
	public static function withMarks(array $auto, array $marks): array {
		$out = [];
		foreach ($auto as $id) {
			$out[(int)$id] = true;
		}
		foreach ($marks as $id => $on) {
			if ($on) {
				$out[(int)$id] = true;
			} else {
				unset($out[(int)$id]);
			}
		}
		return $out;
	}

	/**
	 * What the admin needs before turning it on: the database with read access to the
	 * embeddings, and the machine-learning service. Error per part, null when fine.
	 *
	 * @return array{database: ?string, ml: ?string, model: ?string}
	 */
	public function check(): array {
		$database = !$this->db->isEnabled() ? 'not configured'
			: $this->db->check('SELECT s."assetId", a."originalFileName", a."deletedAt", a.type, a."fileCreatedAt", a."localDateTime" FROM smart_search s JOIN asset a ON a.id = s."assetId" LIMIT 1');
		$model = null;
		try {
			$model = $this->model();
			$this->embed($model, 'a photo');
			$ml = null;
		} catch (\Throwable $e) {
			$ml = $e->getMessage();
		}
		return ['database' => $database, 'ml' => $ml, 'model' => $model];
	}

	/** Users who turned it on (for the hourly job). @return list<string> */
	public function users(): array {
		return $this->ncConfig->getUsersForUserValue(Application::APP_ID, 'pref_sensitive_enabled', '1');
	}

	/**
	 * Assets at least as close to one of the terms as its limit; which terms brought each in.
	 * Their rows (path, name, type) go to $rows for mapping to files.
	 *
	 * @return array{0: array<string, true>, 1: array<string, list<int>>}
	 */
	private function termHits(\PDO $pdo, string $model, array $terms, array &$rows, ?string $scope = null): array {
		if ($terms === []) {
			return [[], []];
		}
		$params = [];
		$cols = [];
		foreach ($terms as $i => $t) {
			$cols[] = '-(s.embedding <#> ?::vector) - ? AS t' . $i;
			$params[] = SensitiveModel::vector($this->termEmbedding($model, $t['q']));
			$params[] = (float)$t['min'];
		}
		$where = ['a."deletedAt" IS NULL'];
		$whereParams = [];
		if ($scope !== null) {
			$where[] = '(starts_with(a."originalPath", ?) OR a."originalFileName" LIKE \'glimpse-frame\\_%\')';
			$whereParams[] = $scope;
		}
		$sql = 'SELECT * FROM (SELECT a.id, a."originalPath", a."originalFileName", a.type, ' . implode(', ', $cols) . '
			FROM smart_search s JOIN asset a ON a.id = s."assetId" WHERE ' . implode(' AND ', $where) . ') x
			WHERE ' . implode(' OR ', array_map(fn ($i) => 't' . $i . ' >= 0', array_keys($terms)));
		$stmt = $pdo->prepare($sql);
		$stmt->execute([...$params, ...$whereParams]);
		$hits = [];
		$byTerm = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$hits[$row['id']] = true;
			foreach (array_keys($terms) as $i) {
				if ((float)$row['t' . $i] >= 0) {
					$byTerm[$row['id']][] = $i;
				}
			}
			$rows[$row['id']] = ['id' => $row['id'], 'originalPath' => $row['originalPath'], 'originalFileName' => $row['originalFileName'], 'type' => $row['type']];
		}
		return [$hits, $byTerm];
	}

	/**
	 * The Immich assets of the files the user marked, found by the path Immich sees them at.
	 *
	 * @param array<int, bool> $marks
	 * @param array<int, string> $fileAssets gets fileId => assetId
	 * @param array<int, string> $known fileId => assetId from earlier builds; updated
	 * @return array<string, bool> assetId => the mark
	 */
	private function markAssets(\PDO $pdo, string $uid, array $marks, array &$fileAssets, array &$known): array {
		$out = [];
		$paths = [];
		// Known from earlier builds: only checked to be still there
		$cached = array_intersect_key($known, $marks);
		$known = [];
		foreach (array_chunk($cached, 1000, true) as $chunk) {
			$stmt = $pdo->prepare('SELECT id FROM asset WHERE "deletedAt" IS NULL AND id = ANY(?::uuid[])');
			$stmt->execute(['{' . implode(',', $chunk) . '}']);
			$alive = array_flip($stmt->fetchAll(\PDO::FETCH_COLUMN));
			foreach ($chunk as $id => $asset) {
				if (isset($alive[$asset])) {
					$out[$asset] = $marks[$id];
					$fileAssets[$id] = $asset;
					$known[$id] = $asset;
				}
			}
		}
		foreach ($marks as $id => $on) {
			if (isset($known[$id])) {
				continue;
			}
			$path = $this->assets->immichPathOf($uid, $id);
			if ($path !== null) {
				$paths[$path] = [$id, $on];
			}
		}
		foreach (array_chunk(array_keys($paths), 500) as $chunk) {
			$stmt = $pdo->prepare('SELECT id, "originalPath" FROM asset WHERE "deletedAt" IS NULL AND "originalPath" IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
			$stmt->execute($chunk);
			while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
				[$id, $on] = $paths[$row[1]];
				$out[$row[0]] = $on;
				$fileAssets[$id] = $row[0];
				$known[$id] = $row[0];
			}
		}
		return $out;
	}

	/**
	 * Train the SVM: the marks, and a fixed sample of the library as "probably not". Also what
	 * the log needs: marks the model gets wrong, and how the last model and this one score
	 * the marks that are new since the last one.
	 *
	 * @param array<string, bool> $markAssets
	 * @param ?array $before the last model
	 * @param array<string, bool> $newMarks
	 * @return array{svm: array, wrong: array{0: int, 1: int}, newMarks: list<array{0: bool, 1: ?float, 2: float}>}
	 */
	private function train(\PDO $pdo, array $markAssets, ?array $before, array $newMarks): array {
		$x = $y = $weight = $ids = [];
		$stmt = $pdo->prepare('SELECT "assetId", embedding::text FROM smart_search WHERE "assetId" = ANY(?::uuid[])');
		$stmt->execute(['{' . implode(',', array_keys($markAssets)) . '}']);
		while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
			$x[] = array_map('floatval', json_decode($row[1], true));
			$y[] = $markAssets[$row[0]] ? 1 : 0;
			$weight[] = 1.0;
			$ids[] = $row[0];
		}
		$marked = count($x);
		// Pick the sample first: turning every embedding into text before sorting took seconds
		$stmt = $pdo->prepare('SELECT s."assetId", s.embedding::text FROM smart_search s WHERE s."assetId" IN (
			SELECT a.id FROM asset a JOIN smart_search x ON x."assetId" = a.id WHERE a."deletedAt" IS NULL ORDER BY md5(a.id::text) LIMIT ?)');
		$stmt->execute([SensitiveModel::BACKGROUND_SIZE + count($markAssets)]);
		$background = 0;
		while (($row = $stmt->fetch(\PDO::FETCH_NUM)) && $background < SensitiveModel::BACKGROUND_SIZE) {
			if (isset($markAssets[$row[0]])) {
				continue;
			}
			$x[] = array_map('floatval', json_decode($row[1], true));
			$y[] = 0;
			$weight[] = SensitiveModel::BACKGROUND_WEIGHT;
			$background++;
		}
		$positives = array_sum($y);
		$model = SensitiveModel::train($x, $y, $weight);
		$wrong = [0, 0];
		$new = [];
		for ($i = 0; $i < $marked; $i++) {
			$score = SensitiveModel::score($model['w'], $model['b'], $x[$i]);
			if (($score >= 0) !== ($y[$i] === 1)) {
				$wrong[$y[$i]]++;
			}
			if (isset($newMarks[$ids[$i]])) {
				$old = !empty($before['w']) ? SensitiveModel::score($before['w'], (float)$before['b'], $x[$i]) : null;
				$new[] = [$y[$i] === 1, $old === null ? null : round($old, 2), round($score, 2)];
			}
		}
		return [
			'svm' => $model + ['positives' => $positives, 'negatives' => $marked - $positives],
			'wrong' => $wrong,
			'newMarks' => $new,
		];
	}

	/**
	 * One debug line per build: what the model learned from, whether it was trained again,
	 * how it does on the marks and on the new ones, and what changed in the library.
	 *
	 * @param list<int> $auto
	 */
	private function logBuild(string $uid, array $data, array $auto, int $byModel, ?array $trained): void {
		$context = [
			'app' => Application::APP_ID, 'uid' => $uid,
			'positives' => $data['learned'][0], 'negatives' => $data['learned'][1],
			'auto' => count($auto), 'byModel' => $byModel,
			'added' => $data['change']['added'], 'removed' => $data['change']['removed'],
			'took' => json_encode($data['took']),
		];
		if ($data['svm'] === null) {
			$this->logger->debug('Sensitive: {uid} built without a model ({positives} marked sensitive, {negatives} not); {auto} files sensitive by terms, {added} new, {removed} gone; took {took}', $context);
			return;
		}
		if ($trained === null) {
			$this->logger->debug('Sensitive: {uid} built with the same model; {auto} files sensitive automatically ({byModel} by the model), {added} new, {removed} gone; took {took}', $context);
			return;
		}
		$svm = $trained['svm'];
		$norm = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $svm['w'])));
		$this->logger->debug('Sensitive: {uid} trained on {positives} marked sensitive and {negatives} not ({passes} passes, gap {gap}, b {b}, |w| {norm}); '
			. 'on its own marks it gets {wrongPos} sensitive and {wrongNeg} not wrong; new marks [sensitive?, score before, after]: {newMarks}; '
			. '{auto} files sensitive automatically ({byModel} by the model), {added} new, {removed} gone; took {took}', $context + [
				'passes' => $svm['passes'] ?? null, 'gap' => $svm['gap'] ?? null, 'b' => round($svm['b'], 3), 'norm' => round($norm, 2),
				'wrongPos' => $trained['wrong'][1], 'wrongNeg' => $trained['wrong'][0],
				'newMarks' => json_encode(array_slice($trained['newMarks'], 0, 50)),
			]);
	}

	/**
	 * Assets on the SVM's sensitive side (score 0 and up). Rows go to $rows for mapping to files.
	 *
	 * @return array<string, true>
	 */
	private function modelHits(\PDO $pdo, array $svm, array &$rows, ?string $scope = null): array {
		if (empty($svm['w'])) {
			return [];
		}
		$where = ['a."deletedAt" IS NULL', '-(s.embedding <#> ?::vector) + ? >= ?'];
		$params = [SensitiveModel::vector($svm['w']), (float)$svm['b'], 0.0];
		if ($scope !== null) {
			$where[] = '(starts_with(a."originalPath", ?) OR a."originalFileName" LIKE \'glimpse-frame\\_%\')';
			$params[] = $scope;
		}
		$stmt = $pdo->prepare('SELECT a.id, a."originalPath", a."originalFileName", a.type FROM smart_search s JOIN asset a ON a.id = s."assetId"
			WHERE ' . implode(' AND ', $where));
		$stmt->execute($params);
		$hits = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$hits[$row['id']] = true;
			$rows[$row['id']] = $row;
		}
		return $hits;
	}

	/**
	 * Assets to the user's files: a still from a video counts for the video. Also how many files
	 * each term brings in, and how many only the SVM brings in.
	 *
	 * @param array<string, true> $hits from the terms
	 * @param array<string, true> $modelHits from the SVM
	 * @param array<int, string> $fileAssets gets fileId => assetId (the video's for a still)
	 * @param array<string, int> $fileOf assetId => fileId (0: not the user's), known ones are not looked up again
	 * @return array{0: list<int>, 1: array<string, int>, 2: int}
	 */
	private function toFiles(\PDO $pdo, string $uid, array $hits, array $modelHits, array $byTerm, array $terms, array $rows, array &$fileAssets, array &$fileOf): array {
		$all = array_keys($hits + $modelHits);
		$this->loadRows($pdo, array_values(array_diff($all, array_keys($rows))), $rows);
		// Stills stand for their video
		$owner = [];
		$videos = [];
		foreach ($all as $asset) {
			$still = isset($rows[$asset]) ? VideoScenes::parseName((string)$rows[$asset]['originalFileName']) : null;
			$owner[$asset] = $still[0] ?? $asset;
			if ($still !== null && !isset($rows[$still[0]])) {
				$videos[] = $still[0];
			}
		}
		$this->loadRows($pdo, array_values(array_unique($videos)), $rows);
		$file = [];
		foreach (array_unique($owner) as $asset) {
			// Which file an asset is, for this user, from the last build when it still holds
			if (!isset($fileOf[$asset])) {
				$item = isset($rows[$asset]) ? $this->assets->visibleItem($uid, $rows[$asset], false) : null;
				$fileOf[$asset] = $item !== null ? (int)$item['fileId'] : 0;
			}
			if ($fileOf[$asset] > 0) {
				$file[$asset] = $fileOf[$asset];
				$fileAssets[$fileOf[$asset]] ??= $asset;
			}
		}
		$auto = [];
		$byHit = [];
		$counts = [];
		foreach ($all as $asset) {
			$id = $file[$owner[$asset]] ?? null;
			if ($id === null) {
				continue;
			}
			$auto[$id] = true;
			if (isset($hits[$asset])) {
				$byHit[$id] = true;
				foreach ($byTerm[$asset] ?? [] as $i) {
					$counts[$terms[$i]['q']][$id] = true;
				}
			}
		}
		return [array_keys($auto), array_map('count', $counts), count(array_diff_key($auto, $byHit))];
	}

	/** @param list<string> $ids */
	private function loadRows(\PDO $pdo, array $ids, array &$rows): void {
		foreach (array_chunk($ids, 500) as $chunk) {
			$q = $pdo->prepare('SELECT id, "originalPath", "originalFileName", type FROM asset WHERE "deletedAt" IS NULL AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
			$q->execute($chunk);
			while ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
				$rows[$row['id']] = $row;
			}
		}
	}

	/** Changes when Immich gets new pictures or another model. */
	private function signature(): string {
		$count = (int)$this->db->pdo()->query('SELECT count(*) FROM smart_search')->fetchColumn();
		return $this->model() . '|' . $count;
	}

	private function model(): string {
		if ($this->model === null) {
			$ml = $this->immich->json('GET', '/system-config')['machineLearning'] ?? [];
			if (!($ml['enabled'] ?? true) || !($ml['clip']['enabled'] ?? true)) {
				throw new \RuntimeException('Smart search is turned off in Immich');
			}
			$this->model = (string)($ml['clip']['modelName'] ?? '') ?: throw new \RuntimeException('Immich did not say which search model it uses');
		}
		return $this->model;
	}

	/** A term's vector, cached per model and term. @return list<float> */
	private function termEmbedding(string $model, string $text): array {
		$key = md5($model . '|' . $text) . '.json';
		$folder = $this->folder(self::TERMS_FOLDER);
		try {
			$cached = json_decode($folder->getFile($key)->getContent(), true);
			if (is_array($cached) && $cached !== []) {
				return $cached;
			}
		} catch (NotFoundException) {
		}
		$vector = $this->embed($model, $text);
		$folder->newFile($key, json_encode($vector));
		return $vector;
	}

	/** A text as Immich's machine-learning service encodes it for search. @return list<float> */
	private function embed(string $model, string $text): array {
		$base = rtrim((string)$this->config->get('ml_ping_url'), '/');
		if ($base === '') {
			throw new \RuntimeException('The address of Immich\'s machine-learning service is not set (Settings → Connection)');
		}
		$resp = $this->clients->newClient()->post($base . '/predict', [
			'form_params' => ['entries' => json_encode(['clip' => ['textual' => ['modelName' => $model]]]), 'text' => $text],
			'timeout' => 120,
		]);
		$clip = json_decode((string)$resp->getBody(), true)['clip'] ?? null;
		$vector = is_string($clip) ? json_decode($clip, true) : $clip;
		if (!is_array($vector) || $vector === []) {
			throw new \RuntimeException('The machine-learning service returned no vector');
		}
		return array_map('floatval', $vector);
	}

	/** @return array<int, bool> */
	private function bools(array $marks): array {
		$out = [];
		foreach ($marks as $id => $on) {
			$out[(int)$id] = (bool)$on;
		}
		return $out;
	}

	private function save(string $uid, array $data): void {
		unset($data['scores'], $data['examples'], $data['near'], $data['nearOf'], $data['added'], $data['removed'], $data['model']);
		$this->folder(self::FOLDER)->newFile($uid . '.json', json_encode($data));
		$this->cache[$uid] = $data;
	}

	private function folder(string $name): ISimpleFolder {
		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException) {
			return $this->appData->newFolder($name);
		}
	}
}
