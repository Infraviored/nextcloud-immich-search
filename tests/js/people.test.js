/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

const pending = []
vi.mock('../../src/api.js', () => ({
	errorMessage: (e) => String(e),
	listPeople: () => new Promise((resolve) => pending.push(resolve)),
}))

const { peopleState, reloadPeople } = await import('../../src/people.js')

describe('reloadPeople', () => {
	it('ignores an answer that arrives after a newer one was requested', async () => {
		const unlocked = reloadPeople()
		const locked = reloadPeople()
		pending[1]({ people: [{ id: 'public' }], updated: null, canManage: false })
		await locked
		pending[0]({ people: [{ id: 'public' }, { id: 'private' }], updated: null, canManage: false })
		await unlocked
		expect(peopleState.people.map((p) => p.id)).toEqual(['public'])
		expect(peopleState.loading).toBe(false)
	})
})
