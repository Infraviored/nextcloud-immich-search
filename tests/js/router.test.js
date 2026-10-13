/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

// router.js reads the address and listens for popstate when it is loaded.
vi.hoisted(() => {
	globalThis.window = { location: { pathname: '/apps/glimpse/', search: '' }, addEventListener: () => {} }
})
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))

const { appPath } = await import('../../src/router.js')

describe('appPath', () => {
	it('strips the base generateUrl returns', () => {
		expect(appPath('/index.php/apps/glimpse/people/12', '/index.php/apps/glimpse')).toBe('/people/12')
		expect(appPath('/apps/glimpse/map', '/apps/glimpse')).toBe('/map')
	})

	it('accepts the address with and without index.php, whatever the config says', () => {
		expect(appPath('/index.php/apps/glimpse/timeline', '/apps/glimpse')).toBe('/timeline')
		expect(appPath('/apps/glimpse/timeline', '/index.php/apps/glimpse')).toBe('/timeline')
	})

	it('handles a web root', () => {
		expect(appPath('/nc/index.php/apps/glimpse/settings/private', '/nc/apps/glimpse')).toBe('/settings/private')
		expect(appPath('/nc/apps/glimpse', '/nc/index.php/apps/glimpse')).toBe('/')
	})

	it('gives "/" for the app itself and for foreign paths', () => {
		expect(appPath('/apps/glimpse/', '/apps/glimpse')).toBe('/')
		expect(appPath('/apps/glimpsefoo/x', '/apps/glimpse')).toBe('/')
		expect(appPath('/apps/files/', '/apps/glimpse')).toBe('/')
	})
})
