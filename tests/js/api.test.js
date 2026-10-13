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

const { faceUrl, inMemories, localDate, memoriesDay, memoriesUrl, seg, thumbnailUrl, timelineStart } = await import('../../src/api.js')

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

describe('timeline links', () => {
	it('numbers days like Memories does', () => {
		// Checked against oc_memories.dayid: 2011-01-29 is day 15003
		expect(memoriesDay('2011-01-29T13:12:27.000Z')).toBe(15003)
		expect(memoriesDay('2011-01-29T00:00:00.000Z')).toBe(15003)
		expect(memoriesDay('2011-01-29T23:59:59.000Z')).toBe(15003)
		expect(memoriesUrl({ date: '2011-01-29T13:12:27.000Z', fileId: 31621 })).toBe('/apps/memories/#v/15003/31621')
	})

	it('links to Memories only for pictures in its folders', () => {
		expect(inMemories('/Photos/2025/a.jpg', ['/Photos'])).toBe(true)
		expect(inMemories('/Photos/a.jpg', ['Photos/'])).toBe(true)
		expect(inMemories('/Photos2/a.jpg', ['/Photos'])).toBe(false)
		expect(inMemories('/Documents/a.jpg', ['/Photos', '/Camera'])).toBe(false)
		expect(inMemories('/Camera/a.jpg', ['/Photos', '/Camera'])).toBe(true)
		expect(inMemories('/Documents/a.jpg', ['/'])).toBe(true)
		expect(inMemories('/Photos/a.jpg', [])).toBe(false)
	})

	it('starts our timeline after the whole day, in any time zone', () => {
		expect(timelineStart('2025-03-07')).toBe('2025-03-08T14:00:00.000Z')
		expect(timelineStart('2025-3-7')).toBe('')
		expect(timelineStart('<script>')).toBe('')
		expect(timelineStart(undefined)).toBe('')
	})
})
