<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Service\SensitiveModel;
use PHPUnit\Framework\TestCase;

class SensitiveModelTest extends TestCase {
	public function testTrainsALinearBoundary(): void {
		// Two clusters on the unit circle's first and third quadrant, plus noise dimensions
		$x = [];
		$y = [];
		mt_srand(7);
		for ($i = 0; $i < 60; $i++) {
			$on = $i % 2;
			$x[] = [($on ? 0.6 : -0.6) + mt_rand(-10, 10) / 100, ($on ? 0.6 : -0.6) + mt_rand(-10, 10) / 100, mt_rand(-10, 10) / 100];
			$y[] = $on;
		}
		$m = SensitiveModel::train($x, $y, array_fill(0, 60, 1.0));
		$score = fn (array $v) => SensitiveModel::sigmoid($m['w'][0] * $v[0] + $m['w'][1] * $v[1] + $m['w'][2] * $v[2] + $m['b']);
		$this->assertGreaterThan(0.9, $score([0.6, 0.6, 0]));
		$this->assertLessThan(0.1, $score([-0.6, -0.6, 0]));
	}

	public function testWeightsPullTheBoundary(): void {
		// The same point once on each side: the heavier example wins
		$m = SensitiveModel::train([[1.0], [1.0]], [1, 0], [5.0, 1.0]);
		$this->assertGreaterThan(0.5, SensitiveModel::sigmoid($m['w'][0] + $m['b']));
	}

	public function testNothingToLearnFromGivesZeros(): void {
		$this->assertSame(['w' => [], 'b' => 0.0], SensitiveModel::train([], [], []));
	}

	public function testMarksWinOverTheScore(): void {
		$scores = [1 => 0.9, 2 => 0.3, 3 => 0.7];
		$marks = [1 => false, 2 => true, 4 => true];
		$this->assertSame([3 => true, 2 => true, 4 => true], SensitiveModel::flagged($scores, $marks, 0.5));
	}

	public function testCountsShrinkAsTheThresholdRises(): void {
		$counts = SensitiveModel::counts([1 => 0.25, 2 => 0.5, 3 => 0.95], [3 => false, 9 => true]);
		$this->assertSame(SensitiveModel::THRESHOLDS, array_keys($counts));
		$this->assertSame(3, $counts[20]);
		$this->assertSame(2, $counts[50]);
		$this->assertSame(1, $counts[95]);
	}

	public function testLogitIsTheInverseOfSigmoid(): void {
		$this->assertEqualsWithDelta(0.2, SensitiveModel::sigmoid(SensitiveModel::logit(0.2)), 1e-9);
		$this->assertSame('[0.5,-0.25]', SensitiveModel::vector([0.5, -0.25]));
	}
}
