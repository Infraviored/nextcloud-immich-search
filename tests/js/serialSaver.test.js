/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { serialSaver } from '../../src/serialSaver.js'

/** A send() whose answers the test releases by hand. */
function manualSend() {
	const calls = []
	const send = (partial) => new Promise((resolve, reject) => calls.push({ partial, resolve, reject }))
	return { calls, send }
}

describe('serialSaver', () => {
	it('keeps one request in flight and sends the latest state of what came meanwhile', async () => {
		const { calls, send } = manualSend()
		const { save, idle } = serialSaver(send)
		const a = save({ list: ['svg'] })
		const b = save({ list: ['svg', 'gif'] })
		const c = save({ list: ['svg', 'gif', 'png'], other: 1 })
		expect(calls).toHaveLength(1)
		expect(calls[0].partial).toEqual({ list: ['svg'] })

		calls[0].resolve('first')
		expect(await a).toBe('first')
		expect(calls).toHaveLength(2)
		expect(calls[1].partial).toEqual({ list: ['svg', 'gif', 'png'], other: 1 })
		expect(idle()).toBe(false)

		calls[1].resolve('second')
		expect(await b).toBe('second')
		expect(await c).toBe('second')
		expect(idle()).toBe(true)
	})

	it('rejects only the batch that failed and goes on', async () => {
		const { calls, send } = manualSend()
		const { save, idle } = serialSaver(send)
		const a = save({ x: 1 })
		const b = save({ x: 2 })
		calls[0].reject(new Error('nope'))
		await expect(a).rejects.toThrow('nope')
		calls[1].resolve('ok')
		expect(await b).toBe('ok')
		expect(idle()).toBe(true)
	})
})
