<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Search with filters and results. With a fixed `person` (PersonView) it lists that
  person's pictures, newest first, optionally narrowed by text. "More like this" mode
  ranks by a picture instead of text.
-->
<template>
	<div class="search">
		<form class="search__bar" role="search" @submit.prevent="submit">
			<NcTextField
				v-model="q"
				class="search__input"
				:label="person ? t('Search in these pictures') : t('What is in the picture?')"
				:placeholder="t('e.g. woman on the beach')"
				trailingButtonIcon="close"
				:showTrailingButton="q !== ''"
				enterkeyhint="search"
				@keydown.enter.prevent="submit"
				@blur="submitIfChanged"
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
					:ariaLabelCombobox="t('Person')"
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

		<MediaBrowser
			:groups="[{ key: 'all', items }]"
			@removed="drop"
			@refresh="run(true)"
			@similar="showSimilar">
			<template #status>
				<p class="search__status" aria-live="polite">
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
			</template>
		</MediaBrowser>

		<NcEmptyContent v-if="emptyState" :name="emptyState.name" :description="emptyState.description">
			<template #icon>
				<NcIconSvgWrapper :path="emptyState.icon" />
			</template>
		</NcEmptyContent>

		<div ref="sentinel" class="search__more">
			<NcLoadingIcon v-if="loading" :size="32" />
		</div>
	</div>
</template>

<script setup>
import { mdiAlertCircleOutline, mdiClose, mdiImageOffOutline, mdiImageSearchOutline, mdiMagnify } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MediaBrowser from '../components/MediaBrowser.vue'
import { errorMessage, search, thumbnailUrl } from '../api.js'
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { t } from '../l10n.js'
import { userPrefs } from '../prefs.js'
import { replaceQuery, route } from '../router.js'

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
const nextPage = ref(null)
const loading = ref(false)
const error = ref('')
const searched = ref(false)
/** Person recognized in the text by the server ("Tara beach"), and whether to skip that. */
const resolved = ref(null)
const literal = ref(false)
/** "More like this" mode: {id, name} of the picture, or null */
const similar = ref(route.query.similar ? { id: route.query.similar, name: route.query.name || '' } : null)
let controller = null
/** Text of the last search, so leaving the field only searches when it changed. */
let lastQ = similar.value ? null : q.value.trim()
const sentinel = ref(null)
const more = useInfiniteScroll(sentinel, () => nextPage.value && !error.value && run(false))

const peopleOptions = computed(() => props.people.filter((p) => p.name))
const personOption = computed({
	get: () => peopleOptions.value.find((p) => p.id === personId.value) ?? null,
	set: (p) => {
		personId.value = p?.id ?? ''
	},
})

const status = computed(() => {
	if (!searched.value || !items.value.length) {
		return ''
	}
	const count = items.value.length + (nextPage.value ? '+' : '')
	return q.value && !similar.value
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
		nextPage.value = 1
		searched.value = false
		error.value = ''
		lastQ = similar.value ? null : q.value.trim()
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
			more.check()
		}
	}
}

/** Enter: always search for the text (also leaves "more like this"). */
function submit() {
	similar.value = null
	run(true)
}

/** Leaving the field searches only if the text changed since the last search. */
function submitIfChanged() {
	if (q.value.trim() !== lastQ) {
		submit()
	}
}

function drop(fileIds) {
	const gone = new Set(fileIds)
	items.value = items.value.filter((i) => !gone.has(i.fileId))
}

/**
 * "More like this" for one picture.
 *
 * @param item
 */
function showSimilar(item) {
	if (!item?.id) {
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

onMounted(() => run(true))
onBeforeUnmount(() => controller?.abort())
</script>

<style scoped lang="scss">
.search {
	&__bar {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 12px;
		align-items: center;
		margin-bottom: 8px;

		// One height and no stray margins, so text field, select and switches line up
		:deep(.input-field), :deep(.v-select) {
			margin-block: 0;
		}
	}

	&__input {
		flex: 1 1 320px;
		min-width: 0;
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 12px;
		align-items: center;
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

	&__similar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 12px;
		margin-bottom: 8px;

		img {
			width: 48px;
			height: 48px;
			border-radius: var(--border-radius);
			object-fit: cover;
		}
	}

	&__more {
		display: flex;
		justify-content: center;
		min-height: 48px;
		padding: 16px 0;
	}
}
</style>
