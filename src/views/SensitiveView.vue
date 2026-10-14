<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Sorting sensitive pictures, step by step: find them by search terms, check the least sure
  ones, look at what the learned model suspects, and set how strict it is for new pictures.
  Lists are sorted by how well a picture fits, never by date. A click selects, shift-click a
  range; the button above the grid acts on the selection.
-->
<template>
	<div class="sort">
		<h2 class="g-display sort__title">
			{{ t('Sort sensitive pictures') }}
		</h2>
		<nav class="sort__steps" :aria-label="t('Steps')">
			<button
				v-for="(s, i) in steps"
				:key="s.id"
				type="button"
				class="sort__step"
				:class="{ 'sort__step--on': step === s.id }"
				:aria-current="step === s.id ? 'step' : undefined"
				@click="go(s.id)">
				<span class="sort__num">{{ i + 1 }}</span>
				{{ s.label }}
			</button>
		</nav>
		<p class="sort__learned" aria-live="polite">
			{{ learnedLine }}
		</p>

		<template v-if="step === 'find'">
			<p class="sort__hint">
				{{ t('Type something you want to sort out, for example “nude breasts”. English words usually work better. Only pictures that do not count as sensitive yet are shown, the best matches first. Select the ones that are sensitive and mark them; leave out the rest.') }}
			</p>
			<LensSearch
				v-model="q"
				class="sort__search"
				:placeholder="t('What should be sorted out?')"
				@submit="load(true)"
				@clear="q = ''; items = []; done = false" />
		</template>
		<p v-else-if="step === 'check'" class="sort__hint">
			{{ t('Everything that counts as sensitive and that you did not mark yourself, the least sure first. Select what is not sensitive. Once only sensitive ones come, you are done here.') }}
		</p>
		<p v-else-if="step === 'missed'" class="sort__hint">
			{{ model
				? t('Pictures that do not count as sensitive yet, but that Glimpse finds most likely to be. Select the sensitive ones and mark them.')
				: n('Glimpse learns from your marks once you marked {count} picture as sensitive. Go back to step 1.', 'Glimpse learns from your marks once you marked {count} pictures as sensitive. Go back to step 1.', minPositive, { count: minPositive }) }}
		</p>

		<section v-if="step === 'limit'" class="sort__limit">
			<p class="sort__hint">
				{{ t('New pictures are judged by what Glimpse learned from your marks. Choose how strict that is. Your terms and your marks keep counting either way.') }}
			</p>
			<template v-if="model && counts">
				<label class="sort__slider">
					<span>{{ t('Stricter') }}</span>
					<input
						v-model.number="limitIndex"
						type="range"
						min="0"
						:max="thresholds.length - 1"
						step="1"
						@change="saveThreshold">
					<span>{{ t('More generous') }}</span>
				</label>
				<p class="sort__count">
					{{ n('About {count} picture counts as sensitive by what Glimpse learned.', 'About {count} pictures count as sensitive by what Glimpse learned.', limitCount, { count: limitCount }) }}
				</p>
			</template>
			<p v-else class="sort__hint">
				{{ n('This needs {count} pictures marked as sensitive first.', 'This needs {count} pictures marked as sensitive first.', minPositive, { count: minPositive }) }}
			</p>
			<div class="sort__next">
				<NcButton variant="primary" @click="finish">
					{{ t('Done') }}
				</NcButton>
			</div>
		</section>

		<template v-else>
			<div v-if="items.length || selected.size" class="sort__bar">
				<span class="sort__selected">
					{{ selected.size ? n('{count} selected', '{count} selected', selected.size, { count: selected.size }) : t('Click to select, shift-click for a range') }}
				</span>
				<NcButton
					variant="primary"
					:disabled="!selected.size || busy"
					@click="act">
					{{ step === 'check' ? t('Not sensitive') : t('Sensitive') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="!items.length" @click="selectAll">
					{{ t('Select all') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="!selected.size" @click="clear">
					{{ t('Clear selection') }}
				</NcButton>
				<NcButton v-if="stale && step !== 'find'" variant="tertiary" :disabled="learning" @click="relearn">
					{{ t('Sort again by what was learned') }}
				</NcButton>
			</div>

			<AssetGrid
				:items="items"
				:selected="selected"
				:selectOnClick="true"
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
				<span v-if="learning" class="sort__hint">{{ t('Learning from your marks …') }}</span>
			</div>

			<div class="sort__next">
				<NcButton v-if="step === 'find'" @click="q = ''; items = []; done = false; focusSearch()">
					{{ t('Another term') }}
				</NcButton>
				<NcButton variant="primary" @click="go(nextStep)">
					{{ t('Next: {step}', { step: steps.find((s) => s.id === nextStep).label }) }}
				</NcButton>
			</div>
		</template>
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
import { errorMessage, getSensitive, rebuildSensitive, reviewSensitive, setSensitiveThreshold } from '../api.js'
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { n, t } from '../l10n.js'
import { sensitiveIcon } from '../materialIcons.js'
import { navigate, replaceQuery, route } from '../router.js'
import { toggleSelection } from '../selection.js'
import { markItems } from '../sensitive.js'
import { openInViewer } from '../viewer.js'

/** The steps in order; the first three are lists, the last sets the threshold. */
const steps = [
	{ id: 'find', label: t('Find') },
	{ id: 'check', label: t('Check') },
	{ id: 'missed', label: t('Missed ones') },
	{ id: 'limit', label: t('New pictures') },
]

const step = ref(steps.some((s) => s.id === route.query.step) ? route.query.step : 'find')
const q = ref('')
const items = ref([])
const selected = ref(new Set())
let anchor = null
let next = 0
/** Something was marked since the model last learned */
let marked = false
const loading = ref(false)
const busy = ref(false)
const done = ref(false)
const error = ref('')
/** Marks changed since the list was loaded: the order of "check" and "missed" may be different now. */
const stale = ref(false)
const status = ref(null)
/** Training on the marks (a few seconds) */
const learning = ref(false)
const limitIndex = ref(4)
let controller = null

const nextStep = computed(() => steps[Math.min(steps.findIndex((s) => s.id === step.value) + 1, steps.length - 1)].id)
const model = computed(() => !!status.value?.model)
const minPositive = computed(() => status.value?.minPositive ?? 20)
const thresholds = computed(() => status.value?.thresholds ?? [])
const counts = computed(() => status.value?.thresholdCounts ?? null)
/** The slider runs from strict (left, high threshold) to generous (right) */
const limitValue = computed(() => thresholds.value[thresholds.value.length - 1 - limitIndex.value] ?? 0)
const limitCount = computed(() => counts.value?.[String(limitValue.value)] ?? 0)

const learnedLine = computed(() => {
	const s = status.value
	if (!s) {
		return ''
	}
	const parts = [n('{count} picture counts as sensitive.', '{count} pictures count as sensitive.', s.flagged ?? 0, { count: s.flagged ?? 0 })]
	if (s.model) {
		parts.push(t('Glimpse learns from {positives} pictures you marked as sensitive and {negatives} you marked as not.', s.learned))
	} else {
		parts.push(n('Glimpse starts learning once {count} picture is marked as sensitive; {have} so far.', 'Glimpse starts learning once {count} pictures are marked as sensitive; {have} so far.', minPositive.value, { count: minPositive.value, have: s.learned?.positives ?? 0 }))
	}
	return parts.join(' ')
})

const emptyText = computed(() => {
	if (loading.value || error.value || items.value.length || !done.value) {
		return ''
	}
	if (step.value === 'find') {
		return t('Nothing more for this term')
	}
	return step.value === 'check' ? t('Nothing that counts as sensitive by itself') : (model.value ? t('Nothing more that looks sensitive') : '')
})

const sentinel = ref(null)
const more = useInfiniteScroll(sentinel, () => !done.value && !error.value && items.value.length && load(false))

async function loadStatus() {
	try {
		status.value = await getSensitive()
		const i = thresholds.value.indexOf(status.value.threshold ?? 0)
		limitIndex.value = i < 0 ? 4 : thresholds.value.length - 1 - i
	} catch (e) {
		error.value = errorMessage(e)
	}
}

/**
 * @param {boolean} fresh from the top (a new term or step) or the next page
 */
async function load(fresh) {
	if (step.value === 'limit' || (step.value === 'find' && !q.value.trim())) {
		return
	}
	if (fresh) {
		controller?.abort()
		items.value = []
		selected.value = new Set()
		next = 0
		done.value = false
		stale.value = false
	} else if (loading.value || done.value) {
		return
	}
	controller = new AbortController()
	const ctrl = controller
	loading.value = true
	error.value = ''
	try {
		const res = await reviewSensitive({ step: step.value, q: q.value.trim(), page: next }, ctrl.signal)
		if (ctrl !== controller) {
			return
		}
		const shown = new Set(items.value.map((i) => i.fileId))
		items.value = items.value.concat(res.items.filter((i) => !shown.has(i.fileId)))
		next = res.nextPage ?? 0
		done.value = res.nextPage === null || res.nextPage === undefined
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

/** Mark the selection (sensitive, or not in "check"); the pictures leave the list at once. */
async function act() {
	const chosen = items.value.filter((i) => selected.value.has(i.fileId))
	if (!chosen.length) {
		return
	}
	busy.value = true
	const res = await markItems(chosen, step.value !== 'check', {
		onUndo: () => {
			load(true)
			loadStatus()
		},
	})
	busy.value = false
	if (!res) {
		return
	}
	const gone = new Set(chosen.map((i) => i.fileId))
	items.value = items.value.filter((i) => !gone.has(i.fileId))
	clear()
	stale.value = step.value !== 'find'
	marked = true
	await loadStatus()
	more.check()
}

/**
 * Learn from the marks made since the last time (the server trains only when asked: marking
 * stays instant), then sort the list again.
 */
async function relearn() {
	learning.value = true
	loading.value = true
	try {
		status.value = await rebuildSensitive()
	} catch (e) {
		showError(errorMessage(e))
	} finally {
		learning.value = false
		loading.value = false
	}
	await load(true)
}

function go(id) {
	if (id === step.value) {
		return
	}
	const changed = stale.value || marked
	step.value = id
	replaceQuery({ step: id })
	items.value = []
	selected.value = new Set()
	done.value = false
	error.value = ''
	if (id === 'find') {
		focusSearch()
		load(true)
	} else if (changed) {
		// What was marked in the step before changes what the model thinks
		marked = false
		relearn()
	} else {
		load(true)
	}
}

async function saveThreshold() {
	try {
		status.value = await setSensitiveThreshold(limitValue.value)
	} catch (e) {
		showError(errorMessage(e))
	}
}

function finish() {
	navigate('/settings/display')
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

watch(step, () => more.check())
onMounted(() => {
	loadStatus()
	load(true)
	if (step.value === 'find') {
		focusSearch()
	}
	document.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
	controller?.abort()
	document.removeEventListener('keydown', onKey)
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

.sort__steps {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 8px;
}

.sort__step {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 14px 6px 6px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: none;
	color: var(--color-main-text);
	cursor: pointer;

	&--on {
		border-color: var(--g-accent, var(--color-primary-element));
		color: var(--g-accent, var(--color-primary-element));
	}
}

.sort__num {
	display: inline-grid;
	place-items: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: var(--color-background-dark);
	font-size: 13px;
	font-weight: 600;
}

.sort__step--on .sort__num {
	background: var(--g-accent, var(--color-primary-element));
	color: var(--color-main-background);
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

.sort__next {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 16px;
}

.sort__error {
	color: var(--color-error-text);
}

.sort__slider {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 16px;
	margin: 12px 0 4px;

	input {
		flex: 0 1 320px;
		accent-color: var(--g-accent, var(--color-primary-element));
	}
}

.sort__count {
	font-weight: 600;
}
</style>
