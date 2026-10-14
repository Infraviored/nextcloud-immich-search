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
 * Trained with Pegasos (stochastic sub-gradient on the hinge loss with L2), the classes
 * weighted to count equally, in a fixed order: the same marks give the same model.
 */
class SensitiveModel {
	/** Marks as sensitive needed before there is a model; below, only terms and marks count. */
	public const MIN_POSITIVE = 20;
	/** Weight of the background sample, pictures taken as "probably not" (a few are). */
	public const BACKGROUND_WEIGHT = 0.1;
	public const BACKGROUND_SIZE = 1000;
	private const EPOCHS = 12;
	/** Regularisation per example: λ = 1 / (C · n). */
	private const C = 10.0;

	/**
	 * @param list<list<float>> $x
	 * @param list<int> $y 1 sensitive, 0 not
	 * @param list<float> $weight per example
	 * @return array{w: list<float>, b: float}
	 */
	public static function train(array $x, array $y, array $weight): array {
		$n = count($x);
		if ($n === 0 || array_sum($y) === 0 || array_sum($y) === $n) {
			return ['w' => [], 'b' => 0.0];
		}
		// The bias as one more feature, always 1
		$d = count($x[0]) + 1;
		// Each class counts as much as the other, whatever the numbers
		$sum = [0 => 0.0, 1 => 0.0];
		foreach ($y as $i => $label) {
			$sum[$label] += $weight[$i];
		}
		$c = [];
		foreach ($y as $i => $label) {
			$c[$i] = $weight[$i] * ($sum[0] + $sum[1]) / (2 * $sum[$label]);
		}
		$lambda = 1.0 / (self::C * $n);
		// w = $scale · $v, so shrinking w is one multiplication instead of d
		$v = array_fill(0, $d, 0.0);
		$scale = 1.0;
		$t = 0;
		$order = range(0, $n - 1);
		$seed = 12345;
		for ($epoch = 0; $epoch < self::EPOCHS; $epoch++) {
			// Fisher–Yates with a small LCG: a fixed order, without touching PHP's global RNG
			for ($i = $n - 1; $i > 0; $i--) {
				$seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
				$j = $seed % ($i + 1);
				[$order[$i], $order[$j]] = [$order[$j], $order[$i]];
			}
			foreach ($order as $i) {
				$t++;
				$eta = 1.0 / ($lambda * ($t + 100));
				$s = $y[$i] === 1 ? 1.0 : -1.0;
				$xi = $x[$i];
				$xi[] = 1.0;
				$dot = 0.0;
				for ($k = 0; $k < $d; $k++) {
					$dot += $v[$k] * $xi[$k];
				}
				$margin = $s * $scale * $dot;
				$scale *= 1 - $eta * $lambda;
				if ($margin < 1) {
					$step = $eta * $c[$i] * $s / $scale;
					for ($k = 0; $k < $d; $k++) {
						$v[$k] += $step * $xi[$k];
					}
				}
				if ($scale < 1e-6) {
					foreach ($v as $k => $val) {
						$v[$k] = $val * $scale;
					}
					$scale = 1.0;
				}
			}
		}
		$w = array_map(static fn ($val) => $val * $scale, $v);
		$b = array_pop($w);
		return ['w' => $w, 'b' => $b];
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
