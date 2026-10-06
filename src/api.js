/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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

export async function search({ q = '', page = 1, person = '', type = '', literal = false, similar = '' }, signal) {
	const { data } = await axios.get(url('/api/search'), { params: { q, page, person, type, literal: literal ? 1 : 0, similar }, signal })
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

export async function getTags() {
	return (await axios.get(url('/api/tags'))).data
}

export async function tagFiles(fileIds, tagIds, newTags) {
	return (await axios.post(url('/api/files/tag'), { fileIds, tagIds, newTags })).data
}

export async function timeline({ page = 1, before = '', type = '' }, signal) {
	return (await axios.get(url('/api/timeline'), { params: { page, before, type }, signal })).data
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

export async function deleteFace(faceId, assetId) {
	return (await axios.delete(url(`/api/faces/${seg(faceId)}`), { params: { assetId } })).data
}

export async function assetForFile(fileId) {
	return (await axios.get(url(`/api/files/${seg(fileId)}/asset`))).data
}

export async function personSamples(id) {
	return (await axios.get(url(`/api/people/${seg(id)}/samples`))).data
}
