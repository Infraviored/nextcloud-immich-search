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
			:memoriesPaths="page.memoriesPaths"
			:person="peopleState.canManage ? person : null"
			:term="term"
			@clear="selected = new Set()"
			@selectAll="selected = new Set(allItems.map((i) => i.fileId))"
			@move="doTransfer('move', $event)"
			@copy="doTransfer('copy', $event)"
			@favorite="doFavorite"
			@tag="dialog = 'tag'"
			@album="dialog = 'album'"
			@similar="$emit('similar', singleSelected)"
			@faces="dialog = 'faces'"
			@private="doPrivate"
			@sensitive="doSensitive"
			@upToHere="doUpToHere"
			@delete="dialog = 'delete'"
			@notPerson="dialog = 'notPerson'"
			@timeline="navigate('/timeline', { at: singleSelected.date.slice(0, 10) })" />
		<slot name="status" />

		<FacesDialog v-if="dialog === 'faces' && singleSelected" :item="singleSelected" @close="dialog = null" />
		<TagDialog
			v-if="dialog === 'tag'"
			:count="selected.size"
			@close="dialog = null"
			@apply="doTag" />
		<NotPersonDialog
			v-if="dialog === 'notPerson' && person"
			:person="person"
			:count="selected.size"
			@close="dialog = null"
			@apply="doNotPerson" />
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
			<h2 v-if="group.year" class="media__year g-display" :data-year="group.year">
				{{ group.year }}
			</h2>
			<h3 v-if="group.title" class="media__heading g-heading">
				{{ group.title }}
			</h3>
			<AssetGrid
				v-model:selected="selected"
				:items="group.items"
				@toggle="toggle"
				@open="open"
				@similar="$emit('similar', $event)" />
		</template>
	</div>
</template>

<script setup>
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import AlbumDialog from './AlbumDialog.vue'
import AssetGrid from './AssetGrid.vue'
import FacesDialog from './FacesDialog.vue'
import NotPersonDialog from './NotPersonDialog.vue'
import SelectionBar from './SelectionBar.vue'
import TagDialog from './TagDialog.vue'
import { moveFaces } from '../api.js'
import { usePictureActions } from '../composables/usePictureActions.js'
import { useTransfer } from '../composables/useTransfer.js'
import { n, t } from '../l10n.js'
import { peopleState, reloadPeople } from '../people.js'
import { userPrefs } from '../prefs.js'
import { privateState, setPrivateFiles } from '../private.js'
import { navigate } from '../router.js'
import { pruneSelection, selectionActive, toggleSelection } from '../selection.js'
import { markItems, sensitiveUpToHere } from '../sensitive.js'
import { leavingView } from '../sensitiveRules.js'
import { openInViewer } from '../viewer.js'

const props = defineProps({
	/** [{key, title?, year?, items}]; a year opens a band with the year in big type above the group */
	groups: { type: Array, required: true },
	/** {id, name}: the pictures are this person's; offers correcting wrong faces */
	person: { type: Object, default: null },
	/** The text of a search, results best first: offers "Sensitive up to here" */
	term: { type: String, default: '' },
})
/**
 * removed: file ids that left this view (moved or deleted)
 * refresh: something was undone, reload the view
 * similar: "more like this" for an item
 */
const emit = defineEmits(['removed', 'refresh', 'similar'])

const page = loadState('glimpse', 'page', { photosEnabled: false, memoriesPaths: [] })
const selected = ref(new Set())
/** File id clicked last, start of a shift range; shared by all groups of the view. */
let anchor = null
const dialog = ref(null)
const { busy: transferBusy, run: runTransfer } = useTransfer(() => emit('refresh'))
const { busy: actionBusy, act, setFavorite, tag, album, remove } = usePictureActions()

const allItems = computed(() => props.groups.flatMap((g) => g.items))
const singleSelected = computed(() => (selected.value.size === 1 ? allItems.value.find((i) => selected.value.has(i.fileId)) ?? null : null))

// New search, map pan, folder change, year jump: what is gone cannot stay selected,
// or delete and move would act on pictures the user no longer sees.
watch(allItems, (items) => {
	selected.value = pruneSelection(selected.value, items)
})
watch(selected, (s) => {
	selectionActive.value = s.size > 0
}, { immediate: true })
onBeforeUnmount(() => {
	selectionActive.value = false
})

function toggle(item, range) {
	selected.value = toggleSelection(selected.value, allItems.value, item.fileId, anchor, range)
	anchor = item.fileId
}

function open(item) {
	openInViewer(item, allItems.value, {
		onRemoved: (fileIds) => emit('removed', fileIds),
		onRefresh: () => emit('refresh'),
	})
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

function doFavorite(on) {
	setFavorite([...selected.value], on)
}

function doTag(tagIds, newTags) {
	dialog.value = null
	tag([...selected.value], tagIds, newTags)
}

function doAlbum(name, isNew, recipients = []) {
	dialog.value = null
	album(allItems.value.filter((i) => selected.value.has(i.fileId)), name, isNew, recipients)
}

/**
 * "Not {person}": move that person's faces in the selected pictures elsewhere; the
 * pictures then leave this person's view.
 *
 * @param target as moveFaces() takes it, plus the chosen person's name
 */
async function doNotPerson(target) {
	dialog.value = null
	const chosen = allItems.value.filter((i) => selected.value.has(i.fileId) && i.id)
	const res = await act(() => moveFaces(props.person.id, chosen.map((i) => i.id), target))
	if (!res) {
		return
	}
	const moved = new Set(res.moved)
	const gone = chosen.filter((i) => moved.has(i.id)).map((i) => i.fileId)
	selected.value = new Set([...selected.value].filter((id) => !gone.includes(id)))
	emit('removed', gone)
	reloadPeople()
	if (target.unnamed || target.newName) {
		// Immich lists a new person only once it has made its face picture, a few seconds later
		setTimeout(reloadPeople, 8000)
	}
	const count = gone.length
	if (target.notAFace) {
		showSuccess(n('Face removed from {count} picture', 'Face removed from {count} pictures', count, { count }))
	} else {
		const name = target.name || target.newName || t('a new person without a name')
		showSuccess(n('{count} picture moved to {name}', '{count} pictures moved to {name}', count, { count, name }))
	}
	if (res.failed.length || count < chosen.length) {
		showWarning(n(
			'{count} picture had no face of {name} or could not be changed',
			'{count} pictures had no face of {name} or could not be changed',
			chosen.length - count,
			{ count: chosen.length - count, name: props.person.name || t('Unnamed') },
		))
	}
}

async function doPrivate(on) {
	const ids = [...selected.value]
	if (await act(() => setPrivateFiles(ids, on))) {
		showSuccess(on
			? n('{count} file is private now', '{count} files are private now', ids.length, { count: ids.length })
			: n('{count} file is no longer private', '{count} files are no longer private', ids.length, { count: ids.length }))
		selected.value = new Set()
		// Unlocked, private pictures stay visible; locked (only possible if the session ran
		// out meanwhile), they leave this view without reloading it.
		if (on && !privateState.unlocked) {
			emit('removed', ids)
		}
	}
}

/**
 * By hand, as sensitive or not; the tiles change, and pictures the sensitive filter no longer
 * lets through leave the view.
 *
 * @param {boolean} on
 */
async function doSensitive(on) {
	const ids = selected.value
	selected.value = new Set()
	let gone = []
	const res = await markItems(allItems.value.filter((i) => ids.has(i.fileId)), on, {
		// Undo brings back what the filter took out
		onUndo: () => gone.length && emit('refresh'),
	})
	// "Without sensitive" or "only sensitive": what no longer fits leaves the view at once
	gone = leavingView(res?.sensitive, userPrefs.sensitive_mode)
	if (gone.length) {
		emit('removed', gone)
	}
}

async function doUpToHere() {
	const item = singleSelected.value
	selected.value = new Set()
	await sensitiveUpToHere(props.term, item, allItems.value)
}

async function doDelete() {
	dialog.value = null
	const res = await remove([...selected.value])
	if (res) {
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
.media__year {
	margin: 40px 0 0;
	color: var(--g-text);
	font-size: clamp(64px, 9vw, 112px);

	&:first-of-type {
		margin-top: 8px;
	}
}

.media__heading {
	margin: 22px 0 10px;
	color: var(--g-text);
	font-size: 22px;
}

@media (max-width: 600px) {
	.media__year {
		margin-inline: 16px;
	}

	.media__heading {
		margin-inline: 16px;
		font-size: 20px;
	}
}
</style>
