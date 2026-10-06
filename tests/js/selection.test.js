/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { afterTransfer, pruneSelection, toggleSelection } from '../../src/selection.js'

const items = [1, 2, 3, 4, 5].map((fileId) => ({ fileId }))

describe('toggleSelection', () => {
	it('toggles one item', () => {
		const a = toggleSelection(new Set(), items, 2, null, false)
		expect([...a]).toEqual([2])
		expect([...toggleSelection(a, items, 2, 2, false)]).toEqual([])
	})

	it('adds a shift range in either direction, keeping what was selected', () => {
		expect([...toggleSelection(new Set([1]), items, 4, 2, true)].sort()).toEqual([1, 2, 3, 4])
		expect([...toggleSelection(new Set(), items, 1, 3, true)].sort()).toEqual([1, 2, 3])
	})

	it('resolves the range by file id over the whole view, across groups', () => {
		// The anchor sits in another grid (another month); the order is that of all items.
		const view = [10, 11, 20, 21, 22].map((fileId) => ({ fileId }))
		expect([...toggleSelection(new Set(), view, 21, 11, true)]).toEqual([11, 20, 21])
	})

	it('treats shift without an anchor, or with one that is gone, as a plain toggle', () => {
		expect([...toggleSelection(new Set(), items, 5, null, true)]).toEqual([5])
		expect([...toggleSelection(new Set(), items, 5, 99, true)]).toEqual([5])
	})

	it('does not modify the input', () => {
		const sel = new Set([1])
		toggleSelection(sel, items, 3, null, false)
		expect([...sel]).toEqual([1])
	})
})

describe('pruneSelection', () => {
	it('drops ids that are no longer shown and keeps the visible ones', () => {
		expect([...pruneSelection(new Set([2, 4, 99]), items)]).toEqual([2, 4])
		expect(pruneSelection(new Set([1, 2]), []).size).toBe(0)
	})

	it('returns the same Set when nothing changed', () => {
		const sel = new Set([1, 3])
		expect(pruneSelection(sel, items)).toBe(sel)
	})
})

describe('afterTransfer', () => {
	it('drops moved items and keeps failed ones selected', () => {
		const r = afterTransfer(items, new Set([1, 2, 3]), [2], true)
		expect(r.items.map((i) => i.fileId)).toEqual([2, 4, 5])
		expect([...r.selected]).toEqual([2])
	})

	it('keeps all items after a copy', () => {
		expect(afterTransfer(items, new Set([1]), [], false).items).toHaveLength(5)
	})
})
