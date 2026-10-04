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
		<form class="search__bar" role="search" @submit.prevent="run(true)">
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

		<SelectionBar
			v-if="selected.size"
			:count="selected.size"
			:canSelectAll="selected.size < items.length"
			:busy="transferBusy"
			:folderHref="selected.size === 1 ? fileUrl([...selected][0]) : ''"
			@clear="selected = new Set()"
			@selectAll="selected = new Set(items.map((i) => i.fileId))"
			@move="doTransfer('move')"
			@copy="doTransfer('copy')" />
		<p v-else class="search__status" aria-live="polite">
			{{ status }}
		</p>

		<NcEmptyContent v-if="emptyState" :name="emptyState.name" :description="emptyState.description">
			<template #icon>
				<NcIconSvgWrapper :path="emptyState.icon" />
			</template>
		</NcEmptyContent>

		<AssetGrid v-model:selected="selected" :items="items" @open="open" />
		<div ref="sentinel" class="search__more">
			<NcLoadingIcon v-if="loading" :size="32" />
		</div>
	</div>
</template>

<script setup>
import { mdiAlertCircleOutline, mdiImageOffOutline, mdiImageSearchOutline, mdiMagnify } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AssetGrid from '../components/AssetGrid.vue'
import SelectionBar from '../components/SelectionBar.vue'
import { errorMessage, fileUrl, search } from '../api.js'
import { useTransfer } from '../composables/useTransfer.js'
import { t } from '../l10n.js'
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
const sentinel = ref(null)
const { busy: transferBusy, run: runTransfer } = useTransfer()
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
	if (!q.value && !personId.value) {
		return { name: t('Search photos by content'), description: t('Describe what should be in the picture, or pick a person.'), icon: mdiImageSearchOutline }
	}
	if (searched.value && !items.value.length) {
		return { name: t('Nothing found'), description: '', icon: mdiImageOffOutline }
	}
	return null
})

async function run(reset) {
	if (reset) {
		controller?.abort()
		controller = null
		items.value = []
		selected.value = new Set()
		nextPage.value = 1
		searched.value = false
		error.value = ''
		if (!props.person) {
			replaceQuery({ q: q.value.trim(), type: type.value, person: personId.value })
		} else {
			replaceQuery({ q: q.value.trim(), type: type.value })
		}
	} else if (controller || !nextPage.value) {
		return
	}
	if (!q.value.trim() && !personId.value) {
		return
	}

	const ctrl = controller = new AbortController()
	loading.value = true
	try {
		const res = await search({ q: q.value.trim(), page: nextPage.value, person: personId.value, type: type.value }, ctrl.signal)
		if (ctrl !== controller) {
			return
		}
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

async function doTransfer(mode) {
	const ids = [...selected.value]
	const res = await runTransfer(ids, mode)
	if (!res) {
		return
	}
	// Moved files are no longer where Immich last saw them; drop them until the rescan.
	const next = afterTransfer(items.value, selected.value, res.failedIds, mode === 'move')
	items.value = next.items
	selected.value = next.selected
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
