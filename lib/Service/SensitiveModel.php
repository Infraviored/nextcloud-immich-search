<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Glimpse\Service;

/**
 * A linear SVM on Immich's search embeddings, trained per user from what they marked
 * (SensitiveContent). The result is one vector and a bias: a picture's score is
 * w · x + b, which Postgres computes for the whole library (`-(embedding <#> w) + b`).
 * Above 0 is on the sensitive side; how far tells how sure.
 *
 * Solved to the end with dual coordinate descent (Hsieh et al. 2008, what LIBLINEAR and
 * scikit-learn's LinearSVC use), squared hinge loss, the classes weighted to count equally.
 * The problem has one optimum, so the same marks give the same model whatever the order,
 * and a few new marks move it a little. (Stochastic training that stops after a fixed number
 * of steps did not: reshuffled, the same marks gave 860 or 1,700 sensitive pictures.)
 */
class SensitiveModel {
	/** Marks as sensitive needed before there is a model; below, only terms and marks count. */
	public const MIN_POSITIVE = 20;
	/** Part of the key of a trained model: a new solver trains again from the same marks. */
	public const VERSION = 2;
	/** Weight of the background sample, pictures taken as "probably not" (a few are). */
	public const BACKGROUND_WEIGHT = 0.1;
	public const BACKGROUND_SIZE = 1000;
	/** Cap per class to keep training time and memory bounded. */
	public const MAX_PER_CLASS = 3000;
	/** Cost of a mistake against a wide margin, as LinearSVC's C. Checked on real marks: 0.1 to 1 differ little. */
	private const C = 0.3;
	/** Done when the projected gradients lie this close together (LIBLINEAR's stopping rule). */
	private const EPS = 0.01;
	private const MAX_PASSES = 80;

	/**
	 * @param list<list<float>> $x
	 * @param list<int> $y 1 sensitive, 0 not
	 * @param list<float> $weight per example
	 * @return array{w: list<float>, b: float, passes?: int, gap?: float}
	 */
	public static function train(array $x, array $y, array $weight): array {
		$n = count($x);
		if ($n === 0 || array_sum($y) === 0 || array_sum($y) === $n) {
			return ['w' => [], 'b' => 0.0];
		}
		$indices = [0 => [], 1 => []];
		foreach ($y as $i => $label) {
			$indices[$label][] = $i;
		}
		if (count($indices[0]) > self::MAX_PER_CLASS || count($indices[1]) > self::MAX_PER_CLASS) {
			$keep = [];
			foreach ([0, 1] as $label) {
				$idx = $indices[$label];
				$count = count($idx);
				if ($count <= self::MAX_PER_CLASS) {
					$keep = array_merge($keep, $idx);
				} else {
					for ($k = 0; $k < self::MAX_PER_CLASS; $k++) {
						$keep[] = $idx[(int)floor($k * $count / self::MAX_PER_CLASS)];
					}
				}
			}
			sort($keep);
			$x = array_map(static fn ($i) => $x[$i], $keep);
			$y = array_map(static fn ($i) => $y[$i], $keep);
			$weight = array_map(static fn ($i) => $weight[$i], $keep);
			$n = count($x);
		}
		// The bias as one more feature, always 1
		$d = count($x[0]) + 1;
		// Each class counts as much as the other, whatever the numbers
		$sum = [0 => 0.0, 1 => 0.0];
		foreach ($y as $i => $label) {
			$sum[$label] += $weight[$i];
		}
		$diag = $q = $s = [];
		foreach ($y as $i => $label) {
			$x[$i][] = 1.0;
			// Squared hinge: the cost enters as a term on the diagonal
			$diag[$i] = 1 / (2 * self::C * $weight[$i] * ($sum[0] + $sum[1]) / (2 * $sum[$label]));
			$norm = 0.0;
			foreach ($x[$i] as $v) {
				$norm += $v * $v;
			}
			$q[$i] = $norm + $diag[$i];
			$s[$i] = $label === 1 ? 1.0 : -1.0;
		}
		$alpha = array_fill(0, $n, 0.0);
		$w = array_fill(0, $d, 0.0);
		$order = range(0, $n - 1);
		$seed = 12345;
		$gap = INF;
		for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
			// A new order each pass converges much faster than a fixed one; a small LCG keeps
			// it reproducible without touching PHP's global RNG
			for ($i = $n - 1; $i > 0; $i--) {
				$seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
				$j = $seed % ($i + 1);
				[$order[$i], $order[$j]] = [$order[$j], $order[$i]];
			}
			$max = -INF;
			$min = INF;
			foreach ($order as $i) {
				$xi = $x[$i];
				$dot = 0.0;
				for ($k = 0; $k < $d; $k++) {
					$dot += $w[$k] * $xi[$k];
				}
				$g = $s[$i] * $dot - 1 + $diag[$i] * $alpha[$i];
				$pg = $alpha[$i] == 0.0 ? min($g, 0.0) : $g;
				$max = max($max, $pg);
				$min = min($min, $pg);
				if ($pg != 0.0) {
					$old = $alpha[$i];
					$alpha[$i] = max($old - $g / $q[$i], 0.0);
					$step = ($alpha[$i] - $old) * $s[$i];
					for ($k = 0; $k < $d; $k++) {
						$w[$k] += $step * $xi[$k];
					}
				}
			}
			$gap = $max - $min;
			if ($gap < self::EPS) {
				break;
			}
		}
		$b = array_pop($w);
		return ['w' => $w, 'b' => $b, 'passes' => min($pass, self::MAX_PASSES), 'gap' => round($gap, 4)];
	}

	/** @param list<float> $v as Postgres takes a vector literal */
	public static function vector(array $v): string {
		return '[' . implode(',', array_map(static fn ($f) => sprintf('%.7g', $f), $v)) . ']';
	}

	/** @param list<float> $w */
	public static function score(array $w, float $b, array $x): float {
		$dot = $b;
		foreach ($w as $k => $wk) {
			$dot += $wk * $x[$k];
		}
		return $dot;
	}
}
