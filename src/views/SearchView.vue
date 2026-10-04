<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Search with filters and results. With a fixed `person` (PersonView) it lists that
  person's pictures, newest first, optionally narrowed by text.
-->
<template>
	<div class="search">
		<form class="search__bar" role="search" @submit.prevent="similar = null; run(true)">
			<NcTextField
				v-model="q"
				class="search__input"
				:label="person ? t('Search in these pictures') : t('What is in the picture?')"
				:placeholder="t('e.g. woman on the beach')"
				trailingButtonIcon="close"
				:showTrailingButton="q !== ''"
				enterkeyhint="search"
				@trailingButtonClick="q = ''; run(true)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMagnify" :size="20" />
				</template>
			</NcTextField>
			<div class="search__filters">
				<NcSelect
					v-if="!person"
					v-model="personOption"
					class="search__person"
					:options="peopleOptions"
					:inputLabel="t('Person')"
					:placeholder="t('All people')"
					label="name"
					:clearable="true"
					@update:modelValue="run(true)" />
				<div class="search__types" role="group" :aria-label="t('Type')">
					<NcCheckboxRadioSwitch
						v-for="opt in typeOptions"
						:key="opt.value"
						v-model="type"
						type="radio"
						buttonVariant
						buttonVariantGrouped="horizontal"
						:value="opt.value"
						name="type"
						@update:modelValue="run(true)">
						{{ opt.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</form>

		<div v-if="similar" class="search__similar">
			<img :src="thumbnailUrl(similar.id)" alt="">
			<span>{{ t('Pictures similar to {name}', { name: similar.name }) }}</span>
			<NcButton variant="tertiary" @click="similar = null; run(true)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClose" />
				</template>
				{{ t('Back to the search') }}
			</NcButton>
		</div>

		<SelectionBar
			v-if="selected.size"
			:count="selected.size"
			:fileIds="[...selected]"
			:canSelectAll="selected.size < items.length"
			:busy="transferBusy || actionBusy"
			:single="singleSelected"
			:photosEnabled="page.photosEnabled"
			@clear="selected = new Set()"
			@selectAll="selected = new Set(items.map((i) => i.fileId))"
			@move="doTransfer('move', $event)"
			@copy="doTransfer('copy', $event)"
			@favorite="doFavorite"
			@tag="dialog = 'tag'"
			@album="dialog = 'album'"
			@similar="showSimilar(singleSelected)"
			@delete="dialog = 'delete'" />
		<p v-else class="search__status" aria-live="polite">
			<template v-if="resolved">
				{{ resolved.rest
					? t('Pictures of {name} matching “{text}”', { name: resolved.name, text: resolved.rest })
					: t('Pictures of {name}', { name: resolved.name }) }}
				·
				<NcButton variant="tertiary-no-background" size="small" @click="run(true, true)">
					{{ t('Search the words instead') }}
				</NcButton>
				<br>
			</template>
			{{ status }}
		</p>

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

		<NcEmptyContent v-if="emptyState" :name="emptyState.name" :description="emptyState.description">
			<template #icon>
				<NcIconSvgWrapper :path="emptyState.icon" />
			</template>
		</NcEmptyContent>

		<AssetGrid
			v-model:selected="selected"
			:items="items"
			@open="open"
			@similar="showSimilar" />
		<div ref="sentinel" class="search__more">
			<NcLoadingIcon v-if="loading" :size="32" />
		</div>
	</div>
</template>

<script setup>
import { mdiAlertCircleOutline, mdiClose, mdiImageOffOutline, mdiImageSearchOutline, mdiMagnify } from '@mdi/js'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlbumDialog from '../components/AlbumDialog.vue'
import AssetGrid from '../components/AssetGrid.vue'
import SelectionBar from '../components/SelectionBar.vue'
import TagDialog from '../components/TagDialog.vue'
import { addToAlbum, createAlbum } from '../albums.js'
import { deleteFiles, errorMessage, favorite, fileUrl, search, tagFiles, thumbnailUrl } from '../api.js'
import { useAction } from '../composables/useAction.js'
import { useTransfer } from '../composables/useTransfer.js'
import { n, t } from '../l10n.js'
import { userPrefs } from '../prefs.js'
import { replaceQuery, route } from '../router.js'
import { afterTransfer } from '../selection.js'

const props = defineProps({
	/** Fixed person (PersonView); hides the person filter. */
	person: { type: String, default: '' },
	/** Named people for the filter: [{id, name}] */
	people: { type: Array, default: () => [] },
})

const typeOptions = [
	{ value: '', label: t('All') },
	{ value: 'IMAGE', label: t('Photos') },
	{ value: 'VIDEO', label: t('Videos') },
]

const q = ref(route.query.q || '')
const type = ref(route.query.type ?? (userPrefs.show_videos ? '' : 'IMAGE'))
const personId = ref(props.person || route.query.person || '')
const items = ref([])
const selected = ref(new Set())
const nextPage = ref(null)
const loading = ref(false)
const error = ref('')
const searched = ref(false)
/** Person recognized in the text by the server ("Tara beach"), and whether to skip that. */
const resolved = ref(null)
const literal = ref(false)
const sentinel = ref(null)
const { busy: transferBusy, run: runTransfer } = useTransfer(() => run(true))
const { busy: actionBusy, act } = useAction()
const page = loadState('immich_search', 'page', { photosEnabled: false })
/** Open dialog: 'tag', 'album', 'delete' or null */
const dialog = ref(null)
/** "More like this" mode: {id, name} of the picture, or null */
const similar = ref(route.query.similar ? { id: route.query.similar, name: route.query.name || '' } : null)
const singleSelected = computed(() => (selected.value.size === 1 ? items.value.find((i) => selected.value.has(i.fileId)) ?? null : null))
let controller = null
let observer = null

const peopleOptions = computed(() => props.people.filter((p) => p.name))
const personOption = computed({
	get: () => peopleOptions.value.find((p) => p.id === personId.value) ?? null,
	set: (p) => { personId.value = p?.id ?? '' },
})

const status = computed(() => {
	if (!searched.value || !items.value.length) {
		return ''
	}
	const count = items.value.length + (nextPage.value ? '+' : '')
	return q.value
		? t('{count} results, best first', { count })
		: t('{count} results, newest first', { count })
})

const emptyState = computed(() => {
	if (loading.value) {
		return null
	}
	if (error.value) {
		return { name: t('Search failed'), description: error.value, icon: mdiAlertCircleOutline }
	}
	if (!q.value && !personId.value && !similar.value) {
		return { name: t('Search photos by content'), description: t('Describe what should be in the picture, or pick a person.'), icon: mdiImageSearchOutline }
	}
	if (searched.value && !items.value.length) {
		return { name: t('Nothing found'), description: '', icon: mdiImageOffOutline }
	}
	return null
})

/**
 * @param {boolean} reset start a new search
 * @param {boolean} asText do not turn a person's name in the text into a person filter
 */
async function run(reset, asText = false) {
	if (reset) {
		literal.value = asText
		resolved.value = null
		controller?.abort()
		controller = null
		items.value = []
		selected.value = new Set()
		nextPage.value = 1
		searched.value = false
		error.value = ''
		if (similar.value) {
			replaceQuery({ similar: similar.value.id, name: similar.value.name, type: type.value })
		} else if (!props.person) {
			replaceQuery({ q: q.value.trim(), type: type.value, person: personId.value })
		} else {
			replaceQuery({ q: q.value.trim(), type: type.value })
		}
	} else if (controller || !nextPage.value) {
		return
	}
	if (!q.value.trim() && !personId.value && !similar.value) {
		return
	}

	const ctrl = controller = new AbortController()
	loading.value = true
	try {
		const res = await search(similar.value
			? { similar: similar.value.id, page: nextPage.value, type: type.value }
			: { q: q.value.trim(), page: nextPage.value, person: personId.value, type: type.value, literal: literal.value }, ctrl.signal)
		if (ctrl !== controller) {
			return
		}
		resolved.value = res.person ?? null
		items.value.push(...res.items)
		nextPage.value = res.nextPage
		searched.value = true
	} catch (e) {
		if (e?.name !== 'CanceledError' && ctrl === controller) {
			error.value = errorMessage(e)
		}
	} finally {
		if (ctrl === controller) {
			controller = null
			loading.value = false
			// The filter can drop whole pages: keep loading while the end is still on screen.
			queueMicrotask(checkSentinel)
		}
	}
}

function checkSentinel() {
	const r = sentinel.value?.getBoundingClientRect()
	if (r && r.top < window.innerHeight + 800 && nextPage.value && !error.value) {
		run(false)
	}
}

function open(item) {
	if (window.OCA?.Viewer) {
		window.OCA.Viewer.open({ path: item.path })
	} else {
		window.location.href = fileUrl(item.fileId)
	}
}

async function doTransfer(mode, target = null) {
	const ids = [...selected.value]
	const res = await runTransfer(ids, mode, target)
	if (!res) {
		return
	}
	// Moved files are no longer where Immich last saw them; drop them until the rescan.
	const next = afterTransfer(items.value, selected.value, res.failedIds, mode === 'move')
	items.value = next.items
	selected.value = next.selected
}

/**
 * Report a bulk action: success toast, or a warning naming how many were refused.
 *
 * @param res
 * @param success
 */
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

async function doFavorite(on) {
	const res = await act(() => favorite([...selected.value], on))
	report(res, on
		? n('{count} file added to favorites', '{count} files added to favorites', res?.done ?? 0, { count: res?.done ?? 0 })
		: n('{count} file removed from favorites', '{count} files removed from favorites', res?.done ?? 0, { count: res?.done ?? 0 }))
}

async function doTag(tagIds, newTags) {
	dialog.value = null
	const res = await act(() => tagFiles([...selected.value], tagIds, newTags))
	report(res, n('{count} file tagged', '{count} files tagged', res?.done ?? 0, { count: res?.done ?? 0 }))
}

async function doAlbum(name, isNew) {
	dialog.value = null
	const chosen = items.value.filter((i) => selected.value.has(i.fileId))
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
	const ids = [...selected.value]
	const res = await act(() => deleteFiles(ids))
	if (res) {
		report(res, n('{count} file moved to the trash bin', '{count} files moved to the trash bin', res.done, { count: res.done }))
		const next = afterTransfer(items.value, selected.value, res.failed.map((f) => f.fileId), true)
		items.value = next.items
		selected.value = next.selected
	}
}

const deleteButtons = computed(() => [
	{ label: t('Cancel'), callback: () => { dialog.value = null } },
	{ label: t('Delete'), variant: 'error', callback: doDelete },
])

/**
 * "More like this" for one picture.
 *
 * @param item
 */
function showSimilar(item) {
	if (!item) {
		return
	}
	similar.value = { id: item.id, name: item.name }
	window.scrollTo({ top: 0 })
	run(true)
}

watch(() => props.person, (p) => {
	personId.value = p
	run(true)
})

onMounted(() => {
	observer = new IntersectionObserver((e) => {
		if (e[0].isIntersecting) {
			run(false)
		}
	}, { rootMargin: '800px' })
	observer.observe(sentinel.value)
	run(true)
})
onBeforeUnmount(() => {
	observer?.disconnect()
	controller?.abort()
})
</script>

<style scoped lang="scss">
.search {
	&__bar {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 12px;
		align-items: flex-end;
		margin-bottom: 8px;
	}

	&__input {
		flex: 1 1 320px;
		min-width: 0;
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 12px;
		align-items: flex-end;
	}

	&__person {
		min-width: 200px;
	}

	&__types {
		display: flex;
	}

	&__status {
		min-height: 1.4em;
		margin: 4px 0 8px;
		color: var(--color-text-maxcontrast);
	}

	&__more {
		display: flex;
		justify-content: center;
		min-height: 48px;
		padding: 16px 0;
	}
}
</style>
