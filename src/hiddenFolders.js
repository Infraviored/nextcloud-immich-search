/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { showError, showUndo } from '@nextcloud/dialogs'
import { reactive } from 'vue'
import { hideFolder as apiHideFolder, setHiddenFolders as apiSetHiddenFolders, errorMessage } from './api.js'
import { t } from './l10n.js'

/**
 * Version goes up when hidden folders change so views reload on :key.
 */
export const hiddenFoldersState = reactive({ version: 0 })

/**
 * Hides the folder containing the file with fileId.
 * Shows an undo toast that restores the previous list via PUT.
 *
 * @param {number} fileId
 */
export async function hideFolder(fileId) {
	try {
		const res = await apiHideFolder(fileId)
		const folder = res.folder
		const previous = (res.folders || []).filter((f) => f !== folder)
		hiddenFoldersState.version++
		showUndo(t('“{folder}” hidden', { folder }), async () => {
			try {
				await apiSetHiddenFolders(previous)
				hiddenFoldersState.version++
			} catch (e) {
				showError(errorMessage(e))
			}
		})
		return res
	} catch (e) {
		showError(errorMessage(e))
		throw e
	}
}
