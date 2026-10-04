<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- A Nextcloud folder as a gallery: breadcrumbs, subfolders, pictures with all actions. -->
<template>
	<div class="folders">
		<nav class="folders__crumbs" :aria-label="t('Folder')">
			<NcButton variant="tertiary" size="small" @click="go('/')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiHomeOutline" />
				</template>
				{{ t('Files') }}
			</NcButton>
			<template v-for="c in crumbs" :key="c.path">
				<span aria-hidden="true">›</span>
				<NcButton variant="tertiary" size="small" @click="go(c.path)">
					{{ c.name }}
				</NcButton>
			</template>
		</nav>

		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<div v-if="data.folders.length" class="folders__list">
				<NcButton
					v-for="f in data.folders"
					:key="f.path"
					alignment="start"
					:title="f.name"
					@click="go(f.path)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderOutline" />
					</template>
					{{ f.name }}
				</NcButton>
			</div>
			<MediaBrowser
				:groups="[{ key: data.path, items: data.items }]"
				@removed="drop"
				@refresh="load"
				@similar="similar">
				<template #status>
					<p class="folders__status">
						{{ data.items.length ? n('{count} picture or video', '{count} pictures and videos', data.items.length, { count: data.items.length }) : '' }}
						<span v-if="data.truncated">({{ t('only the first ones are shown') }})</span>
					</p>
				</template>
			</MediaBrowser>
			<NcEmptyContent v-if="!data.items.length && !data.folders.length" :name="error || t('No pictures in this folder')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderOutline" />
				</template>
			</NcEmptyContent>
		</template>
	</div>
</template>

<script setup>
import { mdiFolderOutline, mdiHomeOutline } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MediaBrowser from '../components/MediaBrowser.vue'
import { errorMessage, folder } from '../api.js'
import { n, t } from '../l10n.js'
import { navigate, replaceQuery, route } from '../router.js'

const path = ref(route.query.path || '/')
const data = ref({ path: '/', folders: [], items: [], truncated: false })
const loading = ref(true)
const error = ref('')

const crumbs = computed(() => {
	const parts = data.value.path.split('/').filter(Boolean)
	return parts.map((name, i) => ({ name, path: '/' + parts.slice(0, i + 1).join('/') }))
})

async function load() {
	loading.value = true
	error.value = ''
	try {
		data.value = await folder(path.value)
	} catch (e) {
		error.value = errorMessage(e)
		data.value = { path: path.value, folders: [], items: [], truncated: false }
	} finally {
		loading.value = false
	}
}

function go(p) {
	path.value = p
	replaceQuery({ path: p === '/' ? '' : p })
	load()
}

function drop(fileIds) {
	const gone = new Set(fileIds)
	data.value.items = data.value.items.filter((i) => !gone.has(i.fileId))
}

function similar(item) {
	navigate('/', { similar: item.id, name: item.name })
}

onMounted(load)
</script>

<style scoped lang="scss">
.folders {
	&__crumbs {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 2px;
		margin-bottom: 8px;
	}

	&__list {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
		gap: 6px;
		margin-bottom: 12px;

		// Long folder names: one line, cut with "…", full name in the tooltip
		> * {
			min-width: 0;
			max-width: 100%;
		}

		:deep(.button-vue__wrapper),
		:deep(.button-vue__text) {
			min-width: 0;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	}

	&__status {
		min-height: 1.4em;
		margin: 4px 0 8px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
