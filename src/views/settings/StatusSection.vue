<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Index status, refreshed every 10 s while visible. Admins see queues, libraries and actions. -->
<template>
	<div class="status">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcLoadingIcon v-else-if="!status" :size="32" />
		<template v-else>
			<NcNoteCard :type="status.busy ? 'info' : 'success'">
				{{ status.busy
					? n('Immich is working: {count} task left', 'Immich is working: {count} tasks left', status.busy, { count: status.busy })
					: t('Everything is indexed.') }}
			</NcNoteCard>

			<template v-if="status.full">
				<p class="status__totals">
					{{ t('{photos} photos and {videos} videos in Immich', { photos: fmt(status.photos), videos: fmt(status.videos) }) }}
					<span v-if="status.ml.state !== 'unknown'" :class="'status__ml status__ml--' + status.ml.state">
						· {{ status.ml.state === 'ok' ? t('Analysis service reachable') : t('Analysis service not reachable') }}
					</span>
				</p>

				<table class="status__table">
					<thead>
						<tr>
							<th>{{ t('Step') }}</th>
							<th>{{ t('Running') }}</th>
							<th>{{ t('Waiting') }}</th>
							<th>{{ t('Failed') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(c, q) in status.queues" :key="q">
							<td>{{ queueNames[q] || q }}<span v-if="c.paused" class="status__paused"> ({{ t('paused') }})</span></td>
							<td>{{ fmt(c.active) }}</td>
							<td>{{ fmt(c.waiting) }}</td>
							<td :class="{ status__failed: c.failed }">
								{{ fmt(c.failed) }}
							</td>
						</tr>
					</tbody>
				</table>

				<h3>{{ t('Libraries') }}</h3>
				<table class="status__table">
					<thead>
						<tr>
							<th>{{ t('User') }}</th>
							<th>{{ t('Photos') }}</th>
							<th>{{ t('Videos') }}</th>
							<th>{{ t('Last scan') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="lib in status.libraries" :key="lib.id">
							<td>{{ lib.user || lib.name }}</td>
							<td>{{ fmt(lib.photos) }}</td>
							<td>{{ fmt(lib.videos) }}</td>
							<td>{{ lib.refreshedAt ? new Date(lib.refreshedAt).toLocaleString() : '–' }}</td>
						</tr>
					</tbody>
				</table>

				<div class="status__actions">
					<NcButton :disabled="busy" @click="act(queueMissing, t('Missing pictures queued'))">
						{{ t('Queue missing pictures') }}
					</NcButton>
					<NcButton :disabled="busy" @click="act(scanLibraries, t('Scan started'))">
						{{ t('Scan libraries now') }}
					</NcButton>
					<NcButton :disabled="busy" @click="act(reindexPeople, t('People are updated with the next background job run'))">
						{{ t('Update people now') }}
					</NcButton>
				</div>
			</template>

			<p class="status__people">
				{{ people }}
			</p>
			<p v-if="status.prune?.total" class="status__people">
				{{ n('{count} tiny picture taken out of Immich so far.', '{count} tiny pictures taken out of Immich so far.', status.prune.total, { count: status.prune.total }) }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { errorMessage, getStatus, queueMissing, reindexPeople, scanLibraries } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'

const REFRESH_MS = 10000

const status = ref(null)
const error = ref('')
const { busy, act } = useAction()
let timer = null

const queueNames = {
	library: t('Library scan'),
	metadataExtraction: t('Reading metadata'),
	thumbnailGeneration: t('Thumbnails'),
	smartSearch: t('Content analysis'),
	faceDetection: t('Finding faces'),
	facialRecognition: t('Recognizing people'),
	duplicateDetection: t('Duplicates'),
}

const fmt = (v) => Number(v || 0).toLocaleString()

const people = computed(() => {
	const p = status.value?.peopleIndex
	if (!p?.updated) {
		return t('The people list has not been built yet.')
	}
	return t('People list updated {time} ({seconds} s, {count} people).', {
		time: new Date(p.updated * 1000).toLocaleString(),
		seconds: p.seconds,
		count: p.people,
	})
})

async function load() {
	try {
		status.value = await getStatus()
		error.value = ''
	} catch (e) {
		error.value = errorMessage(e)
	}
}

onMounted(() => {
	load()
	timer = window.setInterval(() => {
		if (!document.hidden) {
			load()
		}
	}, REFRESH_MS)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<style scoped lang="scss">
.status {
	&__totals {
		margin: 12px 0;
	}

	&__ml--ok { color: var(--color-success-text); }
	&__ml--down { color: var(--color-error-text); }

	&__table {
		width: 100%;
		max-width: 640px;
		margin-bottom: 16px;
		border-collapse: collapse;

		th, td {
			padding: 6px 8px;
			border-bottom: 1px solid var(--color-border);
			text-align: start;
		}

		th { color: var(--color-text-maxcontrast); font-weight: normal; }
		td:not(:first-child), th:not(:first-child) { text-align: end; }
	}

	&__failed { color: var(--color-error-text); font-weight: bold; }
	&__paused { color: var(--color-warning-text); }

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin: 8px 0 16px;
	}

	&__people { color: var(--color-text-maxcontrast); }
}
</style>
