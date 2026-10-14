<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="display">
		<h3>{{ t('Tile size') }}</h3>
		<p class="hint">
			{{ t('Saved on this device.') }}
		</p>
		<NcCheckboxRadioSwitch
			v-for="s in sizes"
			:key="s.value"
			v-model="prefs.tileSize"
			type="radio"
			:value="s.value"
			name="tile-size">
			{{ s.label }}
		</NcCheckboxRadioSwitch>

		<h3>{{ t('Start with') }}</h3>
		<NcCheckboxRadioSwitch
			v-for="v in views"
			:key="v.value"
			v-model="userPrefs.start_view"
			type="radio"
			:value="v.value"
			name="start-view"
			@update:modelValue="save({ start_view: $event })">
			{{ v.label }}
		</NcCheckboxRadioSwitch>

		<h3>{{ t('Results') }}</h3>
		<NcCheckboxRadioSwitch v-model="userPrefs.show_videos" type="switch" @update:modelValue="save({ show_videos: $event })">
			{{ t('Include videos by default') }}
		</NcCheckboxRadioSwitch>

		<template v-if="page.sensitiveAvailable">
			<h3>{{ t('Sensitive pictures') }}</h3>
			<p class="hint">
				{{ t('Recognises nudity, so you can mark such pictures, hide them or look at only them. Recognised on this server from what Immich already knows about each picture; nothing leaves it. Only you see what is recognised or marked for you.') }}
			</p>
			<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_enabled" type="switch" @update:modelValue="toggleSensitive">
				{{ t('Recognise sensitive pictures') }}
			</NcCheckboxRadioSwitch>
			<template v-if="userPrefs.sensitive_enabled">
				<p class="sensitive__state" aria-live="polite">
					{{ sensitiveLine }}
				</p>
				<label class="sensitive__slider">
					<span>{{ t('Sensitivity') }}</span>
					<input
						v-model.number="sensitivity"
						type="range"
						min="20"
						max="95"
						step="5"
						@change="save({ sensitive_threshold: 115 - sensitivity })">
				</label>
				<p v-if="count !== null" class="hint">
					{{ n('At this sensitivity {count} picture counts as sensitive.', 'At this sensitivity {count} pictures count as sensitive.', count, { count }) }}
				</p>
				<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_badge" type="switch" @update:modelValue="save({ sensitive_badge: $event })">
					{{ t('Mark them on the tiles') }}
				</NcCheckboxRadioSwitch>
				<div class="sensitive__actions">
					<NcButton @click="review">
						{{ t('Review recognised pictures') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="status?.queued" @click="recompute">
						{{ t('Recompute now') }}
					</NcButton>
				</div>
				<p class="hint">
					{{ t('Mark pictures that were recognised wrongly, or missed, in the viewer or with a selection. Your marks always count, and the recognition learns from them within the hour.') }}
				</p>
			</template>
		</template>
	</div>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { getSensitive, rebuildSensitive } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'
import { prefs, saveUserPrefs, userPrefs } from '../../prefs.js'
import { navigate } from '../../router.js'
import { setSensitiveMode } from '../../sensitive.js'

const { act } = useAction()
const page = loadState('glimpse', 'page', { sensitiveAvailable: false })

const sizes = [
	{ value: 'small', label: t('Small tiles') },
	{ value: 'medium', label: t('Medium tiles') },
	{ value: 'large', label: t('Large tiles') },
]
const views = [
	{ value: 'search', label: t('Search') },
	{ value: 'timeline', label: t('Timeline') },
	{ value: 'people', label: t('People') },
	{ value: 'map', label: t('Map') },
	{ value: 'folders', label: t('Folders') },
]

const save = (changes) => act(() => saveUserPrefs(changes), t('Saved'))

/** Sensitivity on the slider: further right counts more pictures (a lower score threshold). */
const sensitivity = ref(115 - (userPrefs.sensitive_threshold ?? 50))
const status = ref(null)
const count = computed(() => status.value?.counts?.[115 - sensitivity.value] ?? null)
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })
const sensitiveLine = computed(() => {
	const s = status.value
	if (!s) {
		return ''
	}
	if (s.queued || !s.built) {
		return t('Being computed, this takes a minute …')
	}
	if (s.error) {
		return t('Last attempt failed: {error}', { error: s.error })
	}
	return t('Last computed {date}.', { date: dateFormat.format(new Date(s.built * 1000)) })
		+ (s.marked + s.unmarked ? ' ' + n('{count} picture marked by you.', '{count} pictures marked by you.', s.marked + s.unmarked, { count: s.marked + s.unmarked }) : '')
})

let poll = null
async function loadStatus() {
	clearTimeout(poll)
	if (!page.sensitiveAvailable || !userPrefs.sensitive_enabled) {
		status.value = null
		return
	}
	try {
		status.value = await getSensitive()
	} catch {
		status.value = null
	}
	// While it is being computed (a background job), look again now and then
	if (status.value?.queued || (status.value?.enabled && !status.value?.built)) {
		poll = setTimeout(loadStatus, 10000)
	}
}

async function toggleSensitive(on) {
	await save({ sensitive_enabled: on })
	loadStatus()
}

async function recompute() {
	await act(rebuildSensitive)
	loadStatus()
}

/** "Only sensitive", in the timeline: the list to clean up. */
async function review() {
	await setSensitiveMode('only')
	navigate('/timeline')
}

watch(() => userPrefs.sensitive_threshold, (v) => {
	sensitivity.value = 115 - (v ?? 50)
})
onMounted(loadStatus)
onBeforeUnmount(() => clearTimeout(poll))
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
h3 { margin-top: 20px; }
.hint, .sensitive__state { max-width: 62ch; }

.sensitive__slider {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 16px;
	margin: 12px 0 4px;

	input {
		flex: 0 1 280px;
		accent-color: var(--g-accent, var(--color-primary-element));
	}
}

.sensitive__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 12px 0 4px;
}
</style>
