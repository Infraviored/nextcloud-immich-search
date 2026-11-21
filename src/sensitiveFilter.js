/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { n, t } from './l10n.js'

/**
 * Returns a human-friendly role label.
 *
 * @param {'owner'|'editor'|'user'|string} role
 * @return {string}
 */
export function roleLabel(role) {
	if (role === 'owner') {
		return t('Owner')
	}
	if (role === 'user') {
		return t('User')
	}
	return t('Editor')
}

/**
 * Returns one line describing the shared partners (for SensitiveView and settings).
 *
 * @param {object|null} filter
 * @return {string}
 */
export function formatSharedFilterLine(filter) {
	if (!filter || !filter.shared) {
		return ''
	}
	if (filter.role === 'owner') {
		const others = (filter.members || [])
			.filter((m) => m.uid !== filter.owner)
			.map((m) => m.name || m.uid)
		if (!others.length) {
			return ''
		}
		return t('Shared filter with {partner}', { partner: others.join(', ') })
	}
	return t('Shared filter with {partner}', { partner: filter.ownerName || filter.owner })
}

/**
 * Filter recipient search candidates: only users (type 0) and not already members.
 *
 * @param {Array<{id: string, type: number, label: string}>} candidates
 * @param {string[]|Set<string>} existingUids
 * @return {Array<{id: string, type: number, label: string}>}
 */
export function filterRecipientCandidates(candidates, existingUids) {
	const set = existingUids instanceof Set ? existingUids : new Set(existingUids || [])
	return (candidates || []).filter((r) => r.type === 0 && !set.has(r.id))
}

/**
 * Headline for the active filter.
 *
 * @param {object|null} filter
 * @return {string}
 */
export function formatFilterHeadline(filter) {
	if (!filter) {
		return t('Filter')
	}
	if (filter.role === 'owner') {
		return filter.shared ? t('Shared filter (owned by you)') : t('Personal filter')
	}
	return t('Using {name}’s filter', { name: filter.ownerName || filter.owner })
}

/**
 * Explanation text for role and sharing status.
 *
 * @param {object|null} filter
 * @return {string}
 */
export function formatFilterDescription(filter) {
	if (!filter) {
		return ''
	}
	if (filter.role !== 'owner') {
		if (filter.role === 'editor') {
			return t('You are an editor. Your marks and search terms train this filter for everyone.')
		}
		return t('You are a user. Your marks are your personal exceptions and do not change the shared filter.')
	}
	const otherCount = Math.max(0, (filter.members?.length ?? 1) - 1)
	if (filter.shared && otherCount > 0) {
		return n(
			'Your filter is shared with {count} person. Editors train the filter with you.',
			'Your filter is shared with {count} people. Editors train the filter with you.',
			otherCount,
			{ count: otherCount },
		)
	}
	return t('Only you train and use this filter. You can share it with others below.')
}
