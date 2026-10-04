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
				<!-- Name and birthday in one row, so the two fields line up -->
				<div v-if="state.canManage" class="person-view__fields">
					<form class="person-view__name" @submit.prevent="saveName">
						<NcTextField
							v-model="name"
							:label="t('Name')"
							:placeholder="t('Name')"
							:success="saved"
							@blur="saveName" />
					</form>
					<NcTextField
						v-if="person"
						v-model="birthday"
						class="person-view__birthday"
						type="date"
						:label="t('Birthday')"
						:max="today"
						@blur="saveBirthday(birthday)" />
				</div>
				<h2 v-else>
					{{ person?.name || t('Unnamed') }}
				</h2>
				<span v-if="person" class="person-view__count">
					{{ n('{count} picture you can see', '{count} pictures you can see', person.count, { count: person.count }) }}
					<template v-if="age !== null"> · {{ n('{count} year old', '{count} years old', age, { count: age }) }}</template>
				</span>
			</div>
			<NcButton
				v-if="state.canManage && person"
				variant="tertiary"
				:aria-label="person.favorite ? t('Remove from favorites') : t('Add to favorites')"
				:title="person.favorite ? t('Remove from favorites') : t('Add to favorites')"
				@click="toggleFavorite">
				<template #icon>
					<NcIconSvgWrapper :path="person.favorite ? mdiStar : mdiStarOutline" />
				</template>
			</NcButton>
			<NcButton
				v-if="person"
				variant="tertiary"
				:aria-label="isPrivate ? t('No longer private') : t('Mark as private')"
				:title="isPrivate ? t('No longer private') : t('Mark as private')"
				@click="togglePrivatePerson">
				<template #icon>
					<NcIconSvgWrapper :path="isPrivate ? mdiLock : mdiLockOpenVariantOutline" />
				</template>
			</NcButton>
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
import { mdiArrowLeft, mdiEyeOffOutline, mdiEyeOutline, mdiLock, mdiLockOpenVariantOutline, mdiStar, mdiStarOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SearchView from './SearchView.vue'
import { errorMessage, faceUrl, updatePerson } from '../api.js'
import { n, t } from '../l10n.js'
import { patchPerson, peopleState as state } from '../people.js'
import { privateState, setPrivateLists } from '../private.js'
import { href, navigate } from '../router.js'

const props = defineProps({
	id: { type: String, required: true },
})

const person = computed(() => state.people.find((p) => p.id === props.id))
const name = ref(person.value?.name ?? '')
const saved = ref(false)
const birthday = ref(person.value?.birthDate ?? '')
const error = ref('')

watch(person, (p) => {
	if (p && !saved.value) {
		name.value = p.name
	}
	if (p) {
		birthday.value = p.birthDate ?? ''
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

const today = new Date().toISOString().slice(0, 10)
const age = computed(() => {
	const b = person.value?.birthDate
	if (!b) {
		return null
	}
	const d = new Date(b)
	const now = new Date()
	return now.getFullYear() - d.getFullYear() - (now < new Date(now.getFullYear(), d.getMonth(), d.getDate()) ? 1 : 0)
})

const isPrivate = computed(() => privateState.people.includes(props.id))

/** Private people: their pictures only show when the private area is unlocked. */
async function togglePrivatePerson() {
	const people = isPrivate.value ? privateState.people.filter((x) => x !== props.id) : [...privateState.people, props.id]
	try {
		await setPrivateLists(privateState.folders, people)
	} catch (e) {
		error.value = errorMessage(e)
	}
}

async function toggleFavorite() {
	try {
		const res = await updatePerson(props.id, { favorite: !person.value.favorite })
		patchPerson(props.id, { favorite: res.favorite })
	} catch (e) {
		error.value = errorMessage(e)
	}
}

async function saveBirthday(value) {
	if ((value || null) === (person.value?.birthDate ?? null)) {
		return
	}
	try {
		const res = await updatePerson(props.id, { birthDate: value })
		patchPerson(props.id, { birthDate: res.birthDate })
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

		// Fields of one height, side by side
		:deep(.input-field) {
			margin-block: 0;
		}
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

	&__fields {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: center;

		:deep(.input-field) {
			margin-block: 0;
		}
	}

	&__name {
		flex: 1 1 200px;
	}

	&__birthday {
		flex: 0 0 170px;
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
