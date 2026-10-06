/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))
vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: () => {} }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { faceUrl, localDate, seg, thumbnailUrl } = await import('../../src/api.js')

describe('localDate', () => {
	it('uses the local calendar day, zero-padded', () => {
		// Local midnight: in any time zone east of UTC, toISOString gives the day before.
		expect(localDate(new Date(2026, 0, 5, 0, 30))).toBe('2026-01-05')
		expect(localDate(new Date(2026, 11, 31, 23, 59))).toBe('2026-12-31')
	})
})

describe('path segments', () => {
	it('cannot leave their segment', () => {
		expect(seg('../../settings')).toBe('..%2F..%2Fsettings')
		expect(thumbnailUrl('a/b?c')).toBe('/apps/glimpse/api/assets/a%2Fb%3Fc/thumbnail')
		expect(faceUrl('x/y', 'a&b')).toBe('/apps/glimpse/api/people/x%2Fy/face?asset=a%26b')
	})
})
