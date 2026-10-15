/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { showError, showSuccess, showUndo } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, reactive } from 'vue'
import { errorMessage, markSensitive, setSensitiveTerm } from './api.js'
import { n, t } from './l10n.js'
import { saveUserPrefs, userPrefs } from './prefs.js'

const page = loadState('glimpse', 'page', { sensitiveAvailable: false })

/**
 * Sensitive content (SensitiveContent.php): optional, the admin allows it and each user turns
 * it on. `version` goes up when the mode changes what is visible: views reload on that.
 */
export const sensitiveState = reactive({ version: 0 })

/** Allowed by the admin and turned on by this user. */
export const sensitiveOn = computed(() => !!page.sensitiveAvailable && !!userPrefs.sensitive_enabled)

/** Show the mark on tiles. */
export const sensitiveBadge = computed(() => sensitiveOn.value && userPrefs.sensitive_badge === true)

export const sensitiveModes = [
	{ value: 'all', label: t('All pictures') },
	{ value: 'hide', label: t('Without sensitive pictures') },
	{ value: 'only', label: t('Only sensitive pictures') },
]

/**
 * @param {'all'|'hide'|'only'} mode
 */
export async function setSensitiveMode(mode) {
	if (userPrefs.sensitive_mode === mode) {
		return
	}
	try {
		await saveUserPrefs({ sensitive_mode: mode })
		sensitiveState.version++
	} catch (e) {
		showError(errorMessage(e))
	}
}

/**
 * Mark pictures by hand, with undo in the toast. The items' `sensitive` follows, so their
 * tiles change right away. Returns the server's answer (null on failure); `onUndo` runs after
 * an undo went through.
 *
 * @param {object[]} items
 * @param {boolean} on
 * @param {{onUndo?: Function}} [options]
 */
export async function markItems(items, on, { onUndo = null } = {}) {
	if (!items.length) {
		return null
	}
	try {
		const res = await markSensitive(Object.fromEntries(items.map((i) => [i.fileId, on])))
		apply(items, res.sensitive)
		const count = items.length
		// Undo puts the marks back as they were, including "no mark" (the score decides)
		showUndo(on
			? n('{count} picture marked as sensitive', '{count} pictures marked as sensitive', count, { count })
			: n('{count} picture marked as not sensitive', '{count} pictures marked as not sensitive', count, { count }), async () => {
			try {
				apply(items, (await markSensitive(res.previous)).sensitive)
				onUndo?.()
			} catch (e) {
				showError(errorMessage(e))
			}
		})
		return res
	} catch (e) {
		showError(errorMessage(e))
		return null
	}
}

/**
 * "Sensitive up to here", on one picture in the results of a text search: the search counts
 * as sensitive down to it. The results above it get the mark right away; everything else
 * follows when the views load again.
 *
 * @param {string} q the search text
 * @param {object} item the picked result
 * @param {object[]} items the results in their order
 */
export async function sensitiveUpToHere(q, item, items) {
	try {
		const res = await setSensitiveTerm(q, item.thumbId ?? item.id)
		const index = items.indexOf(item)
		for (const i of items.slice(0, index + 1)) {
			i.sensitive = true
		}
		const count = res.terms?.find((x) => x.q.toLowerCase() === q.toLowerCase())?.count ?? index + 1
		showSuccess(n('“{q}”: {count} picture counts as sensitive', '“{q}”: {count} pictures count as sensitive', count, { q, count }))
	} catch (e) {
		showError(errorMessage(e))
	}
}

function apply(items, sensitive) {
	for (const item of items) {
		if (sensitive?.[item.fileId] !== undefined) {
			item.sensitive = !!sensitive[item.fileId]
		}
	}
}
