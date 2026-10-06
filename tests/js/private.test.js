/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: () => {} }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
vi.mock('@nextcloud/password-confirmation', () => ({}))
vi.mock('@nextcloud/password-confirmation/style.css', () => ({}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../../src/api.js', () => ({ errorMessage: (e) => String(e) }))

const { normalizePrivate } = await import('../../src/private.js')

describe('normalizePrivate', () => {
	it('locked: counts only, lists unknown', () => {
		const s = normalizePrivate({ folders: [], people: [], files: 3, folderCount: 2, peopleCount: 1, unlocked: false, locked: true, askPassword: true })
		expect(s).toMatchObject({ folders: [], people: [], files: 3, folderCount: 2, peopleCount: 1, unlocked: false, listsKnown: false })
	})

	it('unlocked: lists and counts', () => {
		const s = normalizePrivate({ folders: ['/a'], people: ['p'], files: 0, folderCount: 1, peopleCount: 1, unlocked: true, locked: false, askPassword: false })
		expect(s).toMatchObject({ folders: ['/a'], people: ['p'], unlocked: true, askPassword: false, listsKnown: true })
	})

	it('older servers: lists always, counts derived', () => {
		const s = normalizePrivate({ folders: ['/a', '/b'], people: [], files: 4, unlocked: false, askPassword: true })
		expect(s).toMatchObject({ folderCount: 2, peopleCount: 0, files: 4, listsKnown: true })
	})

	it('leaves out what the answer does not say', () => {
		// PUT answers of older servers carried only the lists.
		const s = normalizePrivate({ folders: ['/a'], people: [] })
		expect(s).not.toHaveProperty('unlocked')
		expect(s).not.toHaveProperty('files')
	})
})
