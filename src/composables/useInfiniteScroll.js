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
 * @param sentinel
 * @param loadMore
 */
export function useInfiniteScroll(sentinel, loadMore) {
	let observer = null

	function check() {
		queueMicrotask(() => {
			const r = sentinel.value?.getBoundingClientRect()
			if (r && r.top < window.innerHeight + MARGIN) {
				loadMore()
			}
		})
	}

	onMounted(() => {
		observer = new IntersectionObserver((e) => {
			if (e[0].isIntersecting) {
				loadMore()
			}
		}, { rootMargin: MARGIN + 'px' })
		observer.observe(sentinel.value)
	})
	onBeforeUnmount(() => observer?.disconnect())

	return { check }
}
