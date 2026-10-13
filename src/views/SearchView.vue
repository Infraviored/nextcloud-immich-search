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
		<!-- The search itself is the pill in the app's bar; a person's page has its own field -->
		<LensSearch
			v-if="person"
			v-model="q"
			class="search__person-field"
			:placeholder="t('Search in these pictures')"
			@submit="submit"
			@blur="submitIfChanged"
			@clear="run(true)" />
		<h2 v-else-if="q.trim() && !similar" class="search__query g-display" :class="{ 'search__query--long': q.trim().length > 28 }">
			{{ q.trim() }}
		</h2>
		<div class="search__filters">
			<slot name="filters" />
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
			<NcSelect
				v-if="tagOptions.length"
				v-model="tagOption"
				class="search__tag"
				:options="tagOptions"
				:ariaLabelCombobox="t('Filter by tag')"
				:placeholder="t('All tags')"
				label="name"
				:clearable="true"
				@update:modelValue="run(true)" />
			<div class="search__types" role="group" :aria-label="t('Type')">
				<button
					v-for="opt in typeOptions"
					:key="opt.value"
					type="button"
					class="chip"
					:class="{ 'chip--on': type === opt.value }"
					:aria-pressed="type === opt.value"
					@click="type = opt.value; run(true)">
					{{ opt.label }}
				</button>
			</div>
		</div>
		<!-- Nothing asked yet: the people to start from, as in the app -->
		<section v-if="idle && faces.length" class="search__people">
			<h3 class="g-heading">
				{{ t('People') }}
			</h3>
			<div class="faces">
				<a
					v-for="p in faces"
					:key="p.id"
					class="faces__person"
					:href="href('/people/' + p.id)"
					@click.prevent="navigate('/people/' + p.id)">
					<img :src="faceUrl(p.id)" alt="" loading="lazy">
					<span>{{ p.name }}</span>
				</a>
			</div>
		</section>

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
			:person="listedPerson"
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
import { mdiAlertCircleOutline, mdiClose, mdiImageOffOutline, mdiImageSearchOutline } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import LensSearch from '../components/LensSearch.vue'
import MediaBrowser from '../components/MediaBrowser.vue'
import { errorMessage, faceUrl, getTags, search, thumbnailUrl } from '../api.js'
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { t } from '../l10n.js'
import { peopleState } from '../people.js'
import { userPrefs } from '../prefs.js'
import { href, navigate, replaceQuery, route } from '../router.js'

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
/** Collaborative tag to narrow to; alone it lists all its pictures. */
const tagId = ref(route.query.tag || '')
const tagOptions = ref([])
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

/** Nothing to search for yet */
const idle = computed(() => !q.value.trim() && !personId.value && !similar.value && !tagId.value)
/** Named people, the most pictured first */
const faces = computed(() => peopleOptions.value.filter((p) => !p.hidden).toSorted((a, b) => (b.count ?? 0) - (a.count ?? 0)).slice(0, 24))
const personOption = computed({
	get: () => peopleOptions.value.find((p) => p.id === personId.value) ?? null,
	set: (p) => {
		personId.value = p?.id ?? ''
	},
})

const tagOption = computed({
	get: () => tagOptions.value.find((o) => String(o.id) === String(tagId.value)) ?? null,
	set: (o) => {
		tagId.value = o?.id ?? ''
	},
})

/** The person whose pictures are listed (fixed, picked or recognized in the text), {id, name} */
const listedPerson = computed(() => {
	const id = similar.value ? '' : (personId.value || resolved.value?.id || '')
	if (!id) {
		return null
	}
	const p = peopleState.people.find((x) => x.id === id)
	return { id, name: p?.name || resolved.value?.name || '' }
})

/** Without text or picture to rank by, results come by date. */
const byDate = computed(() => !(q.value.trim() && !similar.value))

const status = computed(() => {
	if (!searched.value || !items.value.length) {
		return ''
	}
	const count = items.value.length + (nextPage.value ? '+' : '')
	return byDate.value
		? t('{count} results, newest first', { count })
		: t('{count} results, best first', { count })
})

const emptyState = computed(() => {
	if (loading.value) {
		return null
	}
	if (error.value) {
		return { name: t('Search failed'), description: error.value, icon: mdiAlertCircleOutline }
	}
	if (idle.value) {
		return faces.value.length ? null : { name: t('Search photos by content'), description: t('Describe what should be in the picture, or pick a person or a tag.'), icon: mdiImageSearchOutline }
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
		// The aborted request skips its cleanup (it is no longer the current one), so the
		// spinner is reset here; otherwise it would stay if this search ends right below.
		controller?.abort()
		controller = null
		loading.value = false
		items.value = []
		nextPage.value = 1
		searched.value = false
		error.value = ''
		lastQ = similar.value ? null : q.value.trim()
		if (similar.value) {
			replaceQuery({ similar: similar.value.id, name: similar.value.name, type: type.value, tag: tagId.value })
		} else if (!props.person) {
			replaceQuery({ q: q.value.trim(), type: type.value, person: personId.value, tag: tagId.value })
		} else {
			replaceQuery({ q: q.value.trim(), type: type.value, tag: tagId.value })
		}
	} else if (controller || !nextPage.value) {
		return
	}
	if (!q.value.trim() && !personId.value && !similar.value && !tagId.value) {
		return
	}

	const ctrl = controller = new AbortController()
	loading.value = true
	try {
		const res = await search(similar.value
			? { similar: similar.value.id, page: nextPage.value, type: type.value, tag: tagId.value }
			: { q: q.value.trim(), page: nextPage.value, person: personId.value, type: type.value, literal: literal.value, tag: tagId.value }, ctrl.signal)
		if (ctrl !== controller) {
			return
		}
		resolved.value = res.person ?? null
		// A video can turn up again on a later page, found by another of its stills
		const shown = new Set(items.value.map((i) => i.fileId))
		items.value.push(...res.items.filter((i) => !shown.has(i.fileId)))
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

onMounted(async () => {
	run(true)
	try {
		tagOptions.value = await getTags(true)
	} catch {
		// no tag filter then; the search itself works without it
	}
})
onBeforeUnmount(() => controller?.abort())
</script>

<style scoped lang="scss">
.search {
	&__person-field {
		max-width: 560px;
		margin-bottom: 12px;
	}

	&__query {
		margin: 8px 0 14px;
		font-size: clamp(40px, 6vw, 64px);
		overflow-wrap: anywhere;

		// A whole sentence stays a heading, not a wall
		&--long {
			max-width: 24ch;
			font-size: clamp(30px, 3.6vw, 44px);
			line-height: 1;
		}
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 10px;
		align-items: center;
		margin-bottom: 10px;

		:deep(.v-select) {
			margin-block: 0;
		}
	}

	&__person, &__tag {
		min-width: 200px;
	}

	&__types {
		display: flex;
		gap: 6px;
	}

	&__people {
		margin-top: 20px;

		h3 {
			margin: 0 0 14px;
			font-size: 24px;
		}
	}

	&__status {
		min-height: 1.4em;
		margin: 4px 0 8px;
		color: var(--g-muted);
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
			border-radius: 10px;
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

.chip {
	height: 36px;
	min-height: 0;
	margin: 0;
	padding: 0 16px;
	border: 1px solid var(--g-line);
	border-radius: 18px;
	background: transparent;
	color: var(--g-muted);
	font-weight: 600;
	cursor: pointer;

	&:hover, &:focus-visible {
		color: var(--g-text);
	}

	&--on {
		border-color: var(--g-accent);
		background: var(--g-accent);
		color: var(--g-on-accent);

		&:hover, &:focus-visible {
			color: var(--g-on-accent);
		}
	}
}

.faces {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
	gap: 18px 10px;

	&__person {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		padding: 4px;
		border-radius: 16px;
		color: var(--g-text);
		font-weight: 600;
		text-decoration: none;

		img {
			width: 86%;
			aspect-ratio: 1;
			border-radius: 50%;
			object-fit: cover;
			background: var(--g-raised);
			transition: transform 0.15s ease;
		}

		span {
			max-width: 100%;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		&:hover img, &:focus-visible img {
			transform: scale(1.04);
		}
	}
}

@media (max-width: 600px) {
	.search {
		&__query, &__filters, &__people, &__status, &__similar, &__person-field {
			margin-inline: 16px;
		}
	}

	.faces {
		grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
	}
}
</style>
