<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Geotagged pictures on a map (markers the user may see, from the hourly map index).
  Below the map: the pictures in the visible area, with all actions.
-->
<template>
	<div class="map">
		<div ref="mapEl" class="map__canvas" />
		<p class="map__status">
			<template v-if="loadingMarkers">
				{{ t('Loading map …') }}
			</template>
			<template v-else-if="!markers.length">
				{{ updated ? t('No geotagged pictures.') : t('The map is built in the background; it will appear here within the hour.') }}
			</template>
			<template v-else>
				{{ n('{count} picture in this area', '{count} pictures in this area', inView.length, { count: inView.length }) }}
			</template>
		</p>
		<MediaBrowser :groups="[{ key: 'area', items }]" @removed="drop" @similar="similar" />
		<div ref="sentinel" class="map__more">
			<NcLoadingIcon v-if="loadingItems" :size="32" />
		</div>
	</div>
</template>

<script setup>
import L from 'leaflet'
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MediaBrowser from '../components/MediaBrowser.vue'
import { itemsFor, mapMarkers, thumbnailUrl, tileUrl } from '../api.js'
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js'
import { n, t } from '../l10n.js'
import { navigate } from '../router.js'

import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'

const PAGE = 120

const mapEl = ref(null)
const sentinel = ref(null)
const markers = shallowRef([])
const inView = shallowRef([])
const items = ref([])
const updated = ref(null)
const loadingMarkers = ref(true)
const loadingItems = ref(false)
let map = null
let shown = 0
let generation = 0
const more = useInfiniteScroll(sentinel, () => loadItems())

/** Markers inside the visible area, newest first; the grid below follows the map. */
function refreshArea() {
	const b = map.getBounds()
	inView.value = markers.value.filter((m) => b.contains([m[2], m[3]])).sort((a, b2) => b2[5] - a[5])
	items.value = []
	shown = 0
	generation++
	loadItems()
}

async function loadItems() {
	if (loadingItems.value || shown >= inView.value.length) {
		return
	}
	const gen = generation
	const page = inView.value.slice(shown, shown + PAGE)
	shown += page.length
	loadingItems.value = true
	try {
		// Names and paths come from Nextcloud; the map index only holds ids and coordinates.
		const details = new Map((await itemsFor(page.map((m) => m[1]))).map((i) => [i.fileId, i]))
		if (gen === generation) {
			items.value.push(...page.filter((m) => details.has(m[1])).map((m) => ({ ...details.get(m[1]), id: m[0] })))
		}
	} finally {
		loadingItems.value = false
		more.check()
	}
}

function drop(fileIds) {
	const gone = new Set(fileIds)
	items.value = items.value.filter((i) => !gone.has(i.fileId))
}

function similar(item) {
	navigate('/', { similar: item.id, name: item.name })
}

onMounted(async () => {
	map = L.map(mapEl.value, { worldCopyJump: true }).setView([48.1, 11.5], 4)
	L.tileLayer(tileUrl(), {
		maxZoom: 19,
		attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
	}).addTo(map)

	try {
		const data = await mapMarkers()
		markers.value = data.markers
		updated.value = data.updated
	} finally {
		loadingMarkers.value = false
	}
	if (!markers.value.length) {
		return
	}
	const cluster = L.markerClusterGroup({ chunkedLoading: true, showCoverageOnHover: false, maxClusterRadius: 60 })
	cluster.addLayers(markers.value.map((m) => L.marker([m[2], m[3]], {
		icon: L.divIcon({ className: 'map__pin', html: `<img src="${thumbnailUrl(m[0])}" loading="lazy" alt="">`, iconSize: [44, 44] }),
	})))
	map.addLayer(cluster)
	map.fitBounds(cluster.getBounds(), { padding: [20, 20], maxZoom: 12 })
	map.on('moveend', refreshArea)
	refreshArea()
})

onBeforeUnmount(() => map?.remove())
</script>

<style scoped lang="scss">
.map {
	&__canvas {
		height: min(60vh, 560px);
		border-radius: var(--border-radius-large);
		z-index: 0;
	}

	&__status {
		margin: 8px 0;
		color: var(--color-text-maxcontrast);
	}

	&__more {
		display: flex;
		justify-content: center;
		min-height: 48px;
	}

	:deep(.map__pin) {
		img {
			width: 44px;
			height: 44px;
			border: 2px solid #fff;
			border-radius: 8px;
			object-fit: cover;
			box-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
		}
	}
}
</style>
