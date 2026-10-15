<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Sensitive pictures: on or off, what the filter hides, training it. Only when the admin allows it. -->
<template>
	<div class="sensitive">
		<p class="hint">
			{{ t('A filter that hides the pictures you consider sensitive. You train it yourself: search for something, hide what matches, then check what it hides. Works with what Immich already knows about each picture; nothing leaves this server. Only you see your marks.') }}
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
			<h3>{{ t('Show') }}</h3>
			<NcCheckboxRadioSwitch
				v-for="m in sensitiveModes"
				:key="m.value"
				:modelValue="userPrefs.sensitive_mode"
				type="radio"
				:value="m.value"
				name="sensitive-mode"
				@update:modelValue="setSensitiveMode(m.value)">
				{{ m.label }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_badge" type="switch" @update:modelValue="save({ sensitive_badge: $event })">
				{{ t('Show a small mark on sensitive pictures') }}
			</NcCheckboxRadioSwitch>
			<div class="sensitive__actions">
				<NcButton variant="primary" @click="navigate('/sensitive')">
					{{ t('Train the sensitive filter') }}
				</NcButton>
				<NcButton @click="review">
					{{ t('Review sensitive pictures') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="recompute">
					{{ t('Recompute now') }}
				</NcButton>
			</div>
			<p class="hint">
				{{ t('Mark single pictures as sensitive or not in the viewer or with a selection. Your mark always counts for the picture, and from 20 marks on Glimpse learns from them.') }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { mdiClose } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { getSensitive, rebuildSensitive, removeSensitiveTerm } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'
import { saveUserPrefs, userPrefs } from '../../prefs.js'
import { href, navigate } from '../../router.js'
import { sensitiveModes, sensitiveState, setSensitiveMode } from '../../sensitive.js'

const { act } = useAction()

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
	if (s.model) {
		parts.push(n('{count} more by what Glimpse learned from your marks.', '{count} more by what Glimpse learned from your marks.', s.byModel ?? 0, { count: s.byModel ?? 0 }))
	}
	if (s.built) {
		parts.push(t('Last computed {date}.', { date: dateFormat.format(new Date(s.built * 1000)) }))
	}
	return parts.join(' ')
})

async function loadStatus() {
	if (!userPrefs.sensitive_enabled) {
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
