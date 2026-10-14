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

	</div>
</template>

<script setup>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { useAction } from '../../composables/useAction.js'
import { t } from '../../l10n.js'
import { prefs, saveUserPrefs, userPrefs } from '../../prefs.js'

const { act } = useAction()

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
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
h3 { margin-top: 20px; }
</style>
