/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockShowError = vi.fn()
const mockShowUndo = vi.fn()
const mockHideFolder = vi.fn()
const mockSetHiddenFolders = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => mockShowError(...args),
	showUndo: (...args) => mockShowUndo(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars) => (vars?.folder ? s.replace('{folder}', vars.folder) : s),
	translatePlural: (app, s) => s,
}))
vi.mock('../../src/api.js', () => ({
	errorMessage: (e) => (e && e.message ? e.message : String(e)),
	hideFolder: (...args) => mockHideFolder(...args),
	setHiddenFolders: (...args) => mockSetHiddenFolders(...args),
}))

const { hiddenFoldersState, hideFolder } = await import('../../src/hiddenFolders.js')

describe('hiddenFolders', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		hiddenFoldersState.version = 0
	})

	it('hides folder, increments version, and registers undo', async () => {
		mockHideFolder.mockResolvedValue({
			folder: '/Vacation',
			folders: ['/Old', '/Vacation'],
		})
		mockSetHiddenFolders.mockResolvedValue({
			folders: ['/Old'],
		})

		await hideFolder(123)

		expect(mockHideFolder).toHaveBeenCalledWith(123)
		expect(hiddenFoldersState.version).toBe(1)
		expect(mockShowUndo).toHaveBeenCalledWith('“/Vacation” hidden', expect.any(Function))

		// Execute the undo callback
		const undoCb = mockShowUndo.mock.calls[0][1]
		await undoCb()

		expect(mockSetHiddenFolders).toHaveBeenCalledWith(['/Old'])
		expect(hiddenFoldersState.version).toBe(2)
	})

	it('shows error dialog and throws on failure', async () => {
		mockHideFolder.mockRejectedValue(new Error('Network error'))

		await expect(hideFolder(456)).rejects.toThrow('Network error')
		expect(mockShowError).toHaveBeenCalledWith('Network error')
		expect(hiddenFoldersState.version).toBe(0)
	})
})
