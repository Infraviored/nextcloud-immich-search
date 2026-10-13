<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- How the app shows up in Nextcloud's own search (the magnifier in the header, mobile apps). -->
<template>
	<div class="search-settings">
		<h3>{{ t('For me') }}</h3>
		<NcCheckboxRadioSwitch
			:modelValue="!userPrefs.search_disabled"
			type="switch"
			@update:modelValue="saveMine">
			{{ t('Show photos by content in my Nextcloud search') }}
		</NcCheckboxRadioSwitch>

		<template v-if="page.isAdmin && s">
			<h3>{{ t('For everyone (admin)') }}</h3>
			<NcCheckboxRadioSwitch v-model="s.search_photos" type="switch" @update:modelValue="save({ search_photos: $event })">
				{{ t('Photos by content') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="s.search_people" type="switch" @update:modelValue="save({ search_people: $event })">
				{{ t('People (search a name, open their pictures)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="s.search_open_all" type="switch" @update:modelValue="save({ search_open_all: $event })">
				{{ t('First entry “Open all results in Glimpse”') }}
			</NcCheckboxRadioSwitch>

			<NcTextField
				:modelValue="String(s.search_min_term)"
				type="number"
				min="1"
				:label="t('Search from this many characters')"
				@change="save({ search_min_term: Number($event.target.value) })" />

			<h4>{{ t('Position in the results') }}</h4>
			<NcCheckboxRadioSwitch
				v-for="p in positions"
				:key="p.value"
				v-model="s.search_position"
				type="radio"
				:value="p.value"
				name="search-position"
				@update:modelValue="save({ search_position: $event })">
				{{ p.label }}
			</NcCheckboxRadioSwitch>
		</template>
	</div>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { onMounted, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadAdminSettings, saveAdmin } from '../../adminSettings.js'
import { useAction } from '../../composables/useAction.js'
import { t } from '../../l10n.js'
import { saveUserPrefs, userPrefs } from '../../prefs.js'

const page = loadState('glimpse', 'page', { isAdmin: false })
const s = ref(null)
const { act } = useAction()

const positions = [
	{ value: 'top', label: t('Right below Files') },
	{ value: 'low', label: t('After the other apps') },
]

const saveMine = (on) => act(() => saveUserPrefs({ search_disabled: !on }), t('Saved'))

async function save(partial) {
	const res = await act(() => saveAdmin(partial), t('Saved'))
	if (res) {
		s.value = { ...res.settings }
	}
}

onMounted(async () => {
	if (page.isAdmin) {
		s.value = { ...await act(loadAdminSettings) }
	}
})
</script>

<style scoped>
.search-settings { display: flex; flex-direction: column; gap: 4px; max-width: 560px; }
.search-settings :deep(input[type=number]) { max-width: 120px; }
h3 { margin-top: 16px; }
h4 { margin: 12px 0 0; }
</style>
