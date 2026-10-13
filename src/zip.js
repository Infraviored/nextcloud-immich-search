/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Own module, not in api.js: the Files integration loads api.js on every Files page and
// must not pull in the dialogs library with it.
import { getRequestToken } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { url } from './api.js'
import { t } from './l10n.js'

/** The server's limit for one ZIP. */
export const ZIP_MAX_FILES = 2000

/**
 * Download the files as one ZIP. A POST, because thousands of ids do not fit into a URL.
 * A plain form, not axios: only a real navigation lets the browser stream the download.
 * It is sent into a hidden frame, so this page stays as it is; if the server answers with
 * an error instead of the ZIP, the frame shows it and we read it from there.
 *
 * @param {Array} fileIds
 */
export function downloadZip(fileIds) {
	if (fileIds.length > ZIP_MAX_FILES) {
		showError(t('At most {max} files can be downloaded as one ZIP.', { max: ZIP_MAX_FILES }))
		return
	}
	// The Android app's WebView cannot save the answer of a form POST: it sends the form itself
	if (window.GlimpseApp?.postMessage) {
		window.GlimpseApp.postMessage(JSON.stringify({
			type: 'postDownload',
			url: new URL(url('/api/files/zip'), window.location.origin).href,
			fields: { ids: fileIds.join(','), requesttoken: getRequestToken() ?? '' },
		}))
		return
	}
	let frame = document.getElementById('glimpse-zip-frame')
	if (!frame) {
		frame = document.createElement('iframe')
		frame.id = frame.name = 'glimpse-zip-frame'
		frame.hidden = true
		// A download does not load the frame; only an error page (JSON) does.
		frame.addEventListener('load', () => {
			let text
			try {
				text = frame.contentDocument?.body?.textContent ?? ''
			} catch {
				return
			}
			if (text.trim()) {
				let message = text
				try {
					message = JSON.parse(text).error || text
				} catch {
					// not JSON: show the text
				}
				showError(t('Download failed: {error}', { error: message.slice(0, 300) }))
			}
		})
		document.body.appendChild(frame)
	}
	const form = document.createElement('form')
	form.method = 'post'
	form.action = url('/api/files/zip')
	form.target = frame.name
	form.hidden = true
	for (const [name, value] of [['ids', fileIds.join(',')], ['requesttoken', getRequestToken() ?? '']]) {
		const input = document.createElement('input')
		input.type = 'hidden'
		input.name = name
		input.value = value
		form.appendChild(input)
	}
	document.body.appendChild(form)
	form.submit()
	form.remove()
}
