/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/immich_search' + path)

/**
 * Error message from our JSON errors, or a generic one.
 *
 * @param e
 */
export function errorMessage(e) {
	return e?.response?.data?.error || e?.message || String(e)
}

export const thumbnailUrl = (id, size = 'thumbnail') => url(`/api/assets/${id}/thumbnail`) + (size === 'preview' ? '?size=preview' : '')
export const faceUrl = (id) => url(`/api/people/${id}/face`)
export const fileUrl = (fileId) => generateUrl('/f/' + fileId)

/**
 *
 * @param root0
 * @param root0.q
 * @param root0.page
 * @param root0.person
 * @param root0.type
 * @param signal
 */
export async function search({ q = '', page = 1, person = '', type = '' }, signal) {
	const { data } = await axios.get(url('/api/search'), { params: { q, page, person, type }, signal })
	return data
}

/**
 *
 */
export async function listPeople() {
	return (await axios.get(url('/api/people'))).data
}

/**
 *
 * @param id
 * @param changes
 */
export async function updatePerson(id, changes) {
	return (await axios.put(url(`/api/people/${id}`), changes)).data
}

/**
 *
 * @param target
 * @param ids
 */
export async function mergePeople(target, ids) {
	return (await axios.post(url(`/api/people/${target}/merge`), { ids })).data
}

/**
 *
 * @param fileIds
 * @param target
 * @param mode
 */
export async function transfer(fileIds, target, mode) {
	return (await axios.post(url('/api/files/transfer'), { fileIds, target, mode })).data
}

/**
 *
 * @param settings
 */
export async function saveSettings(settings) {
	return (await axios.put(url('/api/settings'), settings)).data
}

/**
 *
 * @param settings
 */
export async function testSettings(settings) {
	return (await axios.post(url('/api/settings/test'), settings)).data
}

/**
 *
 */
export async function reindexPeople() {
	return (await axios.post(url('/api/settings/reindex'))).data
}
