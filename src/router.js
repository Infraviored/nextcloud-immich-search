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

export const route = reactive(parse())

function parse() {
	const rest = window.location.pathname.slice(base.length).replace(/^\/+|\/+$/g, '')
	const [section, id] = rest.split('/')
	const query = Object.fromEntries(new URLSearchParams(window.location.search))
	if (section === 'people' && id) {
		return { name: 'person', id, query }
	}
	if (['timeline', 'map', 'folders', 'duplicates'].includes(section)) {
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
	const path = window.location.pathname.slice(base.length) || '/'
	window.history.replaceState(null, '', href(path, query))
	route.query = { ...query }
}

window.addEventListener('popstate', apply)
