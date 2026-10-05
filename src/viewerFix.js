/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Workaround for https://github.com/nextcloud/viewer/issues/3015: opening a picture in a
 * folder with thousands of files freezes the browser for a minute (Firefox: "this page is
 * slowing down"). After sorting the folder, the viewer looks every file up in the whole
 * list again (O(n²), 36 million path parses for 6000 files).
 *
 * The viewer skips its own folder loading when it is handed a list. So when the Files app
 * opens a picture without one, this loads the folder the same way the viewer would
 * (same request, same file info format, same sorting) and hands it over.
 *
 * Fixed upstream in Nextcloud 33.0.10, 34.0.5 and 35.0.1; on those this stays off.
 */
import axios from '@nextcloud/axios'
import { File as NcFile, sortNodes } from '@nextcloud/files'
import { defaultRemoteURL, defaultRootPath, getClient, getDavNameSpaces, getDavProperties, getRemoteURL, getRootPath } from '@nextcloud/files/dav'
import { generateUrl } from '@nextcloud/router'

/** First release of each major with the fix; majors not listed and older are affected. */
const FIXED = { 33: [33, 0, 10], 34: [34, 0, 5], 35: [35, 0, 1] }

/**
 * @param {string} version e.g. "33.0.7.1"
 */
export function hasViewerBug(version) {
	const v = String(version || '').split('.').map(Number)
	if (!v[0]) {
		return false
	}
	const fixed = FIXED[v[0]]
	if (!fixed) {
		return v[0] < 33
	}
	for (let i = 0; i < 3; i++) {
		if ((v[i] ?? 0) !== fixed[i]) {
			return (v[i] ?? 0) < fixed[i]
		}
	}
	return false
}

/**
 * The viewer's own file info format: all WebDAV properties flattened, keys in camelCase,
 * numbers as numbers (viewer/src/utils/fileUtils.ts genFileInfo).
 *
 * @param {object} obj WebDAV stat result
 */
export function genFileInfo(obj) {
	const info = {}
	for (const [key, data] of Object.entries(obj)) {
		if (data && typeof data === 'object' && !Array.isArray(data)) {
			Object.assign(info, genFileInfo(data))
		} else if (data === 'false' || data === 'true') {
			info[camel(key)] = data === 'true'
		} else if (['filename', 'basename', 'owner-id'].includes(key)) {
			info[camel(key)] = String(data)
		} else {
			info[camel(key)] = data !== '' && data !== null && Number(data).toString() === String(data) ? Number(data) : data
		}
	}
	return info
}

/**
 * "has-preview" -> "hasPreview", like the camelcase package does for these keys.
 *
 * @param {string} key
 */
function camel(key) {
	return key.replace(/^[-_\s]+/, '').replace(/[-_\s]+(.)/g, (m, c) => c.toUpperCase())
}

let sorting = null
async function sortingConfig() {
	if (!sorting) {
		sorting = axios.get(generateUrl('/apps/files/api/v1/views'))
			.then(({ data }) => data?.data?.files ?? {})
			.catch(() => ({}))
			.then((c) => ({ mode: c.sorting_mode === 'mtime' ? 'mtime' : (c.sorting_mode || 'basename'), asc: c.sorting_direction !== 'desc' }))
	}
	return sorting
}

/**
 * Mimes the viewer pages through together with this one (its handler's group), or null.
 *
 * @param {object} viewer OCA.Viewer
 * @param {string} mime
 */
function groupMimes(viewer, mime) {
	const handlers = viewer.availableHandlers ?? []
	const group = handlers.find((h) => h.mimes?.includes(mime) || Object.keys(h.mimesAliases ?? {}).includes(mime))?.group
	if (!group) {
		return null
	}
	return new Set(handlers.filter((h) => h.group === group).flatMap((h) => [...(h.mimes ?? []), ...Object.keys(h.mimesAliases ?? {})]))
}

/**
 * The folder's files in the viewer's format and order, or null to let the viewer do it.
 *
 * @param {object} viewer OCA.Viewer
 * @param {string} path the opened file, relative to the user's files
 */
async function folderList(viewer, path) {
	const dir = path.slice(0, path.lastIndexOf('/'))
	const client = getClient(getRemoteURL() + defaultRootPath)
	const data = `<?xml version="1.0"?><d:propfind ${getDavNameSpaces()}><d:prop><oc:tags />${getDavProperties()}</d:prop></d:propfind>`
	const [response, config] = await Promise.all([client.getDirectoryContents(dir || '/', { data, details: true }), sortingConfig()])
	const files = response.data.map(genFileInfo)
	const opened = files.find((f) => f.filename === path)
	const mimes = opened && groupMimes(viewer, opened.mime)
	if (!mimes) {
		return null
	}
	const wanted = files.filter((f) => f.mime && mimes.has(f.mime))
	const nodes = wanted.map((f) => new NcFile({
		source: defaultRemoteURL + getRootPath() + f.filename,
		id: f.fileid,
		displayname: f.displayname,
		mime: f.mime,
		mtime: new Date(f.lastmod),
		owner: f.ownerId,
		root: getRootPath(),
	}))
	const byPath = new Map(wanted.map((f) => [f.filename, f]))
	return sortNodes(nodes, { sortingMode: config.mode, sortingOrder: config.asc ? 'asc' : 'desc' })
		.map((node) => byPath.get(node.path))
		.filter(Boolean)
}

/**
 * Wrap OCA.Viewer.open once the viewer is there.
 *
 * @param {string} version Nextcloud version
 */
export function installViewerFix(version = window.OC?.config?.version) {
	if (!hasViewerBug(version)) {
		return
	}
	const wrap = () => {
		const viewer = window.OCA?.Viewer
		if (!viewer || viewer.open.glimpseFix) {
			return !!viewer
		}
		const open = viewer.open.bind(viewer)
		const fixed = (options = {}) => {
			// Only the case the viewer gets wrong: a path, no list, not embedded in an element
			if (!options.path || options.list?.length || options.fileInfo || viewer.el) {
				return open(options)
			}
			folderList(viewer, options.path)
				.then((list) => open(list?.length ? { ...options, list } : options))
				.catch(() => open(options))
		}
		fixed.glimpseFix = true
		viewer.open = fixed
		return true
	}
	if (!wrap()) {
		// The viewer registers itself on DOMContentLoaded, maybe after us
		window.addEventListener('DOMContentLoaded', wrap)
	}
}
