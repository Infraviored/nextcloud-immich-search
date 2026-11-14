<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Migration;

use OCA\Glimpse\Service\IndexAdmin;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Rewrites the libraries' exclusion patterns once without the paths of tiny images that
 * earlier versions put there (see SmallImagePruner): they made Immich's offline check run
 * for hours. Patterns the admin added by hand stay. Immich unreachable: logged and skipped,
 * the next change on the admin page writes them anyway.
 */
class DropPrunedPatterns implements IRepairStep {
	public function __construct(
		private IndexAdmin $index,
	) {
	}

	public function getName(): string {
		return 'Glimpse: drop tiny-image paths from the Immich exclusion patterns';
	}

	public function run(IOutput $output): void {
		try {
			$count = $this->index->applyExclusions(false);
			$output->info("Exclusion patterns rewritten ($count managed)");
		} catch (\Throwable $e) {
			$output->warning('Could not rewrite the exclusion patterns: ' . $e->getMessage());
		}
	}
}
