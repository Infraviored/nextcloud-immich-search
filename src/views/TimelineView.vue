<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- All pictures the user may see, newest first, by month; "on this day" on top; jump to a year
     and scroll on from there in both directions. -->
<template>
	<div class="timeline">
		<section v-if="memories.length" class="timeline__memories" :aria-label="t('On this day')">
			<button
				v-for="m in memories"
				:key="m.year"
				type="button"
				class="memory"
				:class="{ 'memory--open': openMemory === m.year }"
				@click="openMemory = openMemory === m.year ? null : m.year">
				<img :src="thumbnailUrl(m.items[0].id)" alt="" loading="lazy">
				<span class="memory__label">
					<strong>{{ n('{count} year ago', '{count} years ago', thisYear - m.year, { count: thisYear - m.year }) }}</strong>
					<span>{{ m.year }} · {{ n('{count} picture', '{count} pictures', m.items.length, { count: m.items.length }) }}</span>
				</span>
			</button>
		</section>
		<MediaBrowser
			v-if="memory"
			class="timeline__memory"
			:groups="[{ key: 'memory', title: t('On this day in {year}', { year: memory.year }), items: memory.items }]"
			@removed="dropMemory"
			@similar="similar" />

		<nav v-if="years.length > 1" class="timeline__years" :aria-label="t('Jump to year')">
			<NcButton
				v-for="y in years"
				:key="y"
				size="small"
				:variant="y === activeYear ? 'secondary' : 'tertiary'"
				@click="jump(y)">
				{{ y }}
			</NcButton>
		</nav>

		<div ref="topSentinel" class="timeline__newer">
			<NcLoadingIcon v-if="loadingNewer" :size="32" />
		</div>
		<MediaBrowser
			ref="grid"
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
</template>

<script setup>
import { mdiImageMultipleOutline } from '@mdi/js'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MediaBrowser from '../components/MediaBrowser.vue'
import { errorMessage, memories as getMemories, thumbnailUrl, timeline, timelineStart } from '../api.js'
import { scrollParent, useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { n, t } from '../l10n.js'
import { navigate, route } from '../router.js'

const thisYear = new Date().getFullYear()
const monthFormat = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })

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
const activeYear = computed(() => (items.value[0]?.date ? Number(items.value[0].date.slice(0, 4)) : null))

/** Consecutive items of the same month form a group with the month as heading. */
const groups = computed(() => {
	const out = []
	for (const item of all.value) {
		const key = (item.date || '').slice(0, 7) || 'unknown'
		if (out.at(-1)?.key !== key) {
			out.push({ key, title: key === 'unknown' ? t('Unknown date') : monthFormat.format(new Date(key + '-01T00:00:00Z')), items: [] })
		}
		out.at(-1).items.push(item)
	}
	return out
})

async function load() {
	if (controller || !nextPage.value) {
		return
	}
	const ctrl = controller = new AbortController()
	loading.value = true
	try {
		const res = await timeline({ page: nextPage.value, before: before.value }, ctrl.signal)
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
		const res = await timeline({ page: newerPage.value, after: before.value }, ctrl.signal)
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
			scroller.scrollTop += anchor.getBoundingClientRect().top - anchorTop
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

/**
 * Start the list at the end of that year.
 *
 * @param year
 */
function jump(year) {
	before.value = year >= thisYear ? '' : `${year + 1}-01-01T00:00:00.000Z`
	const scroller = scrollParent(topSentinel.value)
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
	navigate('/', { similar: item.id, name: item.name })
}

onMounted(async () => {
	newerPage.value = before.value ? 1 : null
	load()
	try {
		memories.value = await getMemories()
	} catch {
		// memories are a bonus
	}
})
onBeforeUnmount(() => {
	controller?.abort()
	newerController?.abort()
})
</script>

<style scoped lang="scss">
.timeline {
	&__memories {
		display: flex;
		gap: 8px;
		overflow-x: auto;
		padding-bottom: 8px;
		margin-bottom: 8px;
	}

	&__memory {
		margin-bottom: 16px;
	}

	&__years {
		display: flex;
		flex-wrap: wrap;
		gap: 2px;
		margin-bottom: 4px;
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

.memory {
	position: relative;
	flex: 0 0 auto;
	width: 160px;
	height: 200px;
	margin: 0;
	padding: 0;
	overflow: hidden;
	border: 0;
	border-radius: var(--border-radius-large);
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
		padding: 24px 10px 8px;
		background: linear-gradient(transparent, rgba(0, 0, 0, 0.7));
		color: #fff;
		text-align: start;
	}

	&--open {
		outline: 3px solid var(--color-primary-element);
		outline-offset: -3px;
	}
}

@media (max-width: 600px) {
	// One scrollable row instead of a wall of years
	.timeline__years {
		flex-wrap: nowrap;
		overflow-x: auto;
	}

	.memory {
		width: 120px;
		height: 150px;
	}
}
</style>
