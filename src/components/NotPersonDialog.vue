<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  "Not Tanja": where the faces of one person in the selected pictures go instead.
  Only that person's faces change; other people in the same pictures stay as they are.
-->
<template>
	<NcDialog
		:name="t('Not {name}', { name: personName })"
		:buttons="buttons"
		@closing="$emit('close')">
		<p class="not-person__intro">
			{{ n('In {count} picture, the face recognized as {name} is:', 'In {count} pictures, the face recognized as {name} is:', count, { count, name: personName }) }}
		</p>
		<div class="not-person__choices">
			<NcCheckboxRadioSwitch
				v-model="mode"
				type="radio"
				value="other"
				name="not-person">
				{{ t('Someone else') }}
			</NcCheckboxRadioSwitch>
			<NcSelect
				v-if="mode === 'other'"
				v-model="other"
				class="not-person__select"
				:options="options"
				:taggable="true"
				:inputLabel="t('Person')"
				:placeholder="t('Who is it? Pick or type a new name')"
				label="name" />
			<NcCheckboxRadioSwitch
				v-model="mode"
				type="radio"
				value="unnamed"
				name="not-person">
				{{ t('Someone I do not want to name now') }}
			</NcCheckboxRadioSwitch>
			<p v-if="mode === 'unnamed'" class="not-person__hint">
				{{ t('The faces go to one new person without a name. If they are different people, name them one by one later or pick “Someone else”.') }}
			</p>
			<NcCheckboxRadioSwitch
				v-model="mode"
				type="radio"
				value="noface"
				name="not-person">
				{{ t('Not a face at all') }}
			</NcCheckboxRadioSwitch>
			<p v-if="mode === 'noface'" class="not-person__hint">
				{{ t('The faces are removed from the face recognition (a pattern, a poster, a statue).') }}
			</p>
		</div>
	</NcDialog>
</template>

<script setup>
import { computed, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { n, t } from '../l10n.js'
import { peopleState } from '../people.js'

const props = defineProps({
	/** {id, name} of the person whose faces are wrong */
	person: { type: Object, required: true },
	count: { type: Number, required: true },
})
const emit = defineEmits(['close', 'apply'])

const mode = ref('other')
const other = ref(null)
const personName = computed(() => props.person.name || t('Unnamed'))
const options = computed(() => peopleState.people
	.filter((p) => p.name && p.id !== props.person.id)
	.map((p) => ({ id: p.id, name: p.name })))

/** The target as moveFaces() takes it, or null while nothing is chosen */
const target = computed(() => {
	if (mode.value === 'unnamed') {
		return { unnamed: true }
	}
	if (mode.value === 'noface') {
		return { notAFace: true }
	}
	const o = other.value
	if (!o) {
		return null
	}
	const name = typeof o === 'string' ? o : o.name
	return typeof o === 'string' || !o.id ? { newName: name.trim() } : { personId: o.id, name }
})

const buttons = computed(() => [
	{ label: t('Cancel'), callback: () => emit('close') },
	{
		label: t('Correct'),
		variant: 'primary',
		disabled: !target.value || target.value.newName === '',
		callback: () => emit('apply', target.value),
	},
])
</script>

<style scoped lang="scss">
.not-person {
	&__choices {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding-bottom: 8px;
	}

	&__select {
		margin-inline-start: 36px;
	}

	&__hint {
		margin-inline-start: 36px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
