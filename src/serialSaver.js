/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Saves one at a time: while a save is in flight, further changes are merged and sent
 * together afterwards, so a slow answer can never undo a later click.
 *
 * @param {(partial: object) => Promise<object>} send  gets the merged changes of a batch
 * @return {{save: (partial: object) => Promise<object>, idle: () => boolean}} save(partial) resolves (or rejects) with the
 *         answer of the request that carried it; idle() is true when nothing is in flight
 *         or waiting
 */
export function serialSaver(send) {
	let queued = null
	let running = false

	async function pump() {
		running = true
		while (queued) {
			const batch = queued
			queued = null
			try {
				const res = await send(batch.partial)
				batch.waiters.forEach((w) => w.resolve(res))
			} catch (e) {
				batch.waiters.forEach((w) => w.reject(e))
			}
		}
		running = false
	}

	function save(partial) {
		return new Promise((resolve, reject) => {
			queued = queued ?? { partial: {}, waiters: [] }
			Object.assign(queued.partial, partial)
			queued.waiters.push({ resolve, reject })
			if (!running) {
				pump()
			}
		})
	}

	return { save, idle: () => !running && !queued }
}
