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

/**
 * Optional: each user says what counts as sensitive for them, and can show such pictures
 * marked, hide them or see only them. Off unless the admin allows it (`sensitive_detection`)
 * and the user turns it on.
 *
 * No extra model, nothing trained. Immich's search embeddings are read from its database:
 *
 * - Terms: the user searches, picks the last result that still counts ("Up to here") and
 *   the term is saved with that picture's similarity as its limit. A picture counts when it
 *   is at least that close to any of the user's terms.
 * - Marks by hand always win for their own file. They also move the limit locally: the
 *   pictures most like a marked one (NEIGHBOURS, at least NEIGHBOUR_MIN alike) follow the
 *   mark; when several marks are near, the closest decides.
 *
 * Stored per user in app data, `sensitive/<uid>.json`:
 * {"terms": [{"q", "min", "model"}], "marks": {fileId: bool}, "auto": [fileId],
 *  "assets": {fileId: assetId}, "counts": {q: n}, "added": n, "removed": n,
 *  "near": {assetId: [[assetId, sim]]},
 *  "signature": "...", "built": time, "error": null}
 */
class SensitiveContent {
	/** How many of the most similar pictures follow a hand mark. */
	public const NEIGHBOURS = 12;
	/** ... and only those this alike (cosine of the image embeddings; a series is ~0.95). */
	public const NEIGHBOUR_MIN = 0.85;
	public const MAX_TERMS = 30;
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
		IAppDataFactory $appDataFactory,
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
			$this->cache[$uid] = $data + ['terms' => [], 'marks' => [], 'auto' => [], 'assets' => [], 'counts' => [], 'added' => 0, 'removed' => 0,
				'near' => [], 'signature' => '', 'built' => 0, 'error' => null];
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
			$params[] = self::vector($this->termEmbedding($this->model(), trim($q)));
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
		$model = $this->model();
		$stmt = $this->db->pdo('20s')->prepare('SELECT -(embedding <#> ?::vector) FROM smart_search WHERE "assetId" = ?');
		$stmt->execute([self::vector($this->termEmbedding($model, $q)), $assetId]);
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
			'marked' => count(array_filter($marks)),
			'unmarked' => count($marks) - count(array_filter($marks)),
			'added' => (int)$data['added'],
			'removed' => (int)$data['removed'],
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
	 * Work out which files count: the terms, then the pictures near hand marks. A second or a
	 * few; the neighbours of each mark are kept, so only new marks cost a search. Failures are
	 * stored for the settings page and rethrown.
	 *
	 * @return array{flagged: int, terms: int, added: int, removed: int}
	 */
	public function rebuild(string $uid): array {
		$data = $this->get($uid);
		try {
			$signature = $this->signature();
			$model = $this->model();
			$pdo = $this->db->pdo('120s');
			$terms = array_values(array_filter($data['terms'], fn ($t) => ($t['model'] ?? '') === $model));
			$rows = [];
			[$hits, $byTerm] = $this->termHits($pdo, $model, $terms, $rows);

			// Fresh neighbours once a day and when the pictures or the model change
			$near = $data['signature'] === $signature && $data['built'] >= time() - self::MAX_AGE ? $data['near'] : [];
			$fileAssets = [];
			$markAssets = $this->markAssets($pdo, $uid, $this->bools($data['marks']), $fileAssets);
			$near = array_intersect_key($near, $markAssets);
			foreach ($this->neighbours($pdo, array_keys(array_diff_key($markAssets, $near))) as $mark => $list) {
				$near[$mark] = $list;
			}
			$verdicts = [];
			foreach ($near as $mark => $list) {
				foreach ($list as [$asset, $sim]) {
					$verdicts[$asset][] = [$sim, $markAssets[$mark]];
				}
			}
			$decided = self::decide($hits, $verdicts);

			[$auto, $counts, $added, $removed] = $this->toFiles($pdo, $uid, $decided, $hits, $byTerm, $terms, $rows, $fileAssets);
			$data['auto'] = $auto;
			$data['assets'] = $fileAssets;
			$data['counts'] = $counts;
			$data['added'] = $added;
			$data['removed'] = $removed;
			$data['near'] = $near;
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
		return ['flagged' => count($this->flagged($uid)), 'terms' => count($terms), 'added' => $added, 'removed' => $removed];
	}

	/**
	 * What the terms say and what nearby marks say, per Immich asset. A mark nearby wins over
	 * the terms; of several, the most similar one.
	 *
	 * @param array<string, true> $hits assets at least as close to a term as its limit
	 * @param array<string, list<array{0: float, 1: bool}>> $verdicts asset => [similarity to a mark, that mark]
	 * @return array<string, true>
	 */
	public static function decide(array $hits, array $verdicts): array {
		$out = $hits;
		foreach ($verdicts as $asset => $list) {
			usort($list, static fn ($a, $b) => $b[0] <=> $a[0]);
			if ($list[0][1]) {
				$out[$asset] = true;
			} else {
				unset($out[$asset]);
			}
		}
		return $out;
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

	/** @param list<float> $v */
	public static function vector(array $v): string {
		return '[' . implode(',', array_map(static fn ($f) => sprintf('%.7g', $f), $v)) . ']';
	}

	/**
	 * Assets at least as close to one of the terms as its limit; which terms brought each in.
	 * Their rows (path, name, type) go to $rows for mapping to files.
	 *
	 * @return array{0: array<string, true>, 1: array<string, list<int>>}
	 */
	private function termHits(\PDO $pdo, string $model, array $terms, array &$rows): array {
		if ($terms === []) {
			return [[], []];
		}
		$params = [];
		$cols = [];
		foreach ($terms as $i => $t) {
			$cols[] = '-(s.embedding <#> ?::vector) - ? AS t' . $i;
			$params[] = self::vector($this->termEmbedding($model, $t['q']));
			$params[] = (float)$t['min'];
		}
		$sql = 'SELECT * FROM (SELECT a.id, a."originalPath", a."originalFileName", a.type, ' . implode(', ', $cols) . '
			FROM smart_search s JOIN asset a ON a.id = s."assetId" WHERE a."deletedAt" IS NULL) x
			WHERE ' . implode(' OR ', array_map(fn ($i) => 't' . $i . ' >= 0', array_keys($terms)));
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
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
	 * @return array<string, bool> assetId => the mark
	 */
	private function markAssets(\PDO $pdo, string $uid, array $marks, array &$fileAssets): array {
		$paths = [];
		foreach ($marks as $id => $on) {
			$path = $this->assets->immichPathOf($uid, $id);
			if ($path !== null) {
				$paths[$path] = [$id, $on];
			}
		}
		$out = [];
		foreach (array_chunk(array_keys($paths), 500) as $chunk) {
			$stmt = $pdo->prepare('SELECT id, "originalPath" FROM asset WHERE "deletedAt" IS NULL AND "originalPath" IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
			$stmt->execute($chunk);
			while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
				[$id, $on] = $paths[$row[1]];
				$out[$row[0]] = $on;
				$fileAssets[$id] = $row[0];
			}
		}
		return $out;
	}

	/**
	 * The pictures most like each of $marks (itself not included), through Immich's vector index.
	 *
	 * @param list<string> $marks
	 * @return array<string, list<array{0: string, 1: float}>>
	 */
	private function neighbours(\PDO $pdo, array $marks): array {
		$out = [];
		$stmt = $pdo->prepare('SELECT n."assetId", n.sim FROM smart_search m, LATERAL (
				SELECT s."assetId", 1 - (s.embedding <=> m.embedding) AS sim FROM smart_search s
				ORDER BY s.embedding <=> m.embedding LIMIT ' . (self::NEIGHBOURS + 1) . ') n
			WHERE m."assetId" = ? AND n."assetId" <> m."assetId" AND n.sim >= ?');
		foreach ($marks as $mark) {
			$stmt->execute([$mark, self::NEIGHBOUR_MIN]);
			$out[$mark] = array_map(fn ($r) => [$r[0], round((float)$r[1], 4)], $stmt->fetchAll(\PDO::FETCH_NUM));
		}
		return $out;
	}

	/**
	 * Assets to the user's files: a still from a video counts for the video. Also how many files
	 * each term brings in, and how many the marks nearby added and took out.
	 *
	 * @param array<string, true> $decided
	 * @param array<string, true> $hits
	 * @param array<int, string> $fileAssets gets fileId => assetId (the video's for a still)
	 * @return array{0: list<int>, 1: array<string, int>, 2: int, 3: int}
	 */
	private function toFiles(\PDO $pdo, string $uid, array $decided, array $hits, array $byTerm, array $terms, array $rows, array &$fileAssets): array {
		$all = array_keys($decided + $hits);
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
			$item = isset($rows[$asset]) ? $this->assets->visibleItem($uid, $rows[$asset], false) : null;
			if ($item !== null) {
				$file[$asset] = (int)$item['fileId'];
				$fileAssets[(int)$item['fileId']] ??= $asset;
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
			if (isset($decided[$asset])) {
				$auto[$id] = true;
			}
			if (isset($hits[$asset])) {
				$byHit[$id] = true;
				foreach ($byTerm[$asset] ?? [] as $i) {
					$counts[$terms[$i]['q']][$id] = true;
				}
			}
		}
		return [
			array_keys($auto),
			array_map('count', $counts),
			count(array_diff_key($auto, $byHit)),
			count(array_diff_key($byHit, $auto)),
		];
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
		unset($data['scores'], $data['examples']);
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
