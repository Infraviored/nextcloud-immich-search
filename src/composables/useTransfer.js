/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getFilePickerBuilder, showError, showSuccess, showUndo, showWarning } from '@nextcloud/dialogs'
import { ref } from 'vue'
import { errorMessage, transfer, undoMove } from '../api.js'
import { n, t } from '../l10n.js'

const RECENT_KEY = 'glimpse.recentTargets'
const RECENT_MAX = 5

/** Folders moved or copied to lately (this device), newest first. */
export const recentTargets = ref(loadRecent())

function loadRecent() {
	try {
		return JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]')
	} catch {
		return []
	}
}

function remember(target) {
	recentTargets.value = [target, ...recentTargets.value.filter((x) => x !== target)].slice(0, RECENT_MAX)
	try {
		window.localStorage.setItem(RECENT_KEY, JSON.stringify(recentTargets.value))
	} catch {
		// storage blocked: no recent list
	}
}

/**
 * Folder picker + move/copy of selected files, with the result as a toast (undo for moves).
 *
 * @param onUndone
 */
export function useTransfer(onUndone = () => {}) {
	const busy = ref(false)

	/**
	 * @param {number[]} fileIds selection
	 * @param {'move'|'copy'} mode what to do
	 * @param {string|null} target folder, or null to ask with the picker
	 * @return {Promise<{target: string, done: number, failedIds: number[]}|null>} null when cancelled
	 */
	async function run(fileIds, mode, target = null) {
		target ??= await pickFolder(mode)
		if (target === null) {
			return null
		}
		busy.value = true
		try {
			const res = await transfer(fileIds, target, mode)
			remember(target)
			const where = target === '/' ? t('Files') : target
			const msg = mode === 'move'
				? n('Moved {count} file to {folder}', 'Moved {count} files to {folder}', res.done, { count: res.done, folder: where })
				: n('Copied {count} file to {folder}', 'Copied {count} files to {folder}', res.done, { count: res.done, folder: where })
			if (res.failed.length) {
				showWarning(msg + ' · ' + n('{count} not allowed', '{count} not allowed', res.failed.length, { count: res.failed.length }))
			} else if (mode === 'move' && res.moved?.length) {
				showUndo(msg, () => undo(res.moved))
			} else {
				showSuccess(msg)
			}
			return { target, done: res.done, failedIds: res.failed.map((f) => f.fileId) }
		} catch (e) {
			showError(errorMessage(e))
			return null
		} finally {
			busy.value = false
		}
	}

	async function undo(moves) {
		try {
			const res = await undoMove(moves)
			showSuccess(n('Moved {count} file back', 'Moved {count} files back', res.done, { count: res.done }))
			onUndone()
		} catch (e) {
			showError(errorMessage(e))
		}
	}

	return { busy, run }
}

function pickFolder(mode) {
	let chosen = null
	const label = mode === 'move' ? t('Move here') : t('Copy here')
	const picker = getFilePickerBuilder(mode === 'move' ? t('Move to …') : t('Copy to …'))
		.setMultiSelect(false)
		.allowDirectories(true)
		.addMimeTypeFilter('httpd/unix-directory')
		.setButtonFactory((nodes, currentPath) => [{
			label: nodes[0] ? t('{label} ({folder})', { label, folder: nodes[0].basename }) : label,
			variant: 'primary',
			callback: (picked) => {
				chosen = picked[0]?.path ?? currentPath
			},
		}])
		.build()
	return picker.pick().then(() => chosen, () => null)
}
