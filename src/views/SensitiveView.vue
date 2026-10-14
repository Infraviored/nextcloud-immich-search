<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Training the sensitive filter on one page with two sides, back and forth: "Hidden" shows what
  the filter hides, the least sensitive-looking first (bring back what it hides too much),
  "Visible" what it lets through, the most sensitive-looking first (hide what it misses), or
  sorted by a search term to start or for a new topic. Each mark is saved at once and the
  filter learns again in the background; the list keeps its order until asked, so pictures
  do not jump away under the pointer. A click selects, shift-click a range.
-->
<template>
	<div class="sort">
		<h2 class="g-display sort__title">
			{{ t('Train the sensitive filter') }}
		</h2>
		<div class="sort__sides" role="group" :aria-label="t('Side')">
			<button
				v-for="s in sides"
				:key="s.id"
				type="button"
				class="sort__side"
				:class="{ 'sort__side--on': side === s.id }"
				:aria-pressed="side === s.id"
				@click="go(s.id)">
				{{ s.label }}
			</button>
		</div>
		<p v-if="orderLine" class="sort__order">
			{{ orderLine }}
		</p>
		<p class="sort__learned" aria-live="polite">
			<strong v-if="training">{{ t('Learning …') }}</strong>
			<strong v-else-if="trainedLine">{{ trainedLine }}</strong>
			{{ learnedLine }}
		</p>

		<template v-if="side === 'visible'">
			<p class="sort__hint">
				{{ model
					? t('What the filter lets through, the most sensitive-looking first. Select what it should hide. To look at a new topic, search for it.')
					: t('Type something the filter should hide, for example “nude breasts”. English words usually work better. Select the matching pictures and hide them.') }}
			</p>
			<LensSearch
				v-model="q"
				class="sort__search"
				:placeholder="t('What should be hidden?')"
				@submit="load(true)"
				@clear="q = ''; load(true)" />
		</template>
		<p v-else class="sort__hint">
			{{ model
				? t('What the filter hides that you did not mark yourself, the most harmless first. Select what should stay visible and bring it back.')
				: n('The filter learns once you hid {count} picture; {left} to go. Start on “Visible” with a term.', 'The filter learns once you hid {count} pictures; {left} to go. Start on “Visible” with a term.', minPositive, { count: minPositive, left: Math.max(0, minPositive - (status?.learned?.positives ?? 0)) }) }}
		</p>

		<div v-if="items.length || selected.size" class="sort__bar">
			<span class="sort__selected">
				{{ selected.size ? n('{count} selected', '{count} selected', selected.size, { count: selected.size }) : t('Click to enlarge; shift-click or the circle selects') }}
			</span>
			<NcButton
				variant="primary"
				:disabled="!selected.size || busy"
				@click="act">
				{{ side === 'hidden' ? t('Bring back') : t('Hide') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="!items.length" @click="selectAll">
				{{ t('Select all') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="!selected.size" @click="clear">
				{{ t('Clear selection') }}
			</NcButton>
			<NcButton v-if="resort && !training" variant="secondary" @click="load(true)">
				{{ t('Sort again') }}
			</NcButton>
		</div>

		<AssetGrid
			:items="items"
			:selected="selected"
			:clickOpens="true"
			@toggle="toggle"
			@open="open"
			@update:selected="selected = $event" />

		<NcEmptyContent v-if="emptyText" :name="emptyText">
			<template #icon>
				<NcIconSvgWrapper :path="sensitiveIcon" />
			</template>
		</NcEmptyContent>
		<p v-if="error" class="sort__error">
			{{ error }}
		</p>
		<div ref="sentinel" class="sort__more">
			<NcLoadingIcon v-if="loading" :size="32" />
		</div>
	</div>
</template>

<script setup>
import { showError } from '@nextcloud/dialogs'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AssetGrid from '../components/AssetGrid.vue'
import LensSearch from '../components/LensSearch.vue'
import { errorMessage, getSensitive, rebuildSensitive, reviewSensitive } from '../api.js'
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { n, t } from '../l10n.js'
import { sensitiveIcon } from '../materialIcons.js'
import { replaceQuery, route } from '../router.js'
import { toggleSelection } from '../selection.js'
import { markItems } from '../sensitive.js'
import { openInViewer } from '../viewer.js'

/** Visible first: one starts by hiding something */
const sides = [
	{ id: 'visible', label: t('Visible') },
	{ id: 'hidden', label: t('Hidden') },
]
/** Wait this long after the last mark before learning, so a few marks in a row train once */
const TRAIN_DELAY = 1500

const side = ref(sides.some((s) => s.id === route.query.side) ? route.query.side : 'visible')
const q = ref('')
/** The term the list is sorted by (what was submitted, not what is being typed) */
const searched = ref('')
const items = ref([])
const selected = ref(new Set())
let anchor = null
let next = 0
const loading = ref(false)
const busy = ref(false)
const done = ref(false)
const loaded = ref(false)
const error = ref('')
const status = ref(null)
/** Learning from the marks in the background */
const training = ref(false)
/** Learned at least once on this page: show what that changed */
const trained = ref(false)
/** The filter learned since the list was loaded: its order may be different now */
const resort = ref(false)
let trainTimer = null
/** Marked again while learning: learn once more afterwards */
let trainAgain = false
let controller = null

const model = computed(() => !!status.value?.model)
const minPositive = computed(() => status.value?.minPositive ?? 20)

const learnedLine = computed(() => {
	const s = status.value
	if (!s) {
		return ''
	}
	const parts = [n('The filter hides {count} picture.', 'The filter hides {count} pictures.', s.flagged ?? 0, { count: s.flagged ?? 0 })]
	if (s.model) {
		parts.push(t('It learns from {positives} pictures you hid and {negatives} you brought back.', s.learned))
	}
	return parts.join(' ')
})

/** How the list is sorted, right under the switch */
const orderLine = computed(() => {
	if (side.value === 'hidden') {
		return model.value ? t('Most harmless first') : ''
	}
	if (q.value.trim() && searched.value) {
		return t('Closest to “{q}” first', { q: searched.value })
	}
	return model.value ? t('Most sensitive-looking first') : ''
})

const trainedLine = computed(() => {
	const c = status.value?.change
	return trained.value && c ? t('Learned: {added} more hidden, {removed} fewer.', c) : ''
})

const emptyText = computed(() => {
	if (loading.value || error.value || items.value.length || !done.value) {
		return ''
	}
	if (side.value === 'hidden') {
		return model.value ? t('The filter hides nothing but what you marked yourself') : ''
	}
	return q.value.trim() ? t('Nothing more for this term') : (model.value ? t('Nothing more that looks sensitive') : '')
})

const sentinel = ref(null)
const more = useInfiniteScroll(sentinel, () => loaded.value && !done.value && !error.value && load(false))

async function loadStatus() {
	try {
		status.value = await getSensitive()
	} catch (e) {
		error.value = errorMessage(e)
	}
}

/**
 * @param {boolean} fresh from the top (a new term or side) or the next page
 */
async function load(fresh) {
	if (fresh) {
		controller?.abort()
		items.value = []
		selected.value = new Set()
		anchor = null
		next = 0
		done.value = false
		resort.value = false
		loaded.value = false
	} else if (loading.value || done.value) {
		return
	}
	controller = new AbortController()
	const ctrl = controller
	loading.value = true
	error.value = ''
	try {
		const term = side.value === 'visible' ? q.value.trim() : ''
		const res = await reviewSensitive({ side: side.value, q: term, page: next }, ctrl.signal)
		if (ctrl !== controller) {
			return
		}
		const shown = new Set(items.value.map((i) => i.fileId))
		items.value = items.value.concat(res.items.filter((i) => !shown.has(i.fileId)))
		searched.value = term
		next = res.nextPage ?? 0
		done.value = res.nextPage === null || res.nextPage === undefined
		loaded.value = true
	} catch (e) {
		if (ctrl === controller && e?.name !== 'CanceledError') {
			error.value = errorMessage(e)
		}
	} finally {
		if (ctrl === controller) {
			loading.value = false
			more.check()
		}
	}
}

function toggle(item, range) {
	selected.value = toggleSelection(selected.value, items.value, item.fileId, anchor, range)
	anchor = item.fileId
}

function selectAll() {
	selected.value = new Set(items.value.map((i) => i.fileId))
}

function clear() {
	selected.value = new Set()
	anchor = null
}

function open(item) {
	openInViewer(item, items.value)
}

/** Hide the selection, or bring it back on "Hidden"; it leaves the list at once, the filter learns. */
async function act() {
	const chosen = items.value.filter((i) => selected.value.has(i.fileId))
	if (!chosen.length) {
		return
	}
	busy.value = true
	const res = await markItems(chosen, side.value === 'visible', {
		onUndo: () => {
			load(true)
			scheduleTrain()
		},
	})
	busy.value = false
	if (!res) {
		return
	}
	const gone = new Set(chosen.map((i) => i.fileId))
	items.value = items.value.filter((i) => !gone.has(i.fileId))
	clear()
	scheduleTrain()
	loadStatus()
	if (!items.value.length && !done.value) {
		load(false)
	} else {
		more.check()
	}
}

function scheduleTrain() {
	clearTimeout(trainTimer)
	trainTimer = setTimeout(train, TRAIN_DELAY)
}

/** Learn from the marks (the server trains only when asked: marking stays instant). */
async function train() {
	trainTimer = null
	if (training.value) {
		trainAgain = true
		return
	}
	training.value = true
	try {
		status.value = await rebuildSensitive()
		trained.value = true
		resort.value = true
	} catch (e) {
		showError(errorMessage(e))
	} finally {
		training.value = false
	}
	if (trainAgain) {
		trainAgain = false
		train()
	}
}

function go(id) {
	if (id === side.value) {
		return
	}
	side.value = id
	replaceQuery({ side: id })
	error.value = ''
	load(true)
	if (id === 'visible') {
		focusSearch()
	}
}

function focusSearch() {
	nextTick(() => document.querySelector('.sort__search input')?.focus())
}

/** Ctrl+A selects everything loaded, unless typing. */
function onKey(event) {
	if ((event.ctrlKey || event.metaKey) && event.key === 'a' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName) && items.value.length) {
		event.preventDefault()
		selectAll()
	}
}

watch(side, () => more.check())
onMounted(async () => {
	document.addEventListener('keydown', onKey)
	await loadStatus()
	load(true)
	if (side.value === 'visible') {
		focusSearch()
	}
})
onBeforeUnmount(() => {
	controller?.abort()
	document.removeEventListener('keydown', onKey)
	// Marked just before leaving: still learn from it
	if (trainTimer) {
		clearTimeout(trainTimer)
		rebuildSensitive().catch(() => {})
	}
})
</script>

<style scoped lang="scss">
.sort {
	padding: 8px 16px 96px;
	max-width: 1400px;
}

.sort__title {
	margin: 8px 0 12px;
}

.sort__sides {
	display: inline-flex;
	gap: 2px;
	padding: 3px;
	margin-bottom: 8px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
}

.sort__side {
	// Nextcloud's button styles (margin, min sizes) would push the pill out of its frame
	box-sizing: border-box;
	margin: 0;
	min-width: 0;
	min-height: 0;
	padding: 6px 18px;
	border: none;
	border-radius: 999px;
	background: none;
	color: var(--color-main-text);
	font-weight: 600;
	cursor: pointer;

	&--on {
		background: var(--g-accent, var(--color-primary-element));
		color: var(--color-main-background);
	}
}

.sort__order {
	margin: 0 0 4px;
	font-weight: 600;
}

.sort__learned, .sort__hint {
	max-width: 70ch;
	color: var(--color-text-maxcontrast);
}

.sort__hint {
	margin: 12px 0;
	color: var(--color-main-text);
}

.sort__search {
	max-width: 560px;
	margin-bottom: 12px;
}

.sort__bar {
	position: sticky;
	top: 0;
	z-index: 5;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 8px 0;
	background: var(--color-main-background);
}

.sort__selected {
	min-width: 12ch;
	font-weight: 600;
}

.sort__more {
	display: flex;
	justify-content: center;
	min-height: 48px;
}

.sort__error {
	color: var(--color-error-text);
}
</style>
