/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Selection rules of the grid, kept free of Vue so they can be tested.
 *
 * @param {Set} selected   current selection (file ids); not modified
 * @param {Array} items    items in grid order, each with a fileId
 * @param {number} index   clicked item
 * @param {number|null} anchor  last clicked index, for shift ranges
 * @param {boolean} range  shift held
 * @return {Set} the new selection
 */
export function toggleSelection(selected, items, index, anchor, range) {
	const next = new Set(selected)
	if (range && anchor !== null && anchor !== undefined) {
		const [from, to] = [Math.min(anchor, index), Math.max(anchor, index)]
		items.slice(from, to + 1).forEach((i) => next.add(i.fileId))
		return next
	}
	const id = items[index].fileId
	if (next.has(id)) {
		next.delete(id)
	} else {
		next.add(id)
	}
	return next
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
