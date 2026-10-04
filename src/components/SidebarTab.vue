<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Files sidebar tab: who is in this picture, and pictures that look like it. -->
<template>
	<div class="imsearch-tab">
		<p v-if="state === 'loading'" class="imsearch-tab__hint">
			{{ t('Loading …') }}
		</p>
		<p v-else-if="state === 'missing'" class="imsearch-tab__hint">
			{{ t('This picture is not indexed (yet).') }}
		</p>
		<template v-else>
			<h3>{{ t('People in this picture') }}</h3>
			<p v-if="!people.length" class="imsearch-tab__hint">
				{{ faceCount ? n('{count} face found, not assigned to anyone yet.', '{count} faces found, not assigned to anyone yet.', faceCount, { count: faceCount }) : t('No faces found.') }}
			</p>
			<ul v-else class="imsearch-tab__people">
				<li v-for="p in people" :key="p.id">
					<a :href="appUrl('/people/' + p.id)">
						<img :src="faceUrl(p.id)" alt="">
						<span>{{ p.name || t('Unnamed') }}</span>
					</a>
				</li>
			</ul>

			<a v-if="faceCount" class="imsearch-tab__link" :href="appUrl('/?faces=' + asset.id + '&name=' + encodeURIComponent(asset.name))">
				{{ t('Assign faces …') }}
			</a>

			<h3>{{ t('Similar pictures') }}</h3>
			<div class="imsearch-tab__similar">
				<a
					v-for="s in similar"
					:key="s.id"
					:href="fileUrl(s.fileId)"
					:title="s.name">
					<img :src="thumbnailUrl(s.id)" alt="" loading="lazy">
				</a>
			</div>
			<a class="button" :href="appUrl('/?similar=' + asset.id + '&name=' + encodeURIComponent(asset.name))">
				{{ t('Show all similar pictures') }}
			</a>
		</template>
	</div>
</template>

<script setup>
import { generateUrl } from '@nextcloud/router'
import { ref, watch } from 'vue'
import { assetForFile, faceUrl, fileUrl, getFaces, search, thumbnailUrl } from '../api.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	/** Set by the Files sidebar: the selected file */
	node: { type: Object, default: null },
	active: { type: Boolean, default: false },
})

const SIMILAR = 9
const state = ref('loading')
const asset = ref(null)
const people = ref([])
const faceCount = ref(0)
const similar = ref([])

const appUrl = (path) => generateUrl('/apps/immich_search' + path)

async function load() {
	if (!props.node || !props.active) {
		return
	}
	state.value = 'loading'
	try {
		asset.value = await assetForFile(props.node.fileid)
	} catch {
		state.value = 'missing'
		return
	}
	state.value = 'ready'
	const [faces, sim] = await Promise.allSettled([getFaces(asset.value.id), search({ similar: asset.value.id })])
	const seen = new Map()
	for (const f of faces.value?.faces ?? []) {
		if (f.personId && f.known && !seen.has(f.personId)) {
			seen.set(f.personId, { id: f.personId, name: f.personName })
		}
	}
	people.value = [...seen.values()]
	faceCount.value = faces.value?.faces?.length ?? 0
	similar.value = (sim.value?.items ?? []).slice(0, SIMILAR)
}

watch(() => [props.node?.fileid, props.active], load, { immediate: true })
</script>

<style lang="scss">
// Global (no shadow DOM), so everything is prefixed.
.imsearch-tab {
	padding: 8px 0;

	h3 {
		margin: 12px 0 6px;
		font-size: 1em;
		font-weight: bold;
	}

	&__hint { color: var(--color-text-maxcontrast); }

	&__link {
		display: inline-block;
		margin: 4px 0;
		text-decoration: underline;
	}

	&__people {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;

		a {
			display: flex;
			flex-direction: column;
			align-items: center;
			width: 72px;
			text-align: center;
		}

		img {
			width: 56px;
			height: 56px;
			border-radius: 50%;
			object-fit: cover;
		}

		span {
			overflow: hidden;
			max-width: 100%;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	}

	&__similar {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 4px;
		margin-bottom: 10px;

		img {
			display: block;
			width: 100%;
			aspect-ratio: 1;
			border-radius: 4px;
			object-fit: cover;
		}
	}
}
</style>
