/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import { reactive, watch } from 'vue'
import { savePrefs } from './api.js'

// Per-device display preferences: a phone and a desktop want different tile sizes.
const KEY = 'immich_search.prefs'
const defaults = { tileSize: 'medium' }

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

// Per-user preferences, stored on the server (UserPrefs.php), follow the user everywhere.
export const userPrefs = reactive(loadState('immich_search', 'prefs', { search_disabled: false, start_view: 'search', show_videos: true }))

export async function saveUserPrefs(changes) {
	Object.assign(userPrefs, await savePrefs(changes))
	return userPrefs
}
