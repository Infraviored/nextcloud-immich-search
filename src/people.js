/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { reactive } from 'vue'
import { errorMessage, listPeople } from './api.js'

/** People as the current user may see them; shared by navigation, filters and views. */
export const peopleState = reactive({ people: [], updated: null, canManage: false, loading: true, error: '' })

export async function reloadPeople() {
	peopleState.loading = true
	try {
		const res = await listPeople()
		Object.assign(peopleState, { people: res.people, updated: res.updated, canManage: res.canManage, error: '' })
	} catch (e) {
		peopleState.error = errorMessage(e)
	} finally {
		peopleState.loading = false
	}
}

/**
 * Apply a change locally so lists update without a reload.
 *
 * @param id
 * @param changes
 */
export function patchPerson(id, changes) {
	const p = peopleState.people.find((x) => x.id === id)
	if (p) {
		Object.assign(p, changes)
	}
}
