/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { ref } from 'vue'
import { errorMessage } from '../api.js'

/** Run a request with a busy flag and a toast for the outcome. */
export function useAction() {
	const busy = ref(false)

	async function act(fn, success) {
		busy.value = true
		try {
			const res = await fn()
			if (success) {
				showSuccess(typeof success === 'function' ? success(res) : success)
			}
			return res
		} catch (e) {
			showError(errorMessage(e))
			return null
		} finally {
			busy.value = false
		}
	}

	return { busy, act }
}
