<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

// Unit tests run against the public Nextcloud API stubs (nextcloud/ocp), without a server.
require_once __DIR__ . '/../vendor/autoload.php';
foreach (spl_autoload_functions() as $fn) {
	if (is_array($fn) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
		$fn[0]->setPsr4('OCA\\Glimpse\\', [__DIR__ . '/../lib']);
		$fn[0]->setPsr4('OCA\\Glimpse\\Tests\\', [__DIR__]);
	}
}
require_once __DIR__ . '/stubs/OC.php';
