<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Server-internal interfaces that the public OCP stubs extend. Only what the tests touch.

namespace OC\Hooks;

interface Emitter {
	public function listen($scope, $method, callable $callback);
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
