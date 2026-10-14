/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'

/**
 * A few lines instead of vue-router: three views, deep-linkable and reload-safe
 *   /apps/glimpse/            search (?q=&type=&person=)
 *   /apps/glimpse/people      all people
 *   /apps/glimpse/people/<id> one person
 *   /apps/glimpse/settings[/<section>]
 *   /apps/glimpse/timeline | map | folders | duplicates
 */
const base = generateUrl('/apps/glimpse')

/**
 * The part of the address after the app, e.g. "/people/12". generateUrl adds /index.php
 * or not depending on the server config ("pretty URLs"), but both forms reach the app,
 * so both are accepted, behind whatever web root Nextcloud lives in.
 *
 * @param {string} pathname  window.location.pathname
 * @param {string} appBase   generateUrl('/apps/glimpse')
 * @return {string} "/" when the address is the app itself (or not the app at all)
 */
export function appPath(pathname, appBase = base) {
	const webroot = appBase.replace(/(\/index\.php)?\/apps\/glimpse\/?$/, '')
	const escaped = webroot.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = new RegExp('^' + escaped + '(?:/index\\.php)?/apps/glimpse(?=/|$)').exec(pathname)
	return (match ? pathname.slice(match[0].length) : '') || '/'
}

export const route = reactive(parse())

function parse() {
	const rest = appPath(window.location.pathname).replace(/^\/+|\/+$/g, '')
	const [section, id] = rest.split('/')
	const query = Object.fromEntries(new URLSearchParams(window.location.search))
	if (section === 'people' && id) {
		return { name: 'person', id, query }
	}
	if (['timeline', 'map', 'folders', 'duplicates', 'sensitive'].includes(section)) {
		return { name: section, id: null, query }
	}
	if (section === 'settings') {
		return { name: 'settings', id: id || null, query }
	}
	if (section === 'people') {
		return { name: 'people', id: null, query }
	}
	return { name: 'search', id: null, query }
}

function apply() {
	Object.assign(route, parse())
}

export function href(path, query = {}) {
	const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined)).toString()
	return base + path + (qs ? '?' + qs : '')
}

export function navigate(path, query = {}) {
	window.history.pushState(null, '', href(path, query))
	apply()
}

/**
 * Change only the query of the current view, without a history entry per keystroke.
 *
 * @param query
 */
export function replaceQuery(query) {
	const path = appPath(window.location.pathname)
	window.history.replaceState(null, '', href(path, query))
	route.query = { ...query }
}

window.addEventListener('popstate', apply)
