/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { reactive, watch } from 'vue'

// Per-device display preferences: a phone and a desktop want different tile sizes.
const KEY = 'immich_search.prefs'
const defaults = { tileSize: 'medium' }

/**
 *
 */
function load() {
	try {
		return { ...defaults, ...JSON.parse(window.localStorage.getItem(KEY) || '{}') }
	} catch {
		return { ...defaults }
	}
}

export const prefs = reactive(load())

watch(prefs, () => {
	try {
		window.localStorage.setItem(KEY, JSON.stringify(prefs))
	} catch {
		// private mode or storage blocked: preferences just do not stick
	}
})
