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
				{{ t('You decide what counts as sensitive: search for it, select the last result that still counts and choose “Sensitive up to here”. Works with what Immich already knows about each picture; nothing leaves this server. Only you see your terms and marks.') }}
			</p>
			<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_enabled" type="switch" @update:modelValue="toggleSensitive">
				{{ t('Mark sensitive pictures') }}
			</NcCheckboxRadioSwitch>
			<template v-if="userPrefs.sensitive_enabled">
				<p class="sensitive__state" aria-live="polite">
					{{ sensitiveLine }}
				</p>
				<ul v-if="status?.terms?.length" class="sensitive__terms">
					<li v-for="term in status.terms" :key="term.q">
						<a :href="searchHref(term.q)" @click.prevent="navigate('/search', { q: term.q })">“{{ term.q }}”</a>
						<span class="hint">
							{{ term.stale ? t('made with another search model, set it again') : n('{count} picture', '{count} pictures', term.count, { count: term.count }) }}
						</span>
						<NcButton variant="tertiary" :aria-label="t('Remove “{q}”', { q: term.q })" @click="removeTerm(term.q)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</li>
				</ul>
				<p v-else-if="status" class="hint">
					{{ t('No terms yet. Search for what you consider sensitive, select the last picture that still counts, and choose “Sensitive up to here” in the selection menu.') }}
				</p>
				<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_badge" type="switch" @update:modelValue="save({ sensitive_badge: $event })">
					{{ t('Mark them on the tiles') }}
				</NcCheckboxRadioSwitch>
				<div class="sensitive__actions">
					<NcButton @click="review">
						{{ t('Review sensitive pictures') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="recompute">
						{{ t('Recompute now') }}
					</NcButton>
				</div>
				<p class="hint">
					{{ t('Mark single pictures as sensitive or not in the viewer or with a selection. Your mark always counts for the picture, and the pictures most like it follow it.') }}
				</p>
			</template>
		</template>
	</div>
</template>

<script setup>
import { mdiClose } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { getSensitive, rebuildSensitive, removeSensitiveTerm } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'
import { prefs, saveUserPrefs, userPrefs } from '../../prefs.js'
import { href, navigate } from '../../router.js'
import { sensitiveState, setSensitiveMode } from '../../sensitive.js'

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

const status = ref(null)
const busy = ref(false)
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })
const sensitiveLine = computed(() => {
	const s = status.value
	if (!s) {
		return ''
	}
	if (s.error) {
		return t('Last attempt failed: {error}', { error: s.error })
	}
	const parts = [n('{count} picture counts as sensitive.', '{count} pictures count as sensitive.', s.flagged ?? 0, { count: s.flagged ?? 0 })]
	if (s.marked + s.unmarked) {
		parts.push(n('{count} picture marked by you.', '{count} pictures marked by you.', s.marked + s.unmarked, { count: s.marked + s.unmarked }))
	}
	if (s.added || s.removed) {
		parts.push(t('Pictures like your marks: {added} added, {removed} taken out.', { added: s.added, removed: s.removed }))
	}
	if (s.built) {
		parts.push(t('Last computed {date}.', { date: dateFormat.format(new Date(s.built * 1000)) }))
	}
	return parts.join(' ')
})

async function loadStatus() {
	if (!page.sensitiveAvailable || !userPrefs.sensitive_enabled) {
		status.value = null
		return
	}
	try {
		status.value = await getSensitive()
	} catch {
		status.value = null
	}
}

const searchHref = (q) => href('/search', { q })

async function removeTerm(q) {
	busy.value = true
	const res = await act(() => removeSensitiveTerm(q), t('Removed'))
	busy.value = false
	if (res) {
		status.value = res
		sensitiveState.version++
	}
}

async function toggleSensitive(on) {
	await save({ sensitive_enabled: on })
	loadStatus()
}

async function recompute() {
	busy.value = true
	const res = await act(rebuildSensitive)
	busy.value = false
	if (res) {
		status.value = res
		sensitiveState.version++
	}
}

/** "Only sensitive", in the timeline: the list to clean up. */
async function review() {
	await setSensitiveMode('only')
	navigate('/timeline')
}

onMounted(loadStatus)
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
h3 { margin-top: 20px; }
.hint, .sensitive__state { max-width: 62ch; }

.sensitive__terms {
	margin: 8px 0;

	li {
		display: flex;
		align-items: center;
		gap: 4px 12px;
		flex-wrap: wrap;
	}
}

.sensitive__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 12px 0 4px;
}
</style>
