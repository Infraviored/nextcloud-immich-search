<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog
		:name="t('Merge people')"
		size="normal"
		:buttons="buttons"
		@closing="$emit('close')">
		<p>{{ t('Which one stays? The others are merged into it, with all their pictures. This cannot be undone.') }}</p>
		<div class="merge__list">
			<NcCheckboxRadioSwitch
				v-for="p in people"
				:key="p.id"
				v-model="targetId"
				type="radio"
				:value="p.id"
				name="merge-target">
				<span class="merge__option">
					<img :src="faceUrl(p.id)" alt="">
					{{ p.name || t('Unnamed') }} · {{ n('{count} picture', '{count} pictures', p.count, { count: p.count }) }}
				</span>
			</NcCheckboxRadioSwitch>
		</div>
		<NcTextField v-model="name" :label="t('Name')" />
		<p v-if="error" class="merge__error">
			{{ error }}
		</p>
	</NcDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, faceUrl, mergePeople, updatePerson } from '../api.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	people: { type: Array, required: true },
})
const emit = defineEmits(['close', 'merged'])

// Default: the named one with the most pictures.
const best = [...props.people].sort((a, b) => (!!b.name - !!a.name) || b.count - a.count)[0]
const targetId = ref(best.id)
const name = ref(best.name)
const busy = ref(false)
const error = ref('')

watch(targetId, (id) => {
	const p = props.people.find((x) => x.id === id)
	if (p?.name) {
		name.value = p.name
	}
})

const buttons = computed(() => [
	{ label: t('Cancel'), callback: () => emit('close') },
	{ label: t('Merge'), variant: 'primary', disabled: busy.value, callback: () => {
		merge()
		return false
	} },
])

async function merge() {
	busy.value = true
	error.value = ''
	try {
		const others = props.people.map((p) => p.id).filter((id) => id !== targetId.value)
		await mergePeople(targetId.value, others)
		const target = props.people.find((p) => p.id === targetId.value)
		if (name.value.trim() !== (target?.name ?? '')) {
			await updatePerson(targetId.value, { name: name.value.trim() })
		}
		emit('merged', targetId.value)
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.merge__list { margin: 8px 0 12px; }
.merge__option {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
}
.merge__error { color: var(--color-error-text); }
</style>
