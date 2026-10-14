/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import { fileUrl } from './api.js'

/**
 * The file the Nextcloud viewer shows right now (for the action bar over it), or null, and
 * what the view that opened it wants to hear: pictures that left it, and when to reload.
 */
export const viewerState = reactive({ current: null, onRemoved: null, onRefresh: null })

/**
 * Viewer file info for one of our items. The viewer only needs path, name, type and id.
 *
 * @param item
 */
function fileInfo(item) {
	return {
		fileid: item.fileId,
		filename: item.path,
		basename: item.name,
		mime: item.mime,
		type: 'file',
		hasPreview: true,
		permissions: 'R',
	}
}

/**
 * Open a picture in the Nextcloud viewer, with `list` as what previous/next walks through.
 *
 * Without a list the viewer loads the picture's whole folder to page through it, which
 * freezes the browser for a camera folder with thousands of pictures.
 *
 * @param item the picture
 * @param list the pictures around it, in display order
 * @param {object} hooks from the view that opens it
 * @param {(fileIds: number[]) => void} [hooks.onRemoved] file ids that left the view (deleted or moved in the viewer)
 * @param {() => void} [hooks.onRefresh] something was undone, reload the view
 */
export function openInViewer(item, list = [item], { onRemoved = null, onRefresh = null } = {}) {
	if (!window.OCA?.Viewer || !item.path) {
		window.location.href = fileUrl(item.fileId)
		return
	}
	const mimes = window.OCA.Viewer.mimetypes ?? []
	const files = list.filter((i) => i.path && mimes.includes(i.mime))
	const byId = new Map(files.map((i) => [i.fileId, i]))
	const follow = (info) => {
		viewerState.current = byId.get(info.fileid) ?? null
	}
	viewerState.current = item
	viewerState.onRemoved = onRemoved
	viewerState.onRefresh = onRefresh
	window.OCA.Viewer.open({
		path: item.path,
		list: files.map(fileInfo),
		canLoop: false,
		onPrev: follow,
		onNext: follow,
		onClose: () => {
			viewerState.current = null
			viewerState.onRemoved = null
			viewerState.onRefresh = null
		},
	})
	if (item.at) {
		startAt(item.at)
	}
}

/** Close the viewer (onClose above clears the state). */
export function closeViewer() {
	window.OCA?.Viewer?.close?.()
	viewerState.current = null
}

/**
 * A video found by one of its stills starts at that moment: once the viewer's player has
 * the video, jump there.
 *
 * @param {number} seconds
 */
export function startAt(seconds) {
	const seek = (video) => {
		const go = () => {
			video.currentTime = seconds
		}
		if (video.readyState >= 1) {
			go()
		} else {
			video.addEventListener('loadedmetadata', go, { once: true })
		}
	}
	const found = () => document.querySelector('.viewer video, .modal-mask video')
	if (found()) {
		seek(found())
		return
	}
	const observer = new MutationObserver(() => {
		if (found()) {
			observer.disconnect()
			seek(found())
		}
	})
	observer.observe(document.body, { childList: true, subtree: true })
	window.setTimeout(() => observer.disconnect(), 15000)
}

/**
 * The Files app at the picture's folder, with the picture highlighted.
 *
 * @param item
 */
export function folderUrl(item) {
	const dir = item.path.slice(0, item.path.lastIndexOf('/')) || '/'
	return generateUrl('/apps/files/files/{id}?dir={dir}', { id: item.fileId, dir })
}

/**
 * The file itself, for downloading.
 *
 * @param item
 */
export function downloadUrl(item) {
	const path = item.path.split('/').map(encodeURIComponent).join('/')
	return `${generateRemoteUrl('dav')}/files/${encodeURIComponent(getCurrentUser().uid)}${path}`
}
