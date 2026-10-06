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
import { t } from './l10n.js'

import '@nextcloud/password-confirmation/style.css'

const url = (path) => generateUrl('/apps/glimpse/api/private' + path)

/**
 * The private area as the frontend knows it. `version` goes up when locking or unlocking
 * changes what is visible everywhere: views reload when they see that.
 *
 * `listsKnown`: the folder and people lists only come while unlocked (older servers sent
 * them always). Without them they can be neither shown nor edited; while locked the
 * server only adds to them, it never removes.
 */
export const privateState = reactive({
	folders: [],
	people: [],
	files: 0,
	folderCount: 0,
	peopleCount: 0,
	unlocked: false,
	askPassword: true,
	listsKnown: false,
	version: 0,
})

/**
 * Server answer of GET/PUT /api/private as state; also understands the older shape
 * (lists always, no counts, no `locked`).
 *
 * @param {object} data
 */
export function normalizePrivate(data) {
	const folders = Array.isArray(data.folders) ? data.folders : []
	const people = Array.isArray(data.people) ? data.people : []
	const unlocked = data.unlocked ?? (data.locked === undefined ? false : !data.locked)
	const out = {
		folders,
		people,
		folderCount: Number(data.folderCount ?? folders.length) || 0,
		peopleCount: Number(data.peopleCount ?? people.length) || 0,
		listsKnown: !!unlocked || data.locked === undefined,
	}
	if (data.unlocked !== undefined || data.locked !== undefined) {
		out.unlocked = !!unlocked
	}
	if (data.files !== undefined) {
		out.files = Array.isArray(data.files) ? data.files.length : Number(data.files) || 0
	}
	if (data.askPassword !== undefined) {
		out.askPassword = !!data.askPassword
	}
	return out
}

export async function loadPrivate() {
	Object.assign(privateState, normalizePrivate((await axios.get(url(''))).data))
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
		return
	}
	// The lists only come while unlocked (and are emptied again when locked).
	await loadPrivate().catch(() => {})
}

/**
 * Replace both lists. Only while they are known, or the page would send lists it has
 * never seen (locked, the server would also ignore every removal).
 *
 * @param {Array} folders
 * @param {Array} people
 */
export async function setPrivateLists(folders, people) {
	if (!privateState.listsKnown) {
		throw new Error(t('Unlock to see and edit'))
	}
	await putLists(folders, people)
}

/**
 * Make one folder private; works locked too (the server then only adds).
 *
 * @param {string} path
 */
export async function addPrivateFolder(path) {
	if (privateState.listsKnown) {
		await putLists([...privateState.folders.filter((f) => f !== path), path], privateState.people)
	} else {
		await putLists([path], [])
	}
}

async function putLists(folders, people) {
	const wasUnlocked = privateState.unlocked
	Object.assign(privateState, normalizePrivate((await axios.put(url(''), { folders, people })).data))
	// Unlocked, private pictures stay visible, so nothing needs to reload (and lose its
	// scroll position). Locked, a new private folder or person must vanish.
	if (!wasUnlocked) {
		changed()
	}
}

/**
 * Mark files private or not. Views do not reload: unlocked, the files stay visible; the
 * caller removes them itself when locked.
 *
 * @param {Array} fileIds
 * @param {boolean} on
 */
export async function setPrivateFiles(fileIds, on) {
	let files
	try {
		files = (await axios.post(url('/files'), { fileIds, on })).data.files
	} catch (e) {
		if (e?.response?.status === 403 && e.response.data?.error === 'locked') {
			throw new Error(t('Unlock first'), { cause: e })
		}
		throw e
	}
	privateState.files = Array.isArray(files) ? files.length : Number(files) || 0
}

export const isPrivateFolder = (path) => privateState.folders.some((f) => path === f || path.startsWith(f + '/'))
