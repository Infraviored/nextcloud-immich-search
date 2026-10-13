/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
// Only the pure helpers are tested; the Nextcloud libraries need a browser.
vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/files', () => ({}))
vi.mock('@nextcloud/files/dav', () => ({}))
vi.mock('@nextcloud/router', () => ({}))

import { genFileInfo, hasViewerBug } from '../../src/viewerFix.js'

describe('hasViewerBug', () => {
	it('is on for affected releases', () => {
		expect(hasViewerBug('33.0.7.1')).toBe(true)
		expect(hasViewerBug('33.0.9.2')).toBe(true)
		expect(hasViewerBug('34.0.4')).toBe(true)
		expect(hasViewerBug('32.0.15')).toBe(true)
	})
	it('is off where the viewer is fixed', () => {
		expect(hasViewerBug('33.0.10.0')).toBe(false)
		expect(hasViewerBug('34.0.5')).toBe(false)
		expect(hasViewerBug('35.0.1.1')).toBe(false)
		expect(hasViewerBug('36.0.0')).toBe(false)
		expect(hasViewerBug('')).toBe(false)
	})
})

describe('genFileInfo', () => {
	it('flattens props and camel-cases keys like the viewer', () => {
		const info = genFileInfo({
			filename: '/Photos/1.jpg',
			basename: '1.jpg',
			lastmod: 'Mon, 01 Jan 2024 00:00:00 GMT',
			props: { 'has-preview': 'true', fileid: '42', 'owner-id': '123', favorite: '0', getetag: '"abc"' },
		})
		expect(info).toMatchObject({ filename: '/Photos/1.jpg', hasPreview: true, fileid: 42, ownerId: '123', favorite: 0, getetag: '"abc"' })
	})
})
