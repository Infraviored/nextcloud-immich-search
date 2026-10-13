/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { onBeforeUnmount, onMounted } from 'vue'

const MARGIN = 800

/**
 * Calls `loadMore` when the sentinel element comes near the viewport.
 * `check()` asks again after a load: the permission filter can drop whole pages, so the
 * end may still be on screen without any scrolling.
 *
 * With `{ above: true }` the sentinel sits at the top of a list that grows upwards.
 *
 * @param sentinel
 * @param loadMore
 * @param options
 * @param options.above
 */
export function useInfiniteScroll(sentinel, loadMore, { above = false } = {}) {
	let observer = null

	function check() {
		queueMicrotask(() => {
			const r = sentinel.value?.getBoundingClientRect()
			if (r && (above ? r.bottom > -MARGIN : r.top < window.innerHeight + MARGIN)) {
				loadMore()
			}
		})
	}

	onMounted(() => {
		// The margin only counts on the element that scrolls; on the window it would be cut
		// off by the app's own scrolling content
		const root = scrollParent(sentinel.value)
		observer = new IntersectionObserver((e) => {
			if (e[0].isIntersecting) {
				loadMore()
			}
		}, { root: root === document.scrollingElement ? null : root, rootMargin: MARGIN + 'px' })
		observer.observe(sentinel.value)
	})
	onBeforeUnmount(() => observer?.disconnect())

	return { check }
}

/**
 * The element that scrolls around `el`: the app content in current Nextcloud versions,
 * the window in older ones.
 *
 * @param el
 */
export function scrollParent(el) {
	for (let e = el?.parentElement; e; e = e.parentElement) {
		const { overflowY } = getComputedStyle(e)
		if (overflowY === 'auto' || overflowY === 'scroll') {
			return e
		}
	}
	return document.scrollingElement
}
