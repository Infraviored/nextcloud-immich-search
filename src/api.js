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

export async function search({ q = '', page = 1, person = '', type = '', literal = false, similar = '' }, signal) {
	const { data } = await axios.get(url('/api/search'), { params: { q, page, person, type, literal: literal ? 1 : 0, similar }, signal })
	return data
}

export async function listPeople() {
	return (await axios.get(url('/api/people'))).data
}

export async function updatePerson(id, changes) {
	return (await axios.put(url(`/api/people/${id}`), changes)).data
}

export async function mergePeople(target, ids) {
	return (await axios.post(url(`/api/people/${target}/merge`), { ids })).data
}

export async function transfer(fileIds, target, mode) {
	return (await axios.post(url('/api/files/transfer'), { fileIds, target, mode })).data
}

export async function saveSettings(settings) {
	return (await axios.put(url('/api/settings'), settings)).data
}

export async function testSettings(settings) {
	return (await axios.post(url('/api/settings/test'), settings)).data
}

export async function reindexPeople() {
	return (await axios.post(url('/api/settings/reindex'))).data
}

export async function getStatus() {
	return (await axios.get(url('/api/status'))).data
}

export async function getPrefs() {
	return (await axios.get(url('/api/prefs'))).data
}

export async function savePrefs(prefs) {
	return (await axios.put(url('/api/prefs'), { prefs })).data
}

export async function getAdminSettings() {
	return (await axios.get(url('/api/admin/settings'))).data
}

export async function saveAdminSettings(settings) {
	return (await axios.put(url('/api/admin/settings'), { settings })).data
}

export async function getLibraries() {
	return (await axios.get(url('/api/admin/libraries'))).data
}

export async function createLibrary(uid) {
	return (await axios.post(url('/api/admin/libraries'), { uid })).data
}

export async function getFolderSuggestions() {
	return (await axios.get(url('/api/admin/folder-suggestions'))).data
}

export async function queueMissing() {
	return (await axios.post(url('/api/admin/queue-missing'))).data
}

export async function scanLibraries() {
	return (await axios.post(url('/api/admin/scan'))).data
}

export async function pruneSmall() {
	return (await axios.post(url('/api/admin/prune'))).data
}

export async function undoMove(moves) {
	return (await axios.post(url('/api/files/undo'), { moves })).data
}

export async function favorite(fileIds, on) {
	return (await axios.post(url('/api/files/favorite'), { fileIds, on })).data
}

export async function deleteFiles(fileIds) {
	return (await axios.post(url('/api/files/delete'), { fileIds })).data
}

export async function getTags() {
	return (await axios.get(url('/api/tags'))).data
}

export async function tagFiles(fileIds, tagIds, newTags) {
	return (await axios.post(url('/api/files/tag'), { fileIds, tagIds, newTags })).data
}

export const zipUrl = (fileIds) => url('/api/files/zip') + '?ids=' + fileIds.join(',')
