/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Smoke test against a live Nextcloud: every view loads on desktop and phone without
 * script errors or failing app requests, and screenshots land in build/e2e/.
 *
 * All pictures are blurred before anything is drawn – this checks layout, not anyone's
 * photos, so the screenshots are safe to look at and to attach to an issue.
 *
 *   NC_URL=https://cloud.example.com NC_USER=alice NC_PASS=<app password> npm run e2e
 */
import { chromium } from 'playwright'
import { mkdirSync } from 'node:fs'

const base = (process.env.NC_URL || '').replace(/\/$/, '') + '/apps/immich_search'
const auth = 'Basic ' + Buffer.from(`${process.env.NC_USER}:${process.env.NC_PASS}`).toString('base64')
const pages = ['/', '/?q=dog', '/timeline', '/people', '/map', '/folders', '/duplicates', '/settings/status', '/settings/private']
const sizes = { desktop: { width: 1400, height: 900 }, phone: { width: 390, height: 844 } }
const BLUR = 'img, canvas, video, [style*="background-image"], .leaflet-tile { filter: blur(16px) !important; }'

if (!process.env.NC_URL || !process.env.NC_USER || !process.env.NC_PASS) {
	console.error('Set NC_URL, NC_USER and NC_PASS (an app password).')
	process.exit(2)
}

mkdirSync('build/e2e', { recursive: true })
const browser = await chromium.launch()
let failures = 0
for (const [device, viewport] of Object.entries(sizes)) {
	const ctx = await browser.newContext({ viewport, isMobile: device === 'phone', hasTouch: device === 'phone', extraHTTPHeaders: { Authorization: auth } })
	await ctx.addInitScript((css) => {
		document.addEventListener('DOMContentLoaded', () => {
			const s = document.createElement('style')
			s.textContent = css
			document.head.appendChild(s)
		})
	}, BLUR)
	for (const path of pages) {
		const page = await ctx.newPage()
		const problems = []
		page.on('pageerror', (e) => problems.push('script error: ' + e.message))
		page.on('response', (r) => {
			if (r.status() >= 400 && r.url().includes('/apps/immich_search/') && r.status() !== 404) {
				problems.push(`${r.status()} ${r.url()}`)
			}
		})
		await page.goto(base + path, { waitUntil: 'networkidle' })
		await page.waitForTimeout(1500)
		const file = `build/e2e/${device}${path.replace(/[/?=&]/g, '_') || '_'}.png`
		await page.screenshot({ path: file })
		console.log(problems.length ? '✗' : '✓', device, path, problems.join('; '))
		failures += problems.length
		await page.close()
	}
	await ctx.close()
}
await browser.close()
process.exit(failures ? 1 : 0)
