/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { addToAlbum, createAlbum, shareAlbum } from '../albums.js'
import { deleteFiles, favorite, tagFiles } from '../api.js'
import { n } from '../l10n.js'
import { useAction } from './useAction.js'

/**
 * Favorite, tag, album and delete for some pictures, with the outcome as a toast. Shared by
 * the selection bar (many pictures) and the viewer's action bar (one).
 */
export function usePictureActions() {
	const { busy, act } = useAction()

	function report(res, success) {
		if (!res) {
			return
		}
		if (res.failed?.length) {
			showWarning(success + ' · ' + n('{count} not allowed', '{count} not allowed', res.failed.length, { count: res.failed.length }))
		} else {
			showSuccess(success)
		}
	}

	async function setFavorite(fileIds, on) {
		const res = await act(() => favorite(fileIds, on))
		const count = res?.done ?? 0
		report(res, on
			? n('{count} file added to favorites', '{count} files added to favorites', count, { count })
			: n('{count} file removed from favorites', '{count} files removed from favorites', count, { count }))
		return res
	}

	async function tag(fileIds, tagIds, newTags) {
		const res = await act(() => tagFiles(fileIds, tagIds, newTags))
		const count = res?.done ?? 0
		report(res, n('{count} file tagged', '{count} files tagged', count, { count }))
		return res
	}

	/**
	 * @param items the pictures (album entries need their paths)
	 * @param name album
	 * @param isNew create it first
	 * @param recipients share the album with these
	 */
	async function album(items, name, isNew, recipients = []) {
		const added = await act(async () => {
			if (isNew) {
				await createAlbum(name)
			}
			return addToAlbum(name, items)
		})
		if (added === null) {
			return null
		}
		showSuccess(n('{count} picture added to {album}', '{count} pictures added to {album}', added, { count: added, album: name }))
		if (recipients.length) {
			const shared = await act(() => shareAlbum(name, recipients))
			if (shared) {
				showSuccess(n('{album}: shared with {count} more recipient', '{album}: shared with {count} more recipients', shared, { count: shared, album: name }))
			}
		}
		return added
	}

	/**
	 * Into the trash bin; the result lists what failed.
	 *
	 * @param fileIds
	 */
	async function remove(fileIds) {
		const res = await act(() => deleteFiles(fileIds))
		if (res) {
			report(res, n('{count} file moved to the trash bin', '{count} files moved to the trash bin', res.done, { count: res.done }))
		}
		return res
	}

	return { busy, act, report, setFavorite, tag, album, remove }
}
