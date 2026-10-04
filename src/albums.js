/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Albums of the Nextcloud Photos app, through its WebDAV interface (the documented way;
// the app has no PHP API for other apps).
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'

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
