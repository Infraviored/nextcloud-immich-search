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
 * Optional: recognises nudity, so each user can show such pictures marked, hide them or see
 * only them. Off unless the admin allows it (`sensitive_detection`) and the user turns it on.
 *
 * No extra model: Immich's search embeddings are read from its database and a linear
 * classifier is trained per user (SensitiveModel). First guesses come from comparing every
 * picture to a few sensitive and neutral terms; what the user marks by hand counts more,
 * and always wins over the score. Scores are stored per file the user can see, so the
 * threshold can change without a new run.
 *
 * Stored per user in app data, `sensitive/<uid>.json`:
 * {"scores": {fileId: score}, "marks": {fileId: bool}, "signature": "...", "built": time,
 *  "examples": [sensitive, neutral], "error": null}
 */
class SensitiveContent {
	private const FOLDER = 'sensitive';
	/** Embedded terms per model, shared by all users (not a user's file name). */
	private const TERMS_FOLDER = 'sensitive-terms';
	private const MODES = ['all', 'hide', 'only'];
	/** Rebuilt at least this often even when nothing seems to have changed. */
	private const MAX_AGE = 7 * 86400;

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
			$this->cache[$uid] = $data + ['scores' => [], 'marks' => [], 'signature' => '', 'built' => 0, 'examples' => [0, 0], 'error' => null];
		}
		return $this->cache[$uid];
	}

	/** @return array<int, true> the files that count as sensitive for $uid right now */
	public function flagged(string $uid): array {
		$data = $this->get($uid);
		return SensitiveModel::flagged($this->ints($data['scores']), $this->bools($data['marks']), $this->threshold($uid));
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
	 * Mark files by hand: true sensitive, false not, null back to the score. Only files the
	 * user can reach. The next run trains with them.
	 *
	 * @param array<int|string, ?bool> $marks
	 * @return array{previous: array<int, ?bool>, sensitive: array<int, bool>}
	 */
	public function mark(string $uid, array $marks): array {
		$data = $this->get($uid);
		$folder = $this->rootFolder->getUserFolder($uid);
		$previous = [];
		$now = [];
		$threshold = $this->threshold($uid);
		foreach (array_slice($marks, 0, 1000, true) as $id => $on) {
			$id = (int)$id;
			if ($id <= 0 || $folder->getFirstNodeById($id) === null) {
				continue;
			}
			$previous[$id] = $data['marks'][$id] ?? null;
			if ($on === null) {
				unset($data['marks'][$id]);
			} else {
				$data['marks'][$id] = (bool)$on;
			}
			$now[$id] = $data['marks'][$id] ?? (($data['scores'][$id] ?? 0) >= $threshold);
		}
		$data['marksChanged'] = time();
		$this->save($uid, $data);
		return ['previous' => $previous, 'sensitive' => $now];
	}

	/** For the settings: how far it is, and how many each threshold would flag. */
	public function status(string $uid): array {
		$data = $this->get($uid);
		$marks = $this->bools($data['marks']);
		return [
			'built' => $data['built'] ?: null,
			'error' => $data['error'],
			'examples' => $data['examples'],
			'marked' => count(array_filter($marks)),
			'unmarked' => count($marks) - count(array_filter($marks)),
			'counts' => SensitiveModel::counts($this->ints($data['scores']), $marks),
		];
	}

	/** Has anything changed that the last run did not see (pictures, model, marks)? */
	public function needsRebuild(string $uid): bool {
		$data = $this->get($uid);
		return $data['built'] === 0
			|| $data['built'] < time() - self::MAX_AGE
			|| ($data['marksChanged'] ?? 0) > $data['built']
			|| $data['signature'] !== $this->signature();
	}

	/**
	 * Train and score for $uid. Seconds to a minute; run from background jobs and occ.
	 * Failures are stored for the settings page and rethrown.
	 */
	public function rebuild(string $uid): array {
		$data = $this->get($uid);
		try {
			$signature = $this->signature();
			$terms = $this->terms();
			$pdo = $this->db->pdo('120s');

			[$x, $y, $weight] = $this->firstGuesses($pdo, $terms);
			$auto = [array_sum($y), count($y) - array_sum($y)];
			foreach ($this->markedEmbeddings($pdo, $uid, $this->bools($data['marks'])) as [$vector, $on]) {
				$x[] = $vector;
				$y[] = $on ? 1 : 0;
				$weight[] = SensitiveModel::MANUAL_WEIGHT;
			}
			$sensitive = array_sum($y);
			$scores = [];
			if ($sensitive >= SensitiveModel::MIN_EXAMPLES && count($y) - $sensitive >= SensitiveModel::MIN_EXAMPLES) {
				$model = SensitiveModel::train($x, $y, $weight);
				unset($x);
				$scores = $this->score($pdo, $uid, $model['w'], $model['b']);
			}
			$data['scores'] = $scores;
			$data['signature'] = $signature;
			$data['built'] = time();
			$data['examples'] = $auto;
			$data['error'] = null;
		} catch (\Throwable $e) {
			$data['error'] = $e->getMessage();
			$data['built'] = time();
			$this->save($uid, $data);
			throw $e;
		}
		$this->save($uid, $data);
		return ['flagged' => count($this->flagged($uid)), 'scored' => count($scores), 'examples' => $auto];
	}

	/**
	 * What the admin needs before turning it on: the database with read access to the
	 * embeddings, and the machine-learning service. Error per part, null when fine.
	 *
	 * @return array{database: ?string, ml: ?string, model: ?string}
	 */
	public function check(): array {
		$database = !$this->db->isEnabled() ? 'not configured'
			: $this->db->check('SELECT s."assetId", a."originalFileName", a."deletedAt", a.type FROM smart_search s JOIN asset a ON a.id = s."assetId" LIMIT 1');
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

	private function threshold(string $uid): float {
		$t = (int)$this->prefs->get($uid, 'sensitive_threshold');
		return max(SensitiveModel::FLOOR, min(0.99, $t / 100));
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

	/**
	 * The terms as vectors of the current model, cached per model and term as each arrives.
	 *
	 * @return array{sensitive: list<list<float>>, neutral: list<list<float>>}
	 */
	private function terms(): array {
		$model = $this->model();
		return [
			'sensitive' => array_map(fn ($t) => $this->termEmbedding($model, $t), SensitiveModel::POSITIVE),
			'neutral' => array_map(fn ($t) => $this->termEmbedding($model, $t), SensitiveModel::NEUTRAL),
		];
	}

	/** One term's vector, saved as soon as fetched so a failed run keeps what it got. @return list<float> */
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

	/**
	 * Pictures that are clearly closer to a sensitive term than to any neutral one, and
	 * clearly the other way round; spread over the library, the same ones every run.
	 *
	 * @return array{0: list<list<float>>, 1: list<int>, 2: list<float>}
	 */
	private function firstGuesses(\PDO $pdo, array $terms): array {
		$params = [];
		$sim = function (array $v) use (&$params) {
			$params[] = SensitiveModel::vector($v);
			return '-(s.embedding <#> ?::vector)';
		};
		$sensitive = implode(', ', array_map($sim, $terms['sensitive']));
		$neutral = implode(', ', array_map($sim, $terms['neutral']));
		$sql = 'WITH sc AS (SELECT s.embedding, s."assetId", GREATEST(' . $sensitive . ') - GREATEST(' . $neutral . ') AS m
				FROM smart_search s JOIN asset a ON a.id = s."assetId" WHERE a."deletedAt" IS NULL)
			(SELECT 1 AS y, embedding::text AS e FROM sc WHERE m > ? ORDER BY md5(sc."assetId"::text) LIMIT ?)
			UNION ALL
			(SELECT 0, embedding::text FROM sc WHERE m < ? ORDER BY md5(sc."assetId"::text) LIMIT ?)';
		$stmt = $pdo->prepare($sql);
		$stmt->execute([...$params, SensitiveModel::SENSITIVE_MARGIN, SensitiveModel::MAX_SENSITIVE, SensitiveModel::NEUTRAL_MARGIN, SensitiveModel::MAX_NEUTRAL]);
		$x = $y = $weight = [];
		while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
			$x[] = array_map('floatval', json_decode($row[1], true));
			$y[] = (int)$row[0];
			$weight[] = 1.0;
		}
		return [$x, $y, $weight];
	}

	/**
	 * Embeddings of the files the user marked, found by the path Immich sees them at.
	 *
	 * @param array<int, bool> $marks
	 * @return list<array{0: list<float>, 1: bool}>
	 */
	private function markedEmbeddings(\PDO $pdo, string $uid, array $marks): array {
		$paths = [];
		foreach ($marks as $id => $on) {
			$path = $this->assets->immichPathOf($uid, $id);
			if ($path !== null) {
				$paths[$path] = $on;
			}
		}
		$out = [];
		foreach (array_chunk(array_keys($paths), 500) as $chunk) {
			$stmt = $pdo->prepare('SELECT a."originalPath", s.embedding::text FROM asset a JOIN smart_search s ON s."assetId" = a.id
				WHERE a."deletedAt" IS NULL AND a."originalPath" IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
			$stmt->execute($chunk);
			while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
				$out[] = [array_map('floatval', json_decode($row[1], true)), $paths[$row[0]]];
			}
		}
		return $out;
	}

	/**
	 * Score every picture in Immich, keep those above the floor that $uid can see, as their
	 * file ids. A still from a video counts for the video, with its highest score.
	 *
	 * @param list<float> $w
	 * @return array<int, float>
	 */
	private function score(\PDO $pdo, string $uid, array $w, float $b): array {
		$stmt = $pdo->prepare('SELECT a.id, a."originalPath", a."originalFileName", a.type, -(s.embedding <#> ?::vector) AS z
			FROM smart_search s JOIN asset a ON a.id = s."assetId" WHERE a."deletedAt" IS NULL AND -(s.embedding <#> ?::vector) >= ?');
		$vector = SensitiveModel::vector($w);
		$stmt->execute([$vector, $vector, SensitiveModel::logit(SensitiveModel::FLOOR) - $b]);
		$hits = [];
		$stills = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$p = SensitiveModel::sigmoid((float)$row['z'] + $b);
			$still = VideoScenes::parseName((string)$row['originalFileName']);
			if ($still !== null) {
				$stills[$still[0]] = max($stills[$still[0]] ?? 0.0, $p);
			} else {
				$hits[$row['id']] = ['id' => $row['id'], 'originalPath' => $row['originalPath'], 'type' => $row['type'], 'p' => $p];
			}
		}
		foreach (array_chunk(array_keys($stills), 500) as $chunk) {
			$q = $pdo->prepare('SELECT id, "originalPath", type FROM asset WHERE "deletedAt" IS NULL AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
			$q->execute($chunk);
			while ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
				$p = max($stills[$row['id']], $hits[$row['id']]['p'] ?? 0.0);
				$hits[$row['id']] = $row + ['p' => $p];
			}
		}
		$scores = [];
		foreach ($hits as $hit) {
			$item = $this->assets->visibleItem($uid, $hit, false);
			if ($item !== null) {
				$scores[$item['fileId']] = round(max($scores[$item['fileId']] ?? 0.0, $hit['p']), 3);
			}
		}
		return $scores;
	}

	/** @return array<int, float> */
	private function ints(array $scores): array {
		$out = [];
		foreach ($scores as $id => $p) {
			$out[(int)$id] = (float)$p;
		}
		return $out;
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
