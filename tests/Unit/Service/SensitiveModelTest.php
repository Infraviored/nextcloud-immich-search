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
	/** Points around two directions in 8 dimensions, unit length, as embeddings are. */
	private function data(int $pos, int $neg, int $seed = 7): array {
		mt_srand($seed);
		$x = $y = [];
		for ($i = 0; $i < $pos + $neg; $i++) {
			$v = [];
			for ($k = 0; $k < 8; $k++) {
				$v[] = (mt_rand() / mt_getrandmax() - 0.5) * 0.6 + ($i < $pos ? ($k === 0 ? 1.0 : 0.0) : ($k === 1 ? 1.0 : 0.0));
			}
			$n = sqrt(array_sum(array_map(static fn ($a) => $a * $a, $v)));
			$x[] = array_map(static fn ($a) => $a / $n, $v);
			$y[] = $i < $pos ? 1 : 0;
		}
		return [$x, $y];
	}

	public function testSeparatesTwoGroups(): void {
		[$x, $y] = $this->data(30, 200);
		$m = SensitiveModel::train($x, $y, array_fill(0, count($x), 1.0));
		foreach ($x as $i => $v) {
			$this->assertSame($y[$i] === 1, SensitiveModel::score($m['w'], $m['b'], $v) > 0, 'example ' . $i);
		}
	}

	public function testFewPositivesStillCountAsMuch(): void {
		// 10 against 500: without balancing the classes, everything would come out "not"
		[$x, $y] = $this->data(10, 500);
		$m = SensitiveModel::train($x, $y, array_fill(0, count($x), 1.0));
		$this->assertGreaterThan(0, SensitiveModel::score($m['w'], $m['b'], $x[0]));
	}

	public function testSameDataSameModel(): void {
		[$x, $y] = $this->data(20, 50);
		$weight = array_fill(0, count($x), 1.0);
		$this->assertSame(SensitiveModel::train($x, $y, $weight), SensitiveModel::train($x, $y, $weight));
	}

	public function testOrderDoesNotMatter(): void {
		// Solved to the end: shuffled examples give the same model, not a new draw
		[$x, $y] = $this->data(40, 120);
		$weight = array_fill(0, count($x), 1.0);
		$m = SensitiveModel::train($x, $y, $weight);
		$order = range(count($x) - 1, 0, -1);
		$r = SensitiveModel::train(array_map(fn ($i) => $x[$i], $order), array_map(fn ($i) => $y[$i], $order), $weight);
		$this->assertEqualsWithDelta($m['b'], $r['b'], 0.02);
		foreach ($m['w'] as $k => $v) {
			$this->assertEqualsWithDelta($v, $r['w'][$k], 0.02);
		}
	}

	public function testOneClassOnlyGivesNoModel(): void {
		$this->assertSame(['w' => [], 'b' => 0.0], SensitiveModel::train([[1.0, 0.0]], [1], [1.0]));
		$this->assertSame(['w' => [], 'b' => 0.0], SensitiveModel::train([], [], []));
	}

	public function testVector(): void {
		$this->assertSame('[0.5,-0.25]', SensitiveModel::vector([0.5, -0.25]));
	}

	public function testSubsamplesClassAboveMax(): void {
		[$x, $y] = $this->data(25, 3500);
		$weight = array_fill(0, count($x), 1.0);
		$m1 = SensitiveModel::train($x, $y, $weight);
		$m2 = SensitiveModel::train($x, $y, $weight);
		$this->assertSame($m1, $m2);
		$this->assertLessThanOrEqual(80, $m1['passes']);
		$this->assertNotEmpty($m1['w']);
	}
}
