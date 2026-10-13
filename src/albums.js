/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Albums of the Nextcloud Photos app, through its WebDAV interface (the documented way;
// the app has no PHP API for other apps).
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateRemoteUrl } from '@nextcloud/router'

const dav = () => generateRemoteUrl('dav')
const uid = () => encodeURIComponent(getCurrentUser().uid)
const albumUrl = (name) => `${dav()}/photos/${uid()}/albums/${encodeURIComponent(name)}`
const encodePath = (path) => path.split('/').map(encodeURIComponent).join('/')

export async function listAlbums() {
	const { data } = await axios({
		method: 'PROPFIND',
		url: `${dav()}/photos/${uid()}/albums/`,
		headers: { Depth: '1', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
		responseType: 'text',
	})
	const doc = new DOMParser().parseFromString(data, 'application/xml')
	const names = [...doc.getElementsByTagNameNS('DAV:', 'href')]
		.map((h) => decodeURIComponent(h.textContent.replace(/\/$/, '').split('/').pop()))
	return names.slice(1).sort((a, b) => a.localeCompare(b)) // first entry is the collection itself
}

export async function createAlbum(name) {
	await axios({ method: 'MKCOL', url: albumUrl(name) })
}

/**
 * @param name
 * @param items
 * @return {Promise<number>} how many were added
 */
export async function addToAlbum(name, items) {
	let added = 0
	for (const item of items) {
		try {
			await axios({
				method: 'COPY',
				url: `${dav()}/files/${uid()}${encodePath(item.path)}`,
				headers: { Destination: `${albumUrl(name)}/${encodeURIComponent(item.name)}`, Overwrite: 'F' },
			})
			added++
		} catch {
			// already in the album, or not allowed: skip
		}
	}
	return added
}

const NC = 'http://nextcloud.org/ns'

/**
 * Who the album is shared with (Photos calls them collaborators).
 *
 * @param name
 * @return {Promise<Array<{id: string, label: string, type: number}>>}
 */
export async function albumCollaborators(name) {
	const { data } = await axios({
		method: 'PROPFIND',
		url: albumUrl(name),
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		data: `<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="${NC}"><d:prop><nc:collaborators/></d:prop></d:propfind>`,
		responseType: 'text',
	})
	const doc = new DOMParser().parseFromString(data, 'application/xml')
	const text = (el, tag) => el.getElementsByTagName(tag)[0]?.textContent ?? ''
	return [...doc.getElementsByTagNameNS(NC, 'collaborator')]
		.map((c) => ({ id: text(c, 'id'), label: text(c, 'label'), type: Number(text(c, 'type')) }))
}

/**
 * Share the album with more users or groups, keeping those it is shared with already
 * (Photos replaces the whole list). They see it under "Shared albums" in Photos.
 *
 * @param name
 * @param recipients [{id, type}] type 0 = user, 1 = group
 * @return {Promise<number>} how many were not in the list before
 */
export async function shareAlbum(name, recipients) {
	const current = await albumCollaborators(name)
	const key = (c) => c.type + ':' + c.id
	const known = new Set(current.map(key))
	const added = recipients.filter((r) => !known.has(key(r)))
	if (!added.length) {
		return 0
	}
	const list = [...current, ...added].map(({ id, type }) => ({ id, type }))
	const json = JSON.stringify(list).replace(/&/g, '&amp;').replace(/</g, '&lt;')
	await axios({
		method: 'PROPPATCH',
		url: albumUrl(name),
		headers: { 'Content-Type': 'application/xml' },
		data: `<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:nc="${NC}"><d:set><d:prop><nc:collaborators>${json}</nc:collaborators></d:prop></d:set></d:propertyupdate>`,
	})
	return added.length
}

/**
 * Users and groups to share with, as Nextcloud's share dialog finds them.
 *
 * @param term
 * @param signal
 * @return {Promise<Array<{id: string, type: number, label: string}>>}
 */
export async function findRecipients(term, signal) {
	const { data } = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
		params: { search: term, itemType: 'share-recipients', shareType: [0, 1], perPage: 20, lookup: false, format: 'json' },
		signal,
	})
	const d = data.ocs.data
	const me = getCurrentUser().uid
	const all = [...(d.exact?.users ?? []), ...(d.exact?.groups ?? []), ...(d.users ?? []), ...(d.groups ?? [])]
	return all
		.map((r) => ({ id: r.value.shareWith, type: r.value.shareType, label: r.label }))
		.filter((r) => !(r.type === 0 && r.id === me))
}
