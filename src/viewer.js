/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import { fileUrl } from './api.js'

/** The file the Nextcloud viewer shows right now (for the "show in folder" button), or null. */
export const viewerState = reactive({ current: null })

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
 */
export function openInViewer(item, list = [item]) {
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
	window.OCA.Viewer.open({
		path: item.path,
		list: files.map(fileInfo),
		canLoop: false,
		onPrev: follow,
		onNext: follow,
		onClose: () => {
			viewerState.current = null
		},
	})
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
