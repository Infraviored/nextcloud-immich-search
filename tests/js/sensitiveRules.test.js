/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { leavingView } from '../../src/sensitiveRules.js'

describe('leavingView', () => {
	const answer = { 1: true, 2: false, 3: true }

	it('takes the now sensitive out of "without sensitive"', () => {
		expect(leavingView(answer, 'hide')).toEqual([1, 3])
	})

	it('takes the now not sensitive out of "only sensitive"', () => {
		expect(leavingView(answer, 'only')).toEqual([2])
	})

	it('keeps everything when all pictures are shown', () => {
		expect(leavingView(answer, 'all')).toEqual([])
	})

	it('copes with no answer', () => {
		expect(leavingView(null, 'hide')).toEqual([])
	})
})
