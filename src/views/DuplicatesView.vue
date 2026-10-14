<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Duplicates Immich found among the pictures the user may see. Per group: pick what to
  keep (Immich's suggestion preselected), the rest goes to the Nextcloud trash bin.
-->
<template>
	<div class="dups">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="!groups.length" :name="error || t('No duplicates found')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiContentDuplicate" />
			</template>
		</NcEmptyContent>
		<template v-else>
			<p class="dups__intro">
				{{ n('{count} group of identical or nearly identical pictures.', '{count} groups of identical or nearly identical pictures.', groups.length, { count: groups.length }) }}
				{{ t('Marked pictures are kept, the others go to the trash bin.') }}
			</p>
			<section v-for="g in shown" :key="g.id" class="dup">
				<div class="dup__items">
					<label
						v-for="item in g.items"
						:key="item.fileId"
						class="dup__item"
						:class="{ 'dup__item--keep': keep[g.id].has(item.fileId) }">
						<input type="checkbox" :checked="keep[g.id].has(item.fileId)" @change="toggle(g, item)">
						<img
							:src="thumbnailUrl(item.id)"
							alt=""
							loading="lazy"
							@click.prevent="open(item)">
						<span class="dup__meta">
							<strong>{{ keep[g.id].has(item.fileId) ? t('Keep') : t('Delete') }}</strong>
							<span>{{ item.width }} × {{ item.height }} · {{ size(item.bytes) }}</span>
							<span>{{ item.date ? new Date(item.date).toLocaleDateString() : '' }}</span>
							<span class="dup__path" :title="item.path">{{ item.path }}</span>
						</span>
					</label>
				</div>
				<div class="dup__actions">
					<NcButton variant="primary" :disabled="busy || !keep[g.id].size || keep[g.id].size === g.items.length" @click="resolve(g)">
						{{ n('Delete {count} picture', 'Delete {count} pictures', g.items.length - keep[g.id].size, { count: g.items.length - keep[g.id].size }) }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="dismiss(g)">
						{{ t('Not duplicates') }}
					</NcButton>
				</div>
			</section>
			<NcButton v-if="shown.length < groups.length" @click="limit += 20">
				{{ t('Show more') }}
			</NcButton>
		</template>
	</div>
</template>

<script setup>
import { mdiContentDuplicate } from '@mdi/js'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { deleteFiles, dismissDuplicate, duplicates, errorMessage, thumbnailUrl } from '../api.js'
import { useAction } from '../composables/useAction.js'
import { n, t } from '../l10n.js'
import { openInViewer } from '../viewer.js'

const groups = ref([])
const keep = reactive({})
const limit = ref(20)
const loading = ref(true)
const error = ref('')
const { busy, act } = useAction()

const shown = computed(() => groups.value.slice(0, limit.value))

function size(bytes) {
	return bytes ? (bytes / 1048576).toFixed(1) + ' MB' : '–'
}

function toggle(g, item) {
	const s = keep[g.id]
	if (s.has(item.fileId)) {
		s.delete(item.fileId)
	} else {
		s.add(item.fileId)
	}
}

function open(item) {
	openInViewer(item, groups.value.flatMap((g) => g.items), {
		// Deleted or moved in the viewer: a group of one is no duplicate any more
		onRemoved: (fileIds) => {
			const gone = new Set(fileIds)
			groups.value = groups.value
				.map((g) => ({ ...g, items: g.items.filter((i) => !gone.has(i.fileId)) }))
				.filter((g) => g.items.length > 1)
		},
	})
}

async function resolve(g) {
	const ids = g.items.filter((i) => !keep[g.id].has(i.fileId)).map((i) => i.fileId)
	const res = await act(() => deleteFiles(ids))
	if (!res) {
		return
	}
	if (res.failed.length) {
		showWarning(n('{count} not allowed', '{count} not allowed', res.failed.length, { count: res.failed.length }))
	} else {
		showSuccess(n('{count} file moved to the trash bin', '{count} files moved to the trash bin', res.done, { count: res.done }))
	}
	groups.value = groups.value.filter((x) => x.id !== g.id)
}

async function dismiss(g) {
	if (await act(() => dismissDuplicate(g.id))) {
		groups.value = groups.value.filter((x) => x.id !== g.id)
	}
}

onMounted(async () => {
	try {
		groups.value = await duplicates()
		for (const g of groups.value) {
			// Immich's suggestion, or the largest file if it has none
			const suggested = g.items.filter((i) => g.keep.includes(i.id)).map((i) => i.fileId)
			keep[g.id] = new Set(suggested.length ? suggested : [g.items.reduce((a, b) => (b.bytes > a.bytes ? b : a)).fileId])
		}
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		loading.value = false
	}
})
</script>

<style scoped lang="scss">
.dups__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.dup {
	margin-bottom: 20px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);

	&__items {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
		gap: 8px;
	}

	&__item {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 6px;
		border: 2px solid transparent;
		border-radius: var(--border-radius-large);
		cursor: pointer;
		opacity: 0.6;

		input {
			position: absolute;
			top: 12px;
			left: 12px;
		}

		img {
			width: 100%;
			aspect-ratio: 1;
			border-radius: var(--border-radius);
			object-fit: cover;
		}

		&--keep {
			border-color: var(--color-primary-element);
			opacity: 1;
		}
	}

	&__meta {
		display: flex;
		flex-direction: column;
		font-size: var(--font-size-small, 13px);
	}

	&__path {
		overflow: hidden;
		color: var(--color-text-maxcontrast);
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 8px;
	}
}

@media (max-width: 600px) {
	.dups {
		padding-inline: 16px;
	}
}
</style>
