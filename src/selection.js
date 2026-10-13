/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'

/** Shared reactive flag indicating whether a selection is currently active. */
export const selectionActive = ref(false)

/**
 * Selection rules of the grid, kept free of Vue so they can be tested.
 *
 * The anchor of a shift range is a file id, not an index: the view may consist of several
 * grids (months in the timeline) and items come and go, so indexes would point elsewhere.
 *
 * @param {Set} selected   current selection (file ids); not modified
 * @param {Array} items    all items of the view in display order, each with a fileId
 * @param {number} fileId  clicked item
 * @param {number|null} anchor  file id clicked before, for shift ranges
 * @param {boolean} range  shift held
 * @return {Set} the new selection
 */
export function toggleSelection(selected, items, fileId, anchor, range) {
	const next = new Set(selected)
	if (range && anchor !== null && anchor !== undefined) {
		const a = items.findIndex((i) => i.fileId === anchor)
		const b = items.findIndex((i) => i.fileId === fileId)
		if (a >= 0 && b >= 0) {
			items.slice(Math.min(a, b), Math.max(a, b) + 1).forEach((i) => next.add(i.fileId))
			return next
		}
	}
	if (next.has(fileId)) {
		next.delete(fileId)
	} else {
		next.add(fileId)
	}
	return next
}

/**
 * Only what is still shown stays selected: after a new search, a map pan or a folder change
 * an action must not hit pictures the user cannot see any more.
 *
 * @param {Set} selected
 * @param {Array} items  items now shown, each with a fileId
 * @return {Set} the same Set if nothing was dropped, else a smaller one
 */
export function pruneSelection(selected, items) {
	if (!selected.size) {
		return selected
	}
	const shown = new Set(items.map((i) => i.fileId))
	const kept = [...selected].filter((id) => shown.has(id))
	return kept.length === selected.size ? selected : new Set(kept)
}

/**
 * After a move: drop moved items from the grid, keep the ones that failed selected.
 *
 * @param items
 * @param selected
 * @param failedIds
 * @param moved
 */
export function afterTransfer(items, selected, failedIds, moved) {
	const failed = new Set(failedIds)
	return {
		items: moved ? items.filter((i) => !selected.has(i.fileId) || failed.has(i.fileId)) : items,
		selected: new Set([...failed]),
	}
}
