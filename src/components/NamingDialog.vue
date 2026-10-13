<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Label people in a row: the unnamed people with the most pictures, one after another, each
  with three faces from different pictures. Type a name to name them; pick someone known to
  merge them into that person.
-->
<template>
	<NcDialog :name="t('Label people')" size="normal" @closing="$emit('close')">
		<div v-if="person" class="naming">
			<div class="naming__faces">
				<img
					v-for="a in samples"
					:key="a"
					:src="faceUrl(person.id, a)"
					alt=""
					class="naming__face">
			</div>
			<p class="naming__count">
				{{ n('{count} picture', '{count} pictures', person.count, { count: person.count }) }}
				· {{ t('{index} of {total}', { index: index + 1, total: queue.length }) }}
			</p>
			<NcSelect
				v-model="choice"
				class="naming__select"
				:options="named"
				:taggable="true"
				:inputLabel="t('Name')"
				:placeholder="t('Type a name or pick someone')"
				label="name"
				@keydown.enter.stop />
			<p v-if="choice && choice.id" class="hint">
				{{ t('Will be merged into {name}.', { name: choice.name }) }}
			</p>
			<div class="naming__buttons">
				<NcButton variant="tertiary" :disabled="busy" @click="hide">
					{{ t('Hide') }}
				</NcButton>
				<NcButton :disabled="busy" @click="next">
					{{ t('Skip') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !choice" @click="save">
					{{ t('Save and next') }}
				</NcButton>
			</div>
		</div>
		<p v-else class="hint">
			{{ t('Everyone with enough pictures has a name. Well done!') }}
		</p>
	</NcDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { faceUrl, mergePeople, personSamples, updatePerson } from '../api.js'
import { useAction } from '../composables/useAction.js'
import { n, t } from '../l10n.js'
import { patchPerson, peopleState, reloadPeople } from '../people.js'

defineEmits(['close'])

// Fixed when the dialog opens, so naming someone does not reshuffle the queue.
const queue = peopleState.people.filter((p) => !p.name && !p.hidden).slice(0, 200)
const index = ref(0)
const choice = ref(null)
const { busy, act } = useAction()

const person = computed(() => queue[index.value] ?? null)
/** Asset ids of three pictures of the current person; '' = the default face until loaded */
const samples = ref([''])

watch(person, async (p) => {
	samples.value = ['']
	if (p) {
		try {
			const ids = await personSamples(p.id)
			if (person.value === p && ids.length) {
				samples.value = ids
			}
		} catch {
			// keep the default face
		}
	}
}, { immediate: true })
const named = computed(() => peopleState.people.filter((p) => p.name).map((p) => ({ id: p.id, name: p.name })))

function next() {
	choice.value = null
	index.value++
}

async function save() {
	const c = choice.value
	const p = person.value
	const ok = typeof c === 'object' && c?.id
		? await act(() => mergePeople(c.id, [p.id]))
		: await act(() => updatePerson(p.id, { name: typeof c === 'string' ? c : c.name }))
	if (ok) {
		if (typeof c === 'object' && c?.id) {
			peopleState.people = peopleState.people.filter((x) => x.id !== p.id)
		} else {
			patchPerson(p.id, { name: typeof c === 'string' ? c : c.name })
		}
		next()
		if (!person.value) {
			reloadPeople()
		}
	}
}

async function hide() {
	if (await act(() => updatePerson(person.value.id, { hidden: true }))) {
		patchPerson(person.value.id, { hidden: true })
		next()
	}
}
</script>

<style scoped lang="scss">
.naming {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;

	&__faces {
		display: flex;
		gap: 10px;
		justify-content: center;
		width: 100%;
	}

	&__face {
		width: min(30%, 160px);
		aspect-ratio: 1;
		height: auto;
		border-radius: 50%;
		object-fit: cover;
	}

	&__count { color: var(--color-text-maxcontrast); }

	&__select { width: min(100%, 360px); }

	&__buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		justify-content: center;
		margin-top: 8px;
	}
}

.hint { color: var(--color-text-maxcontrast); text-align: center; }
</style>
