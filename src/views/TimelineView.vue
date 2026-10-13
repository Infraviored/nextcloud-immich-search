<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- All pictures the user may see, newest first, by month; "on this day" on top; jump to a year
     and scroll on from there in both directions. -->
<template>
	<div class="timeline" :class="{ 'timeline--rail': years.length > 1 }">
		<div class="timeline__main">
			<section v-if="memories.length && !person" class="timeline__memories" :aria-label="t('On this day')">
				<button
					v-for="m in memories"
					:key="m.year"
					type="button"
					class="memory"
					:class="{ 'memory--open': openMemory === m.year }"
					:aria-pressed="openMemory === m.year"
					@click="openMemory = openMemory === m.year ? null : m.year">
					<img :src="thumbnailUrl(m.items[0].id)" alt="" loading="lazy">
					<span class="memory__label">
						<strong class="g-heading">{{ n('{count} year ago', '{count} years ago', thisYear - m.year, { count: thisYear - m.year }) }}</strong>
						<span>{{ n('{count} picture', '{count} pictures', m.items.length, { count: m.items.length }) }}</span>
					</span>
				</button>
			</section>
			<MediaBrowser
				v-if="memory"
				class="timeline__memory"
				:groups="[{ key: 'memory', title: t('On this day in {year}', { year: memory.year }), items: memory.items }]"
				@removed="dropMemory"
				@similar="similar" />

			<div v-if="before" class="timeline__today">
				<button type="button" class="today" @click="jump(thisYear)">
					{{ t('Back to today') }}
				</button>
			</div>

			<div ref="topSentinel" class="timeline__newer">
				<NcLoadingIcon v-if="loadingNewer" :size="32" />
			</div>
			<MediaBrowser
				ref="grid"
				class="timeline__grid"
				:person="person"
				:groups="groups"
				@removed="drop"
				@refresh="reload"
				@similar="similar" />

			<NcEmptyContent v-if="!loading && !all.length" :name="error || t('No pictures yet')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiImageMultipleOutline" />
				</template>
			</NcEmptyContent>
			<div ref="sentinel" class="timeline__more">
				<NcLoadingIcon v-if="loading" :size="32" />
			</div>
		</div>

		<!-- All years along the edge: the one in view in apricot, a click jumps there -->
		<nav
			v-if="years.length > 1"
			class="rail"
			:class="{ 'rail--awake': awake }"
			:style="{ '--years': years.length }"
			:aria-label="t('Jump to year')">
			<button
				v-for="y in years"
				:key="y"
				type="button"
				class="rail__year"
				:class="{ 'rail__year--active': y === activeYear }"
				:aria-current="y === activeYear ? 'true' : undefined"
				@click="jump(y)">
				{{ y }}
			</button>
		</nav>
	</div>
</template>

<script setup>
import { mdiImageMultipleOutline } from '@mdi/js'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MediaBrowser from '../components/MediaBrowser.vue'
import { errorMessage, memories as getMemories, thumbnailUrl, timeline, timelineStart } from '../api.js'
import { scrollParent, useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { n, t } from '../l10n.js'
import { navigate, route } from '../router.js'

const props = defineProps({
	/** {id, name}: one person's timeline (their page); null for everyone's */
	person: { type: Object, default: null },
	/** '', 'IMAGE' or 'VIDEO' */
	type: { type: String, default: '' },
})
const emit = defineEmits(['similar'])

const thisYear = new Date().getFullYear()
const monthFormat = new Intl.DateTimeFormat(undefined, { month: 'long', timeZone: 'UTC' })

const items = ref([])
/** After a jump: pictures from the jump point forward to today, oldest first, loaded on scrolling up. */
const newer = ref([])
const newerPage = ref(null)
const loadingNewer = ref(false)
let newerController = null
const topSentinel = ref(null)
const grid = ref(null)
/** One unbroken list, newest first. */
const all = computed(() => [...newer.value.toReversed(), ...items.value])
const years = ref([])
const memories = ref([])
const openMemory = ref(null)
const nextPage = ref(1)
/** ?at=YYYY-MM-DD ("show in timeline"): start at that day instead of today. */
const before = ref(timelineStart(route.query.at))
const loading = ref(false)
const error = ref('')
/** The request in flight; a reload aborts it, so an old answer can never be appended. */
let controller = null
const sentinel = ref(null)
const more = useInfiniteScroll(sentinel, () => nextPage.value && !error.value && load())
const less = useInfiniteScroll(topSentinel, () => newerPage.value && !loading.value && loadNewer(), { above: true })

const memory = computed(() => memories.value.find((m) => m.year === openMemory.value) ?? null)
/** The year the top of the window is in; follows the scrolling. */
const activeYear = ref(null)

/**
 * Consecutive items of the same month form a group with the month as heading; the first
 * month of each year opens a band with the year in big type.
 */
const groups = computed(() => {
	const out = []
	let year = null
	for (const item of all.value) {
		const key = (item.date || '').slice(0, 7) || 'unknown'
		if (out.at(-1)?.key !== key) {
			const y = key === 'unknown' ? null : Number(key.slice(0, 4))
			out.push({
				key,
				year: y !== year ? y : null,
				title: key === 'unknown' ? t('Unknown date') : monthFormat.format(new Date(key + '-01T00:00:00Z')),
				items: [],
			})
			year = y
		}
		out.at(-1).items.push(item)
	}
	return out
})

let scroller = null
let frame = 0
/** The last year band whose heading has scrolled past the top third of the window. */
function trackYear() {
	frame = 0
	const bands = grid.value?.$el?.querySelectorAll('[data-year]') ?? []
	const line = (scroller === document.scrollingElement ? 0 : scroller.getBoundingClientRect().top) + window.innerHeight / 3
	let year = all.value[0]?.date ? Number(all.value[0].date.slice(0, 4)) : null
	for (const band of bands) {
		if (band.getBoundingClientRect().top > line) {
			break
		}
		year = Number(band.dataset.year)
	}
	// At the very end the last year cannot scroll up to the line: it is the one in view then
	const el = scroller === document.scrollingElement ? document.documentElement : scroller
	if (bands.length && !nextPage.value && el.scrollTop + el.clientHeight >= el.scrollHeight - 4) {
		year = Number(bands[bands.length - 1].dataset.year)
	}
	activeYear.value = year
}

/** Phones: the years show while scrolling and a moment after, then leave the pictures free. */
const awake = ref(false)
let sleepTimer = 0

function onScroll() {
	frame ||= window.requestAnimationFrame(trackYear)
	awake.value = true
	window.clearTimeout(sleepTimer)
	sleepTimer = window.setTimeout(() => {
		awake.value = false
	}, 1800)
}

async function load() {
	if (controller || !nextPage.value) {
		return
	}
	const ctrl = controller = new AbortController()
	loading.value = true
	try {
		const res = await timeline({ page: nextPage.value, before: before.value, type: props.type, person: props.person?.id ?? '' }, ctrl.signal)
		if (ctrl !== controller) {
			return
		}
		const first = !items.value.length
		items.value.push(...res.items)
		nextPage.value = res.nextPage
		if (first && before.value) {
			if (res.items.length) {
				showJumpPoint()
			} else if (newerPage.value) {
				loadNewer()
			}
		}
		if (first) {
			nextTick(trackYear)
		}
		if (res.years) {
			years.value = res.years
		}
	} catch (e) {
		if (ctrl === controller && e?.name !== 'CanceledError') {
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

/**
 * Pictures newer than the jump point, put above the ones in view. The page keeps its place:
 * what is on screen stays where it is, the new pictures grow above it.
 */
async function loadNewer() {
	if (newerController || !newerPage.value) {
		return
	}
	const ctrl = newerController = new AbortController()
	loadingNewer.value = true
	try {
		const res = await timeline({ page: newerPage.value, after: before.value, type: props.type, person: props.person?.id ?? '' }, ctrl.signal)
		if (ctrl !== newerController) {
			return
		}
		// Whatever tile is at the top of the view stays there (pictures below may be loading too,
		// so the page height alone would not tell how much was added above)
		const scroller = scrollParent(topSentinel.value)
		const scrollerTop = scroller === document.scrollingElement ? 0 : scroller.getBoundingClientRect().top
		const tiles = grid.value?.$el?.querySelectorAll('.tile') ?? []
		let anchor = null
		for (const tile of tiles) {
			if (tile.getBoundingClientRect().top >= scrollerTop) {
				anchor = tile
				break
			}
		}
		anchor ??= tiles[0] ?? null
		const anchorTop = anchor?.getBoundingClientRect().top
		newer.value.push(...res.items)
		newerPage.value = res.nextPage
		await nextTick()
		if (anchor?.isConnected && anchorTop !== undefined) {
			const delta = anchor.getBoundingClientRect().top - anchorTop
			if (scroller === document.scrollingElement) {
				window.scrollBy({ top: delta })
			} else {
				scroller.scrollTop += delta
			}
		}
	} catch (e) {
		if (ctrl === newerController && e?.name !== 'CanceledError') {
			newerPage.value = null
		}
	} finally {
		if (ctrl === newerController) {
			newerController = null
			loadingNewer.value = false
			less.check()
		}
	}
}

/** Start over (year jump, undo); requests still running are dropped. */
function reload() {
	controller?.abort()
	controller = null
	newerController?.abort()
	newerController = null
	loadingNewer.value = false
	items.value = []
	newer.value = []
	newerPage.value = before.value ? 1 : null
	nextPage.value = 1
	error.value = ''
	load()
}

watch(() => props.type, () => reload())
watch(() => props.person?.id, () => reload())

/**
 * Start the list at the end of that year.
 *
 * @param year
 */
function jump(year) {
	before.value = year >= thisYear ? '' : `${year + 1}-01-01T00:00:00.000Z`
	activeYear.value = year
	if (scroller === document.scrollingElement) {
		window.scrollTo({ top: 0 })
	} else if (scroller) {
		scroller.scrollTop = 0
	}
	reload()
}

/** After a jump, the first pictures go to the top of the window; newer ones load above. */
async function showJumpPoint() {
	await nextTick()
	grid.value?.$el?.scrollIntoView({ block: 'start' })
	// The top was in reach before there was anything to put above it
	less.check()
}

function drop(fileIds) {
	const gone = new Set(fileIds)
	items.value = items.value.filter((i) => !gone.has(i.fileId))
	newer.value = newer.value.filter((i) => !gone.has(i.fileId))
}

function dropMemory(fileIds) {
	const gone = new Set(fileIds)
	memories.value = memories.value.map((m) => ({ ...m, items: m.items.filter((i) => !gone.has(i.fileId)) })).filter((m) => m.items.length)
	drop(fileIds)
}

function similar(item) {
	if (props.person) {
		emit('similar', item)
	} else {
		navigate('/', { similar: item.id, name: item.name })
	}
}

onMounted(async () => {
	scroller = scrollParent(sentinel.value)
	const scrollTarget = scroller === document.scrollingElement ? window : scroller
	scrollTarget.addEventListener('scroll', onScroll, { passive: true })
	newerPage.value = before.value ? 1 : null
	load()
	if (props.person) {
		return
	}
	try {
		memories.value = await getMemories()
	} catch {
		// memories are a bonus
	}
})
onBeforeUnmount(() => {
	const scrollTarget = scroller === document.scrollingElement ? window : scroller
	scrollTarget?.removeEventListener('scroll', onScroll)
	window.cancelAnimationFrame(frame)
	window.clearTimeout(sleepTimer)
	controller?.abort()
	newerController?.abort()
})
</script>

<style scoped lang="scss">
.timeline {
	&--rail {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 52px;
		gap: 12px;
	}

	&__main {
		min-width: 0;
	}

	&__memories {
		display: flex;
		gap: 10px;
		overflow-x: auto;
		padding-bottom: 8px;
		margin-bottom: 8px;
		scrollbar-width: none;
	}

	&__memory {
		margin-bottom: 16px;
	}

	// Floats in the top right corner while scrolling, as in the app
	&__today {
		position: sticky;
		top: 88px;
		z-index: 15;
		height: 0;
		display: flex;
		justify-content: flex-end;
	}

	// A jump puts the year below the app's bar, not under it
	&__grid {
		scroll-margin-top: 84px;
	}

	&__newer {
		display: flex;
		justify-content: center;
		min-height: 1px;
	}

	&__more {
		display: flex;
		justify-content: center;
		min-height: 48px;
		padding: 16px 0;
	}
}

.today {
	height: 40px;
	margin: 8px 0 0;
	padding: 0 18px;
	border: 1px solid var(--g-line);
	border-radius: 20px;
	background: var(--g-raised);
	box-shadow: 0 6px 20px rgba(8, 9, 20, 0.35);
	color: var(--g-text);
	font-weight: 600;
	cursor: pointer;

	&:hover, &:focus-visible {
		border-color: var(--g-lens-blue);
	}
}

.rail {
	position: sticky;
	top: 88px;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	align-self: start;
	height: calc(100vh - var(--header-height, 50px) - 120px);
	max-height: calc(28px * var(--years, 40));

	&__year {
		flex: 0 1 auto;
		min-height: 0;
		margin: 0;
		padding: 1px 4px;
		border: 0;
		border-radius: 8px;
		background: none;
		color: var(--g-muted);
		font-size: 13px;
		font-variant-numeric: tabular-nums;
		text-align: end;
		cursor: pointer;

		&:hover, &:focus-visible {
			color: var(--g-text);
			background: var(--g-raised);
		}

		&--active {
			color: var(--g-accent-text);
			font-weight: 700;
		}
	}
}

.memory {
	position: relative;
	flex: 0 0 auto;
	width: 148px;
	height: 196px;
	margin: 0;
	padding: 0;
	overflow: hidden;
	border: 0;
	border-radius: 16px;
	background: var(--g-raised);
	cursor: pointer;

	img {
		width: 100%;
		height: 100%;
		object-fit: cover;
	}

	&__label {
		position: absolute;
		inset: auto 0 0 0;
		display: flex;
		flex-direction: column;
		padding: 32px 12px 10px;
		background: linear-gradient(transparent, rgba(20, 22, 43, 0.85));
		color: #fff;
		font-size: 13px;
		text-align: start;

		strong {
			font-size: 17px;
		}
	}

	&--open {
		outline: 3px solid var(--g-accent-text);
		outline-offset: -3px;
	}
}

// Phones: the years float over the pictures' right edge, like the app's scrubber
@media (max-width: 600px) {
	.timeline--rail {
		display: block;
	}

	.timeline__memories {
		padding-inline: 16px;
	}

	.timeline__today {
		top: 12px;
		padding-inline-end: 52px;
	}

	.rail {
		position: fixed;
		z-index: 25;
		top: calc(var(--header-height, 50px) + 70px);
		right: 2px;
		height: auto;
		max-height: calc(100vh - var(--header-height, 50px) - 220px);
		overflow-y: auto;
		scrollbar-width: none;
		padding: 6px 2px;
		border-radius: 14px;
		background: var(--g-scrim);
		opacity: 0;
		pointer-events: none;
		transition: opacity 0.25s ease;

		&::-webkit-scrollbar {
			display: none;
		}

		&--awake, &:focus-within {
			opacity: 1;
			pointer-events: auto;
		}

		&__year {
			font-size: 11px;
		}
	}

	.memory {
		width: 116px;
		height: 154px;
	}
}
</style>
