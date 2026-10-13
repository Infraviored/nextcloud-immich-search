<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="connection">
		<AdminForm />
		<h3>{{ t('Analysis service health check') }}</h3>
		<p class="hint">
			{{ t('Optional. Address of Immich\'s machine-learning container as Nextcloud reaches it, e.g. http://immich-machine-learning:3003. Only “/ping” is called, which loads no model.') }}
		</p>
		<div class="connection__row">
			<NcTextField v-model="ping" :label="t('Machine-learning address')" placeholder="http://immich-machine-learning:3003" />
			<NcButton @click="save">
				{{ t('Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AdminForm from '../../components/AdminForm.vue'
import { loadAdminSettings, saveAdmin } from '../../adminSettings.js'
import { useAction } from '../../composables/useAction.js'
import { t } from '../../l10n.js'

const ping = ref('')
const { act } = useAction()

const save = () => act(() => saveAdmin({ ml_ping_url: ping.value.trim() }), t('Saved'))

onMounted(async () => {
	ping.value = (await act(loadAdminSettings))?.ml_ping_url ?? ''
})
</script>

<style scoped>
.connection__row { display: flex; gap: 8px; align-items: flex-end; max-width: 560px; }
h3 { margin-top: 24px; }
.hint { color: var(--color-text-maxcontrast); }
</style>
