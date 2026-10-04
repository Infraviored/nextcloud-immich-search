/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { confirmPassword, isPasswordConfirmationRequired } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import { errorMessage } from './api.js'

import '@nextcloud/password-confirmation/style.css'

const url = (path) => generateUrl('/apps/immich_search/api/private' + path)

/**
 * The private area as the frontend knows it. `version` goes up whenever what is visible
 * changes (unlocked, locked, something marked private): views reload when they see that.
 */
export const privateState = reactive({ folders: [], people: [], files: 0, unlocked: false, askPassword: true, version: 0 })

export async function loadPrivate() {
	Object.assign(privateState, (await axios.get(url(''))).data)
}

function changed() {
	privateState.version++
}

export async function togglePrivate() {
	try {
		if (privateState.unlocked) {
			await axios.post(url('/lock'))
			privateState.unlocked = false
		} else if (privateState.askPassword) {
			// Nextcloud's own dialog; skipped if the password was confirmed a moment ago.
			if (isPasswordConfirmationRequired('lax')) {
				await confirmPassword()
			}
			await axios.post(url('/unlock-password'))
			privateState.unlocked = true
		} else {
			await axios.post(url('/unlock'))
			privateState.unlocked = true
		}
		changed()
	} catch (e) {
		if (e) {
			showError(errorMessage(e))
		}
	}
}

export async function setPrivateLists(folders, people) {
	Object.assign(privateState, (await axios.put(url(''), { folders, people })).data)
	changed()
}

export async function setPrivateFiles(fileIds, on) {
	privateState.files = (await axios.post(url('/files'), { fileIds, on })).data.files
	changed()
}

export const isPrivateFolder = (path) => privateState.folders.some((f) => path === f || path.startsWith(f + '/'))
