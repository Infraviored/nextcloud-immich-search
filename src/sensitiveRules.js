/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * After marking: the files that no longer belong in a view filtered by the sensitive mode
 * ("hide": the ones now sensitive, "only": the ones now not). Kept free of Vue for tests.
 *
 * @param {Object<string, boolean>|null|undefined} sensitive fileId => sensitive now (the server's answer)
 * @param {string} mode all, hide or only
 * @return {number[]} file ids
 */
export function leavingView(sensitive, mode) {
	if (mode !== 'hide' && mode !== 'only') {
		return []
	}
	return Object.entries(sensitive ?? {})
		.filter(([, on]) => (mode === 'hide' ? on : !on))
		.map(([id]) => Number(id))
}
