<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Migration;

use OCA\Glimpse\Service\SensitiveContent;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Migrates existing per-user sensitive data (sensitive/<uid>.json) into dedicated
 * filter objects (sensitive/filters/<filterId>.json) for shared filter collaboration.
 */
class MigrateSensitiveFilters implements IRepairStep {
	public function __construct(
		private SensitiveContent $sensitive,
	) {
	}

	public function getName(): string {
		return 'Glimpse: migrate sensitive content data to filter objects';
	}

	public function run(IOutput $output): void {
		try {
			$count = $this->sensitive->migrateAll();
			$output->info("Sensitive filters migrated ($count filters created)");
		} catch (\Throwable $e) {
			$output->warning('Could not migrate sensitive filters: ' . $e->getMessage());
		}
	}
}
