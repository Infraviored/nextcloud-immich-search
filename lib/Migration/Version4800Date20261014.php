<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** What the nudity detector found per Immich asset (SensitiveScanner). */
class Version4800Date20261014 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('glimpse_sensitive')) {
			return null;
		}
		$table = $schema->createTable('glimpse_sensitive');
		$table->addColumn('asset_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		// Where Immich sees the file, to find it in Nextcloud without asking Immich again
		$table->addColumn('path', Types::TEXT, ['notnull' => false]);
		$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'IMAGE']);
		// A still Glimpse took from a video counts for that video
		$table->addColumn('video_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		// {"FEMALE_BREAST_EXPOSED": 0.83, ...}: only classes that matter, from 0.2 up
		$table->addColumn('scores', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '{}']);
		// Highest score of those classes, so lists of candidates need no JSON parsing in SQL
		$table->addColumn('top', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('scanned', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['asset_id']);
		$table->addIndex(['top'], 'glimpse_sens_top');
		return $schema;
	}
}
