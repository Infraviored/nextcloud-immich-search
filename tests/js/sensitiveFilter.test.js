/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	filterRecipientCandidates,
	formatFilterDescription,
	formatFilterHeadline,
	formatSharedFilterLine,
	roleLabel,
} from '../../src/sensitiveFilter.js'

describe('sensitiveFilter', () => {
	describe('roleLabel', () => {
		it('returns correct label for roles', () => {
			expect(roleLabel('owner')).toBe('Owner')
			expect(roleLabel('editor')).toBe('Editor')
			expect(roleLabel('user')).toBe('User')
			expect(roleLabel('unknown')).toBe('Editor')
		})
	})

	describe('formatSharedFilterLine', () => {
		it('returns empty string if filter is null or not shared', () => {
			expect(formatSharedFilterLine(null)).toBe('')
			expect(formatSharedFilterLine({ shared: false })).toBe('')
		})

		it('formats partner name for member', () => {
			expect(formatSharedFilterLine({
				shared: true,
				role: 'editor',
				owner: 'florian',
				ownerName: 'Florian Schneider',
			})).toBe('Shared filter with Florian Schneider')
		})

		it('formats single partner name for owner', () => {
			expect(formatSharedFilterLine({
				shared: true,
				role: 'owner',
				owner: 'florian',
				ownerName: 'Florian Schneider',
				members: [
					{ uid: 'florian', name: 'Florian Schneider', role: 'owner' },
					{ uid: 'tara', name: 'Tara Test', role: 'editor' },
				],
			})).toBe('Shared filter with Tara Test')
		})

		it('formats multiple partner names for owner', () => {
			expect(formatSharedFilterLine({
				shared: true,
				role: 'owner',
				owner: 'florian',
				ownerName: 'Florian Schneider',
				members: [
					{ uid: 'florian', name: 'Florian Schneider', role: 'owner' },
					{ uid: 'tara', name: 'Tara', role: 'editor' },
					{ uid: 'bob', name: 'Bob', role: 'user' },
				],
			})).toBe('Shared filter with Tara, Bob')
		})
	})

	describe('filterRecipientCandidates', () => {
		const candidates = [
			{ id: 'tara', type: 0, label: 'Tara' },
			{ id: 'bob', type: 0, label: 'Bob' },
			{ id: 'family', type: 1, label: 'Family Group' }, // Group
			{ id: 'alice', type: 0, label: 'Alice' },
		]

		it('excludes groups and already existing members', () => {
			const existing = ['tara', 'florian']
			const filtered = filterRecipientCandidates(candidates, existing)
			expect(filtered).toEqual([
				{ id: 'bob', type: 0, label: 'Bob' },
				{ id: 'alice', type: 0, label: 'Alice' },
			])
		})
	})

	describe('formatFilterHeadline and formatFilterDescription', () => {
		it('formats personal filter for owner', () => {
			const f = { role: 'owner', shared: false, members: [{ uid: 'florian' }] }
			expect(formatFilterHeadline(f)).toBe('Personal filter')
			expect(formatFilterDescription(f)).toContain('Only you train and use this filter')
		})

		it('formats shared filter for owner', () => {
			const f = { role: 'owner', shared: true, members: [{ uid: 'florian' }, { uid: 'tara' }] }
			expect(formatFilterHeadline(f)).toBe('Shared filter (owned by you)')
			expect(formatFilterDescription(f)).toContain('shared with 1 person')
		})

		it('formats shared filter for member', () => {
			const f = { role: 'editor', shared: true, ownerName: 'Florian' }
			expect(formatFilterHeadline(f)).toBe('Using Florian’s filter')
			expect(formatFilterDescription(f)).toContain('You are an editor')
		})
	})
})
