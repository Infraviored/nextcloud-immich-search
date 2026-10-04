<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Grids of pictures with one shared selection and all bulk actions (move, copy, favorite,
  tag, album, ZIP, delete, similar). Used by search, timeline, map, folders and people.
  Groups get a heading each (months in the timeline); a single untitled group is a plain grid.
-->
<template>
	<div class="media">
		<SelectionBar
			v-if="selected.size"
			:count="selected.size"
			:fileIds="[...selected]"
			:canSelectAll="selected.size < allItems.length"
			:busy="transferBusy || actionBusy"
			:single="singleSelected"
			:photosEnabled="page.photosEnabled"
			@clear="selected = new Set()"
			@selectAll="selected = new Set(allItems.map((i) => i.fileId))"
			@move="doTransfer('move', $event)"
			@copy="doTransfer('copy', $event)"
			@favorite="doFavorite"
			@tag="dialog = 'tag'"
			@album="dialog = 'album'"
			@similar="$emit('similar', singleSelected)"
			@delete="dialog = 'delete'" />
		<slot v-else name="status" />

		<TagDialog
			v-if="dialog === 'tag'"
			:count="selected.size"
			@close="dialog = null"
			@apply="doTag" />
		<AlbumDialog
			v-if="dialog === 'album'"
			:count="selected.size"
			@close="dialog = null"
			@apply="doAlbum" />
		<NcDialog
			v-if="dialog === 'delete'"
			:name="n('Delete {count} file?', 'Delete {count} files?', selected.size, { count: selected.size })"
			:message="t('They go to the Nextcloud trash bin and can be restored from there.')"
			:buttons="deleteButtons"
			@closing="dialog = null" />

		<template v-for="group in groups" :key="group.key">
			<h3 v-if="group.title" class="media__heading">
				{{ group.title }}
			</h3>
			<AssetGrid
				v-model:selected="selected"
				:items="group.items"
				@open="open"
				@similar="$emit('similar', $event)" />
		</template>
	</div>
</template>

<script setup>
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import AlbumDialog from './AlbumDialog.vue'
import AssetGrid from './AssetGrid.vue'
import SelectionBar from './SelectionBar.vue'
import TagDialog from './TagDialog.vue'
import { addToAlbum, createAlbum } from '../albums.js'
import { deleteFiles, favorite, fileUrl, tagFiles } from '../api.js'
import { useAction } from '../composables/useAction.js'
import { useTransfer } from '../composables/useTransfer.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	/** [{key, title?, items}] */
	groups: { type: Array, required: true },
})
/**
 * removed: file ids that left this view (moved or deleted)
 * refresh: something was undone, reload the view
 * similar: "more like this" for an item
 */
const emit = defineEmits(['removed', 'refresh', 'similar'])

const page = loadState('immich_search', 'page', { photosEnabled: false })
const selected = ref(new Set())
const dialog = ref(null)
const { busy: transferBusy, run: runTransfer } = useTransfer(() => emit('refresh'))
const { busy: actionBusy, act } = useAction()

const allItems = computed(() => props.groups.flatMap((g) => g.items))
const singleSelected = computed(() => (selected.value.size === 1 ? allItems.value.find((i) => selected.value.has(i.fileId)) ?? null : null))

function open(item) {
	if (window.OCA?.Viewer && item.path) {
		window.OCA.Viewer.open({ path: item.path })
	} else {
		window.location.href = fileUrl(item.fileId)
	}
}

/**
 * Selected ids that are gone now; the ones that failed stay selected.
 *
 * @param failedIds
 */
function settle(failedIds) {
	const failed = new Set(failedIds)
	const gone = [...selected.value].filter((id) => !failed.has(id))
	selected.value = failed
	if (gone.length) {
		emit('removed', gone)
	}
}

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

async function doTransfer(mode, target = null) {
	const res = await runTransfer([...selected.value], mode, target)
	if (res) {
		// Moved files are no longer where Immich last saw them; they leave the view until the rescan.
		if (mode === 'move') {
			settle(res.failedIds)
		} else {
			selected.value = new Set(res.failedIds)
		}
	}
}

async function doFavorite(on) {
	const res = await act(() => favorite([...selected.value], on))
	const count = res?.done ?? 0
	report(res, on
		? n('{count} file added to favorites', '{count} files added to favorites', count, { count })
		: n('{count} file removed from favorites', '{count} files removed from favorites', count, { count }))
}

async function doTag(tagIds, newTags) {
	dialog.value = null
	const res = await act(() => tagFiles([...selected.value], tagIds, newTags))
	const count = res?.done ?? 0
	report(res, n('{count} file tagged', '{count} files tagged', count, { count }))
}

async function doAlbum(name, isNew) {
	dialog.value = null
	const chosen = allItems.value.filter((i) => selected.value.has(i.fileId))
	const added = await act(async () => {
		if (isNew) {
			await createAlbum(name)
		}
		return addToAlbum(name, chosen)
	})
	if (added !== null) {
		showSuccess(n('{count} picture added to {album}', '{count} pictures added to {album}', added, { count: added, album: name }))
	}
}

async function doDelete() {
	dialog.value = null
	const res = await act(() => deleteFiles([...selected.value]))
	if (res) {
		report(res, n('{count} file moved to the trash bin', '{count} files moved to the trash bin', res.done, { count: res.done }))
		settle(res.failed.map((f) => f.fileId))
	}
}

const deleteButtons = computed(() => [
	{
		label: t('Cancel'),
		callback: () => {
			dialog.value = null
		},
	},
	{ label: t('Delete'), variant: 'error', callback: doDelete },
])

defineExpose({
	clearSelection: () => {
		selected.value = new Set()
	},
})
</script>

<style scoped lang="scss">
.media__heading {
	position: sticky;
	top: 0;
	z-index: 5;
	margin: 16px 0 6px;
	padding: 4px 0;
	background: var(--color-main-background);
	font-size: 1.05em;
}
</style>
