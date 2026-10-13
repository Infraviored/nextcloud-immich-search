<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="people" :class="{ 'people--choosing': selected.length }">
		<h2 class="people__title g-display">
			{{ t('People') }}
		</h2>
		<div class="people__bar">
			<NcTextField
				v-model="filter"
				class="people__filter"
				:label="t('Find person')"
				trailingButtonIcon="close"
				:showTrailingButton="filter !== ''"
				@trailingButtonClick="filter = ''">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMagnify" :size="20" />
				</template>
			</NcTextField>
			<NcCheckboxRadioSwitch v-model="showHidden" type="switch">
				{{ t('Show hidden') }}
			</NcCheckboxRadioSwitch>
			<NcButton v-if="state.canManage && unnamedAll.length" variant="primary" @click="naming = true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAccountQuestionOutline" />
				</template>
				{{ t('Label people') }}
			</NcButton>
		</div>
		<NamingDialog v-if="naming" @close="naming = false" />

		<div v-if="selected.length" class="people__selection" role="toolbar">
			<NcButton variant="tertiary" :aria-label="t('Clear selection')" @click="selected = []">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClose" />
				</template>
			</NcButton>
			<strong>{{ n('{count} selected', '{count} selected', selected.length, { count: selected.length }) }}</strong>
			<span class="people__spacer" />
			<NcButton :disabled="selected.length < 2" variant="primary" @click="mergeOpen = true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCallMerge" />
				</template>
				{{ t('Merge') }}
			</NcButton>
		</div>
		<p v-else-if="state.canManage && visible.length" class="people__hint">
			{{ t('Same person twice? Select both (round button) and merge them.') }}
		</p>

		<NcEmptyContent
			v-if="!state.loading && !visible.length"
			:name="state.error ? t('Could not load people') : t('No people yet')"
			:description="state.error || t('People appear here once Immich has recognized faces in pictures you can open. The list is updated hourly.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAccountMultipleOutline" />
			</template>
		</NcEmptyContent>

		<template v-for="group in groups" :key="group.key">
			<h3 v-if="group.items.length" class="people__heading g-heading">
				{{ group.title }}
			</h3>
			<div class="people__grid">
				<PersonCard
					v-for="p in group.items"
					:key="p.id"
					:person="p"
					:href="href('/people/' + p.id)"
					:selectable="state.canManage"
					:selected="selected.includes(p.id)"
					:selecting="selected.length > 0"
					@open="navigate('/people/' + p.id)"
					@toggle="toggle(p.id)" />
			</div>
		</template>
		<div v-if="unnamedLimit < unnamedAll.length" class="people__more">
			<NcButton @click="unnamedLimit += 120">
				{{ t('Show more') }}
			</NcButton>
		</div>

		<MergeDialog
			v-if="mergeOpen"
			:people="selectedPeople"
			@close="mergeOpen = false"
			@merged="onMerged" />
	</div>
</template>

<script setup>
import { mdiAccountMultipleOutline, mdiAccountQuestionOutline, mdiCallMerge, mdiClose, mdiMagnify } from '@mdi/js'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MergeDialog from '../components/MergeDialog.vue'
import NamingDialog from '../components/NamingDialog.vue'
import PersonCard from '../components/PersonCard.vue'
import { n, t } from '../l10n.js'
import { reloadPeople, peopleState as state } from '../people.js'
import { href, navigate } from '../router.js'

const filter = ref('')
const showHidden = ref(false)
const selected = ref([])
const mergeOpen = ref(false)
const naming = ref(false)
const unnamedLimit = ref(120)

const visible = computed(() => {
	const f = filter.value.trim().toLowerCase()
	return state.people.filter((p) => (showHidden.value || !p.hidden) && (!f || p.name.toLowerCase().includes(f)))
})
const unnamedAll = computed(() => (filter.value ? [] : visible.value.filter((p) => !p.name)))
const groups = computed(() => [
	{ key: 'named', title: t('Named'), items: visible.value.filter((p) => p.name) },
	{ key: 'unnamed', title: t('Not named yet'), items: unnamedAll.value.slice(0, unnamedLimit.value) },
])
const selectedPeople = computed(() => selected.value.map((id) => state.people.find((p) => p.id === id)).filter(Boolean))

function toggle(id) {
	selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]
}

async function onMerged() {
	mergeOpen.value = false
	selected.value = []
	await reloadPeople()
}
</script>

<style scoped lang="scss">
.people {
	&__title {
		margin: 8px 0 16px;
		font-size: clamp(44px, 6vw, 72px);
	}

	&__bar {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 8px 16px;
		margin-bottom: 8px;
	}

	&__filter { flex: 0 1 360px; }

	&__selection {
		position: sticky;
		top: 0;
		z-index: 10;
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 8px;
		padding: 6px 8px;
		border: 1px solid var(--g-line);
		border-radius: 28px;
		background: var(--g-raised);
	}

	&__spacer { flex: 1; }

	&__hint {
		margin: 0 0 8px;
		color: var(--color-text-maxcontrast);
	}

	&__heading {
		margin: 28px 0 10px;
		font-size: 22px;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(132px, 1fr));
		gap: 14px 6px;
	}

	&__more {
		display: flex;
		justify-content: center;
		padding: 16px 0;
	}
}

@media (max-width: 600px) {
	.people { padding-inline: 12px; }
	.people__grid { grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); }
}
</style>
