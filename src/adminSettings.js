/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { reactive } from 'vue'
import { getAdminSettings, saveAdminSettings } from './api.js'

/** Admin settings, loaded once and shared by the settings sections. */
export const adminSettings = reactive({ loaded: false, values: {} })

export async function loadAdminSettings() {
	if (!adminSettings.loaded) {
		adminSettings.values = await getAdminSettings()
		adminSettings.loaded = true
	}
	return adminSettings.values
}

/**
 * Save some keys; returns the server answer ({settings, exclusionsApplied}).
 *
 * @param partial
 */
export async function saveAdmin(partial) {
	const res = await saveAdminSettings(partial)
	adminSettings.values = res.settings
	return res
}
