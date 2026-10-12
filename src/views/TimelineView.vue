<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- All pictures the user may see, newest first, by month; "on this day" on top; jump to a year. -->
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

		<MediaBrowser
			:groups="groups"
			@removed="drop"
			@refresh="reload"
			@similar="similar" />

		<NcEmptyContent v-if="!loading && !items.length" :name="error || t('No pictures yet')">
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MediaBrowser from '../components/MediaBrowser.vue'
import { errorMessage, memories as getMemories, thumbnailUrl, timeline, timelineStart } from '../api.js'
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { n, t } from '../l10n.js'
import { navigate, route } from '../router.js'

const thisYear = new Date().getFullYear()
const monthFormat = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })

const items = ref([])
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

const memory = computed(() => memories.value.find((m) => m.year === openMemory.value) ?? null)
const activeYear = computed(() => (items.value[0]?.date ? Number(items.value[0].date.slice(0, 4)) : null))

/** Consecutive items of the same month form a group with the month as heading. */
const groups = computed(() => {
	const out = []
	for (const item of items.value) {
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
		items.value.push(...res.items)
		nextPage.value = res.nextPage
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

/** Start over (year jump, undo); a request still running is dropped. */
function reload() {
	controller?.abort()
	controller = null
	items.value = []
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
	window.scrollTo({ top: 0 })
	reload()
}

function drop(fileIds) {
	const gone = new Set(fileIds)
	items.value = items.value.filter((i) => !gone.has(i.fileId))
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
	load()
	try {
		memories.value = await getMemories()
	} catch {
		// memories are a bonus
	}
})
onBeforeUnmount(() => controller?.abort())
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
