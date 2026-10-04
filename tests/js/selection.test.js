/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { afterTransfer, toggleSelection } from '../../src/selection.js'

const items = [1, 2, 3, 4, 5].map((fileId) => ({ fileId }))

describe('toggleSelection', () => {
	it('toggles one item', () => {
		const a = toggleSelection(new Set(), items, 1, null, false)
		expect([...a]).toEqual([2])
		expect([...toggleSelection(a, items, 1, 1, false)]).toEqual([])
	})

	it('adds a shift range in either direction, keeping what was selected', () => {
		expect([...toggleSelection(new Set([1]), items, 3, 1, true)].sort()).toEqual([1, 2, 3, 4])
		expect([...toggleSelection(new Set(), items, 0, 2, true)].sort()).toEqual([1, 2, 3])
	})

	it('treats shift without an anchor as a plain toggle', () => {
		expect([...toggleSelection(new Set(), items, 4, null, true)]).toEqual([5])
	})

	it('does not modify the input', () => {
		const sel = new Set([1])
		toggleSelection(sel, items, 2, null, false)
		expect([...sel]).toEqual([1])
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
