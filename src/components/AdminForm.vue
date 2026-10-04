<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Connection and permissions; used in the admin settings and in the app's settings dialog. -->
<template>
	<form class="admin" @submit.prevent="save">
		<NcTextField
			v-model="form.url"
			:label="t('Immich address')"
			placeholder="http://immich:2283"
			type="url" />
		<NcPasswordField
			v-model="form.apiKey"
			:label="t('API key')"
			:helperText="hasKey ? t('Stored. Leave empty to keep it.') : t('Of the Immich account that owns the libraries, with permission “all”.')"
			autocomplete="off" />
		<NcTextField
			v-model="form.pathPattern"
			:label="pathLabel"
			placeholder="/mnt/nextcloud/{user}/"
			:helperText="pathHelp" />

		<NcTextField
			v-model="form.minSize"
			type="number"
			min="0"
			:label="t('Ignore pictures smaller than (pixels, shorter side)')"
			:helperText="t('Icons and tiny images match almost any search. 0 shows everything.')" />

		<fieldset class="admin__faces">
			<legend>{{ t('Who may name, merge and hide people?') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="m in faceModes"
				:key="m.value"
				v-model="form.faceMode"
				type="radio"
				:value="m.value"
				name="face-mode">
				{{ m.label }}
			</NcCheckboxRadioSwitch>
			<p class="admin__hint">
				{{ t('Everyone only ever sees people from pictures they can open. Names are shared by all users.') }}
			</p>
		</fieldset>

		<div class="admin__buttons">
			<NcButton :disabled="busy" @click="test">
				{{ t('Test connection') }}
			</NcButton>
			<NcButton variant="primary" type="submit" :disabled="busy">
				{{ t('Save') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="reindex">
				{{ t('Update people now') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="message" :type="ok ? 'success' : 'error'">
			{{ message }}
		</NcNoteCard>
	</form>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, reindexPeople, saveSettings, testSettings } from '../api.js'
import { t } from '../l10n.js'

const initial = loadState('immich_search', 'admin', { url: '', hasKey: false, pathPattern: '/mnt/nextcloud/{user}/', faceMode: 'all', minSize: 256 })
const form = reactive({ url: initial.url, apiKey: '', pathPattern: initial.pathPattern, faceMode: initial.faceMode, minSize: String(initial.minSize) })
const hasKey = ref(initial.hasKey)
const busy = ref(false)
const message = ref('')
const ok = ref(true)

// Strings with apostrophes live here, not in template attributes.
const pathLabel = t('Path of a user\'s files inside Immich')
const pathHelp = t('{user} stands for the Nextcloud user ID. Mount each user\'s files folder (in the data directory: user ID, then files) read-only there and add it to Immich as an external library.')

const faceModes = [
	{ value: 'all', label: t('Every user (people they can see)') },
	{ value: 'admins', label: t('Only admins') },
	{ value: 'off', label: t('Nobody, names come from Immich only') },
]

/**
 *
 * @param fn
 * @param success
 */
async function act(fn, success) {
	busy.value = true
	try {
		message.value = success(await fn())
		ok.value = true
	} catch (e) {
		message.value = errorMessage(e)
		ok.value = false
	} finally {
		busy.value = false
	}
}

const test = () => act(() => testSettings(form), (r) => t('Connected as {user}', { user: r.user }))
/**
 *
 */
function save() {
	return act(() => saveSettings({ ...form, minSize: Number(form.minSize) || 0 }), (r) => {
		if (form.apiKey) {
			hasKey.value = true
		}
		form.apiKey = ''
		return t('Saved, connected as {user}', { user: r.user })
	})
}
const reindex = () => act(reindexPeople, () => t('People are updated with the next background job run (usually within 5 minutes).'))
</script>

<style scoped lang="scss">
.admin {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 560px;

    &__faces {
        margin-top: 4px;

        legend { font-weight: bold; margin-bottom: 4px; }
    }

    &__hint { color: var(--color-text-maxcontrast); }

    &__buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
}
</style>
