<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="person-view">
		<header class="person-view__header">
			<NcButton
				variant="tertiary"
				:href="href('/people')"
				:aria-label="t('All people')"
				@click.prevent="navigate('/people')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowLeft" />
				</template>
			</NcButton>
			<img class="person-view__face" :src="faceUrl(id)" alt="">
			<div class="person-view__title">
				<form v-if="state.canManage" @submit.prevent="saveName">
					<NcTextField
						v-model="name"
						:label="t('Name')"
						:placeholder="t('Who is this?')"
						:success="saved"
						@blur="saveName" />
				</form>
				<h2 v-else>
					{{ person?.name || t('Unnamed') }}
				</h2>
				<span v-if="person" class="person-view__count">
					{{ n('{count} picture you can see', '{count} pictures you can see', person.count, { count: person.count }) }}
				</span>
			</div>
			<NcButton v-if="state.canManage && person" @click="toggleHidden">
				<template #icon>
					<NcIconSvgWrapper :path="person.hidden ? mdiEyeOutline : mdiEyeOffOutline" />
				</template>
				{{ person.hidden ? t('Show again') : t('Hide') }}
			</NcButton>
		</header>
		<p v-if="error" class="person-view__error">
			{{ error }}
		</p>
		<SearchView :person="id" />
	</div>
</template>

<script setup>
import { mdiArrowLeft, mdiEyeOffOutline, mdiEyeOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SearchView from './SearchView.vue'
import { errorMessage, faceUrl, updatePerson } from '../api.js'
import { n, t } from '../l10n.js'
import { patchPerson, peopleState as state } from '../people.js'
import { href, navigate } from '../router.js'

const props = defineProps({
	id: { type: String, required: true },
})

const person = computed(() => state.people.find((p) => p.id === props.id))
const name = ref(person.value?.name ?? '')
const saved = ref(false)
const error = ref('')

watch(person, (p) => {
	if (p && !saved.value) {
		name.value = p.name
	}
})

async function saveName() {
	const value = name.value.trim()
	if (!person.value || value === person.value.name) {
		return
	}
	try {
		const res = await updatePerson(props.id, { name: value })
		patchPerson(props.id, { name: res.name })
		saved.value = true
		error.value = ''
		window.setTimeout(() => {
			saved.value = false
		}, 2000)
	} catch (e) {
		error.value = errorMessage(e)
	}
}

async function toggleHidden() {
	try {
		const res = await updatePerson(props.id, { hidden: !person.value.hidden })
		patchPerson(props.id, { hidden: res.hidden })
	} catch (e) {
		error.value = errorMessage(e)
	}
}
</script>

<style scoped lang="scss">
.person-view {
	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin-bottom: 12px;
	}

	&__face {
		width: 72px;
		height: 72px;
		border-radius: 50%;
		object-fit: cover;
		background: var(--color-background-dark);
	}

	&__title {
		display: flex;
		flex: 1 1 220px;
		flex-direction: column;
		gap: 2px;

		h2 { margin: 0; }
	}

	&__count { color: var(--color-text-maxcontrast); }
	&__error { color: var(--color-error-text); }
}
</style>
