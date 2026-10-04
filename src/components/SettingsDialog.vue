<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppSettingsDialog
		:open="open"
		:name="t('Photo search settings')"
		showNavigation
		@update:open="$emit('update:open', $event)">
		<NcAppSettingsSection id="display" :name="t('Display')">
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
		</NcAppSettingsSection>
		<NcAppSettingsSection id="people-index" :name="t('People')">
			<p>{{ t('Which people you see is worked out hourly in the background, from the pictures you can open.') }}</p>
			<p class="hint">
				{{ updated }}
			</p>
		</NcAppSettingsSection>
		<NcAppSettingsSection v-if="isAdmin" id="connection" :name="t('Connection (admin)')">
			<AdminForm />
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script setup>
import { computed } from 'vue'
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import AdminForm from './AdminForm.vue'
import { t } from '../l10n.js'
import { peopleState } from '../people.js'
import { prefs } from '../prefs.js'

defineProps({
	open: { type: Boolean, default: false },
	isAdmin: { type: Boolean, default: false },
})
defineEmits(['update:open'])

const sizes = [
	{ value: 'small', label: t('Small tiles') },
	{ value: 'medium', label: t('Medium tiles') },
	{ value: 'large', label: t('Large tiles') },
]

const updated = computed(() => peopleState.updated
	? t('Last updated: {time}', { time: new Date(peopleState.updated * 1000).toLocaleString() })
	: t('Not built yet.'))
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
</style>
