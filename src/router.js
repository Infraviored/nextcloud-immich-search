import { reactive } from 'vue'
import { generateUrl } from '@nextcloud/router'

/**
 * A few lines instead of vue-router: three views, deep-linkable and reload-safe
 *   /apps/immich_search/            search (?q=&type=&person=)
 *   /apps/immich_search/people      all people
 *   /apps/immich_search/people/<id> one person
 */
const base = generateUrl('/apps/immich_search')

export const route = reactive(parse())

function parse() {
    const rest = window.location.pathname.slice(base.length).replace(/^\/+|\/+$/g, '')
    const [section, id] = rest.split('/')
    const query = Object.fromEntries(new URLSearchParams(window.location.search))
    if (section === 'people' && id) return { name: 'person', id, query }
    if (section === 'people') return { name: 'people', id: null, query }
    return { name: 'search', id: null, query }
}

function apply() {
    Object.assign(route, parse())
}

export function href(path, query = {}) {
    const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v != null)).toString()
    return base + path + (qs ? '?' + qs : '')
}

export function navigate(path, query = {}) {
    window.history.pushState(null, '', href(path, query))
    apply()
}

/** Change only the query of the current view, without a history entry per keystroke. */
export function replaceQuery(query) {
    const path = window.location.pathname.slice(base.length) || '/'
    window.history.replaceState(null, '', href(path, query))
    route.query = { ...query }
}

window.addEventListener('popstate', apply)
