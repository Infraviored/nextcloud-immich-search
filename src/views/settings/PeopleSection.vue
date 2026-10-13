<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div v-if="s" class="people-settings">
		<h3>{{ t('Who may name, merge and hide people?') }}</h3>
		<NcCheckboxRadioSwitch
			v-for="m in faceModes"
			:key="m.value"
			v-model="s.face_mode"
			type="radio"
			:value="m.value"
			name="face-mode"
			@update:modelValue="save({ face_mode: $event })">
			{{ m.label }}
		</NcCheckboxRadioSwitch>
		<p class="hint">
			{{ t('Everyone only ever sees people from pictures they can open. Names are shared by all users.') }}
		</p>

		<h3>{{ t('People list') }}</h3>
		<NcTextField
			:modelValue="String(s.people_min_count)"
			type="number"
			min="1"
			:label="t('Show unnamed people from this many pictures')"
			:helperText="t('Faces in the background form many tiny groups. Named people are always shown.')"
			@change="save({ people_min_count: Number($event.target.value) || 1 })" />

		<h4>{{ t('Update the list') }}</h4>
		<NcCheckboxRadioSwitch
			v-for="i in intervals"
			:key="i.value"
			v-model="s.people_interval_hours"
			type="radio"
			:value="i.value"
			name="people-interval"
			@update:modelValue="save({ people_interval_hours: $event })">
			{{ i.label }}
		</NcCheckboxRadioSwitch>
		<NcButton :disabled="busy" @click="act(reindexPeople, t('People are updated with the next background job run'))">
			{{ t('Update people now') }}
		</NcButton>
	</div>
	<NcLoadingIcon v-else :size="32" />
</template>

<script setup>
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadAdminSettings, saveAdmin } from '../../adminSettings.js'
import { reindexPeople } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { t } from '../../l10n.js'

const s = ref(null)
const { busy, act } = useAction()

const faceModes = [
	{ value: 'all', label: t('Every user (people they can see)') },
	{ value: 'admins', label: t('Only admins') },
	{ value: 'off', label: t('Nobody, names come from Immich only') },
]
const intervals = [
	{ value: '1', label: t('Every hour') },
	{ value: '6', label: t('Every 6 hours') },
	{ value: '24', label: t('Once a day') },
]

async function save(partial) {
	const res = await act(() => saveAdmin(partial), t('Saved'))
	if (res) {
		s.value = { ...res.settings }
	}
}

onMounted(async () => {
	s.value = { ...await act(loadAdminSettings) }
})
</script>

<style scoped>
.people-settings { display: flex; flex-direction: column; gap: 4px; max-width: 560px; align-items: flex-start; }
h3 { margin-top: 16px; }
h4 { margin: 12px 0 0; }
.hint { color: var(--color-text-maxcontrast); }
.people-settings :deep(input[type=number]) { max-width: 120px; }
</style>
