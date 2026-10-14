<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Service;

/**
 * The arithmetic behind SensitiveContent, without Nextcloud or Immich: a linear classifier
 * on Immich's search embeddings. Sensitive when sigmoid(w · x + b) reaches the threshold.
 */
class SensitiveModel {
	/** What counts as sensitive. Encoded with Immich's search model, compared to every picture. */
	public const POSITIVE = [
		'a photo of a naked woman with bare breasts',
		'a close-up photo of exposed nipples',
		'a photo of a nude person',
		'a photo of naked buttocks',
		'an explicit sexual photo',
	];

	/**
	 * Everyday subjects to compare against. A picture counts as a first guess only when it is
	 * clearly closer to a sensitive term than to all of these. ("naked genitals" was dropped:
	 * on SigLIP2 it matched flowers and beaches more than anything else.)
	 */
	public const NEUTRAL = [
		'a photo of a person wearing clothes',
		'a photo of people in swimwear at the beach',
		'a photo of a landscape',
		'a photo of food',
		'a photo of a building',
		'a photo of an animal',
		'a screenshot or document',
		'a photo of a group of friends',
		'a selfie of a face',
		'a photo of a car',
		'a photo of a room interior',
		'a photo of a child playing',
	];

	/**
	 * First guesses for training: margin (closest sensitive term minus closest neutral one)
	 * above this counts as sensitive, below NEUTRAL_MARGIN as not. Measured with SigLIP2
	 * SO400M on 58,000 pictures: a demo library without nudity peaks at 0.024.
	 */
	public const SENSITIVE_MARGIN = 0.05;
	public const NEUTRAL_MARGIN = 0.0;

	/** At most this many first guesses per side; sampled evenly, the same ones every run. */
	public const MAX_SENSITIVE = 300;
	public const MAX_NEUTRAL = 600;
	/** A picture the user marked by hand counts this much more than a first guess. */
	public const MANUAL_WEIGHT = 5.0;
	/** Without this many examples on each side there is no model, and only marks count. */
	public const MIN_EXAMPLES = 10;

	/** Scores below this are not stored; the lowest threshold the user can pick. */
	public const FLOOR = 0.2;
	/** Thresholds offered to the user, in percent. */
	public const THRESHOLDS = [20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95];

	/**
	 * Logistic regression by gradient descent, with weights per example and a little L2.
	 * 900 examples of 1152 numbers: a few seconds in PHP, in the background job.
	 *
	 * @param list<list<float>> $x examples
	 * @param list<int> $y 1 sensitive, 0 not
	 * @param list<float> $weight per example
	 * @return array{w: list<float>, b: float}
	 */
	public static function train(array $x, array $y, array $weight, int $iterations = 100, float $rate = 5.0, float $l2 = 0.001): array {
		$d = count($x[0] ?? []);
		$w = array_fill(0, $d, 0.0);
		$b = 0.0;
		$total = array_sum($weight);
		if ($d === 0 || $total <= 0) {
			return ['w' => $w, 'b' => $b];
		}
		$n = count($x);
		for ($it = 0; $it < $iterations; $it++) {
			$grad = array_fill(0, $d, 0.0);
			$gb = 0.0;
			for ($i = 0; $i < $n; $i++) {
				$xi = $x[$i];
				$z = $b;
				for ($j = 0; $j < $d; $j++) {
					$z += $w[$j] * $xi[$j];
				}
				$e = (self::sigmoid($z) - $y[$i]) * $weight[$i];
				$gb += $e;
				for ($j = 0; $j < $d; $j++) {
					$grad[$j] += $e * $xi[$j];
				}
			}
			for ($j = 0; $j < $d; $j++) {
				$w[$j] -= $rate * ($grad[$j] / $total + $l2 * $w[$j]);
			}
			$b -= $rate * $gb / $total;
		}
		return ['w' => $w, 'b' => $b];
	}

	public static function sigmoid(float $z): float {
		return 1.0 / (1.0 + exp(-max(-50.0, min(50.0, $z))));
	}

	/** The z (w · x + b) at which the score reaches $p. */
	public static function logit(float $p): float {
		return log($p / (1.0 - $p));
	}

	/** For pgvector: "[0.1,-0.2,...]". */
	public static function vector(array $v): string {
		return '[' . implode(',', array_map(static fn ($f) => sprintf('%.7g', $f), $v)) . ']';
	}

	/**
	 * Which files count as sensitive: a mark wins over the score.
	 *
	 * @param array<int, float> $scores fileId => score (0..1)
	 * @param array<int, bool> $marks fileId => marked by hand
	 * @return array<int, true>
	 */
	public static function flagged(array $scores, array $marks, float $threshold): array {
		$out = [];
		foreach ($scores as $id => $p) {
			if ($p >= $threshold && ($marks[$id] ?? true)) {
				$out[(int)$id] = true;
			}
		}
		foreach ($marks as $id => $on) {
			if ($on) {
				$out[(int)$id] = true;
			}
		}
		return $out;
	}

	/**
	 * How many files each offered threshold would flag (for the slider).
	 *
	 * @return array<int, int> percent => count
	 */
	public static function counts(array $scores, array $marks): array {
		$out = [];
		foreach (self::THRESHOLDS as $t) {
			$out[$t] = count(self::flagged($scores, $marks, $t / 100));
		}
		return $out;
	}
}
