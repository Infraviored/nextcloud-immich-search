/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

export const url = (path) => generateUrl('/apps/glimpse' + path)

/**
 * One path segment: ids can come from the address bar (?similar=, /people/<id>), so they
 * must not be able to change the request path.
 *
 * @param {string|number} value
 */
export const seg = (value) => encodeURIComponent(String(value))

/**
 * Error message from our JSON errors, or a generic one.
 *
 * @param e
 */
export function errorMessage(e) {
	return e?.response?.data?.error || e?.message || String(e)
}

export const thumbnailUrl = (id, size = 'thumbnail') => url(`/api/assets/${seg(id)}/thumbnail`) + (size === 'preview' ? '?size=preview' : '')
export const faceUrl = (id, asset = '') => url(`/api/people/${seg(id)}/face`) + (asset ? '?asset=' + seg(asset) : '')
export const fileUrl = (fileId) => generateUrl('/f/' + seg(fileId))
export const previewUrl = (fileId) => generateUrl('/core/preview?fileId={fileId}&x=256&y=256&a=1&mimeFallback=true', { fileId })

// seen: results already loaded; with a filter set (tag, only sensitive) the server stops once all are found
export async function search({ q = '', page = 1, person = '', type = '', literal = false, similar = '', tag = '', seen = 0 }, signal) {
	const { data } = await axios.get(url('/api/search'), { params: { q, page, person, type, literal: literal ? 1 : 0, similar, tag, seen }, signal })
	return data
}

export async function listPeople() {
	return (await axios.get(url('/api/people'))).data
}

export async function updatePerson(id, changes) {
	return (await axios.put(url(`/api/people/${seg(id)}`), changes)).data
}

export async function mergePeople(target, ids) {
	return (await axios.post(url(`/api/people/${seg(target)}/merge`), { ids })).data
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

/**
 * @param {boolean} visible every tag the user can see (to filter by), not only those they may assign
 */
export async function getTags(visible = false) {
	return (await axios.get(url('/api/tags'), { params: visible ? { visible: 1 } : {} })).data
}

export async function tagFiles(fileIds, tagIds, newTags) {
	return (await axios.post(url('/api/files/tag'), { fileIds, tagIds, newTags })).data
}

export async function timeline({ page = 1, before = '', after = '', type = '', person = '', seen = 0 }, signal) {
	return (await axios.get(url('/api/timeline'), { params: { page, before, after, type, person, seen }, signal })).data
}

/**
 * YYYY-MM-DD in the browser's time zone (toISOString would give UTC: shortly after
 * midnight, "today" would still be yesterday east of Greenwich).
 *
 * @param {Date} date
 */
export function localDate(date = new Date()) {
	const pad = (v) => String(v).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * The picture's day as Memories numbers it: days since 1970 of the local capture time.
 * Immich's localDateTime and Memories both store that time as if it were UTC.
 *
 * @param {string} date the item's date (Immich localDateTime)
 */
export const memoriesDay = (date) => Math.floor(Date.parse(date) / 86400000)

/**
 * Memories' timeline with this picture open; closing it leaves the timeline at that day.
 *
 * @param {{date: string, fileId: number}} item
 */
export const memoriesUrl = (item) => generateUrl('/apps/memories/') + `#v/${memoriesDay(item.date)}/${seg(item.fileId)}`

/**
 * Whether Memories' timeline has the picture: it shows only its own folders.
 *
 * @param {string} path the picture, relative to the user's files ("/Photos/a.jpg")
 * @param {string[]} roots Memories' timeline folders ("/Photos", "/" for all)
 */
export function inMemories(path, roots) {
	const file = '/' + String(path || '').replace(/^\/+/, '')
	return (roots || []).some((root) => {
		const dir = '/' + root.replace(/^\/+|\/+$/g, '')
		return dir === '/' || file.startsWith(dir + '/')
	})
}

/**
 * Where our timeline starts for "show in timeline": after the picture's day.
 * Immich filters by the UTC time, the day is local time; 14 hours more cover every time
 * zone, so the day is complete at the cost of a few pictures of the next one on top.
 *
 * @param {string} day YYYY-MM-DD
 * @return {string} ISO time, '' for an invalid day
 */
export function timelineStart(day) {
	const t = /^\d{4}-\d{2}-\d{2}$/.test(day || '') ? Date.parse(day + 'T00:00:00Z') : NaN
	return Number.isNaN(t) ? '' : new Date(t + (24 + 14) * 3600000).toISOString()
}

export async function memories() {
	return (await axios.get(url('/api/memories'), { params: { date: localDate() } })).data
}

export async function duplicates() {
	return (await axios.get(url('/api/duplicates'))).data
}

export async function dismissDuplicate(id) {
	return (await axios.delete(url('/api/duplicates/' + seg(id)))).data
}

export async function folder(path) {
	return (await axios.get(url('/api/folder'), { params: { path } })).data
}

export async function itemsFor(fileIds) {
	return (await axios.get(url('/api/items'), { params: { ids: fileIds.join(',') } })).data
}

export async function mapMarkers() {
	return (await axios.get(url('/api/map'))).data
}

// Leaflet fills in {z}/{x}/{y}; generateUrl would drop them as unknown parameters.
export const tileUrl = () => url('/api/tiles') + '/{z}/{x}/{y}'

export async function getFaces(assetId) {
	return (await axios.get(url(`/api/assets/${seg(assetId)}/faces`))).data
}

export async function reassignFace(faceId, assetId, { personId = '', newName = '' }) {
	return (await axios.put(url(`/api/faces/${seg(faceId)}`), { assetId, personId, newName })).data
}

/**
 * "Not this person" for many pictures: their faces of `id` go elsewhere.
 *
 * @param {string} id the person the faces are taken from
 * @param {string[]} assetIds
 * @param {{personId?: string, newName?: string, unnamed?: boolean, notAFace?: boolean}} target
 * @return {Promise<{moved: string[], faces: number, failed: string[], personId: ?string}>}
 */
export async function moveFaces(id, assetIds, { personId = '', newName = '', unnamed = false, notAFace = false }) {
	return (await axios.post(url(`/api/people/${seg(id)}/move-faces`), { assetIds, personId, newName, unnamed, notAFace })).data
}

export async function deleteFace(faceId, assetId) {
	return (await axios.delete(url(`/api/faces/${seg(faceId)}`), { params: { assetId } })).data
}

export async function assetForFile(fileId) {
	return (await axios.get(url(`/api/files/${seg(fileId)}/asset`))).data
}

export async function personSamples(id) {
	return (await axios.get(url(`/api/people/${seg(id)}/samples`))).data
}

/**
 * A public link to one of the user's files: the link it already has, or a new one.
 *
 * @param {string} path the file, relative to the user's files
 * @return {Promise<string>} the link
 */
export async function publicLink(path) {
	const shares = generateOcsUrl('apps/files_sharing/api/v1/shares')
	try {
		const { data } = await axios.get(shares, { params: { path, reshares: false, format: 'json' } })
		const existing = (data.ocs.data ?? []).find((s) => Number(s.share_type) === 3 && s.url)
		if (existing) {
			return existing.url
		}
		return (await axios.post(shares, { path, shareType: 3 }, { params: { format: 'json' } })).data.ocs.data.url
	} catch (e) {
		throw new Error(e?.response?.data?.ocs?.meta?.message || errorMessage(e), { cause: e })
	}
}

export async function getSensitive() {
	return (await axios.get(url('/api/sensitive'))).data
}

/**
 * @param {Object<number, boolean|null>} marks fileId => true (sensitive), false (not), null (back to what the terms say)
 */
export async function markSensitive(marks) {
	return (await axios.post(url('/api/sensitive/marks'), { marks })).data
}

/**
 * "Sensitive up to here": the term counts down to this picture.
 *
 * @param {string} q the search text
 * @param {string} assetId the picture's Immich id (for a video found through a still, the still's)
 */
export async function setSensitiveTerm(q, assetId) {
	return (await axios.post(url('/api/sensitive/terms'), { q, assetId })).data
}

export async function removeSensitiveTerm(q) {
	return (await axios.post(url('/api/sensitive/terms/remove'), { q })).data
}

/**
 * One page of sorting sensitive pictures.
 *
 * @param {{step: 'find'|'check'|'missed', q?: string, page?: number}} params page: where the previous answer said the next starts
 * @param {AbortSignal} [signal]
 */
export async function reviewSensitive({ step, q = '', page = 0 }, signal) {
	return (await axios.get(url('/api/sensitive/review'), { params: { step, q, page }, signal })).data
}

/**
 * @param {number} threshold from which score of the learned model a picture counts
 */
export async function setSensitiveThreshold(threshold) {
	return (await axios.post(url('/api/sensitive/threshold'), { threshold })).data
}

export async function rebuildSensitive() {
	return (await axios.post(url('/api/sensitive/rebuild'))).data
}

export async function checkSensitive() {
	return (await axios.get(url('/api/admin/sensitive-check'))).data
}
