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
			<img
				v-show="!faceFailed"
				class="person-view__face"
				:src="faceUrl(id)"
				alt=""
				@error="faceFailed = true">
			<div class="person-view__title">
				<!-- The name is the heading; who may manage people edits it in place -->
				<form v-if="state.canManage" class="person-view__name" @submit.prevent="$event.target.querySelector('input').blur()">
					<input
						v-model="name"
						class="g-display"
						:aria-label="t('Name')"
						:placeholder="t('Unnamed')"
						@blur="saveName">
				</form>
				<h2 v-else class="g-display">
					{{ person?.name || t('Unnamed') }}
				</h2>
				<span v-if="person" class="person-view__count">
					{{ n('{count} picture you can see', '{count} pictures you can see', person.count, { count: person.count }) }}
					<span v-if="saved" class="person-view__saved">{{ t('Saved') }}</span>
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
			<!-- Locked, the server does not say which people are private: no toggle then. -->
			<NcButton
				v-if="person && privateState.listsKnown"
				variant="tertiary"
				:aria-label="isPrivate ? t('No longer private') : t('Mark as private')"
				:title="isPrivate ? t('No longer private') : t('Mark as private')"
				@click="togglePrivatePerson">
				<template #icon>
					<NcIconSvgWrapper :path="isPrivate ? mdiLock : mdiLockOpenVariantOutline" />
				</template>
			</NcButton>
		</header>
		<p v-if="error" class="person-view__error">
			{{ error }}
		</p>
		<!-- Private people are not found while locked (face and pictures answer 404). -->
		<NcEmptyContent
			v-if="missing"
			:name="t('Person not found')"
			:description="privateState.unlocked ? '' : t('Unlock the private area to see it.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiLock" />
			</template>
			<template v-if="!privateState.unlocked" #action>
				<NcButton variant="primary" @click="togglePrivate">
					{{ t('Show private pictures') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<SearchView v-else :person="id">
			<!-- In the filter row next to photos/videos, not a row of its own -->
			<template #filters>
				<NcButton v-if="state.canManage && person" @click="toggleHidden">
					<template #icon>
						<NcIconSvgWrapper :path="person.hidden ? mdiEyeOutline : mdiEyeOffOutline" />
					</template>
					{{ person.hidden ? t('Show again') : t('Hide') }}
				</NcButton>
			</template>
		</SearchView>
	</div>
</template>

<script setup>
import { mdiArrowLeft, mdiEyeOffOutline, mdiEyeOutline, mdiLock, mdiLockOpenVariantOutline, mdiStar, mdiStarOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import SearchView from './SearchView.vue'
import { errorMessage, faceUrl, updatePerson } from '../api.js'
import { n, t } from '../l10n.js'
import { patchPerson, peopleState as state } from '../people.js'
import { privateState, setPrivateLists, togglePrivate } from '../private.js'
import { href, navigate } from '../router.js'

const props = defineProps({
	id: { type: String, required: true },
})

const person = computed(() => state.people.find((p) => p.id === props.id))
const faceFailed = ref(false)
/**
 * Not in the people list and no face (404): private while locked, or gone. Unnamed people
 * with few pictures are not in the list either, but their face loads.
 */
const missing = computed(() => faceFailed.value && !person.value)
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
	// One row: back, face, name, star, lock – the name takes what is left.
	&__header {
		display: flex;
		align-items: center;
		gap: 8px 16px;
		margin: 4px 0 18px;
	}

	&__face {
		width: 112px;
		height: 112px;
		border-radius: 50%;
		object-fit: cover;
		background: var(--g-raised);
	}

	&__title {
		display: flex;
		flex: 1 1 0;
		min-width: 0;
		flex-direction: column;
		gap: 6px;

		h2 {
			margin: 0;
			font-size: clamp(40px, 5vw, 60px);
		}
	}

	&__name input {
		width: 100%;
		max-width: 640px;
		height: auto;
		margin: 0;
		padding: 2px 6px;
		margin-inline-start: -6px;
		border: 1px solid transparent !important;
		border-radius: 10px;
		background: transparent !important;
		box-shadow: none !important;
		color: var(--g-text);
		font-size: clamp(40px, 5vw, 60px);
		caret-color: var(--g-accent);

		&::placeholder { color: var(--g-muted); }

		&:hover { border-color: var(--g-line) !important; }

		&:focus {
			border-color: var(--g-lens-blue) !important;
			outline: none;
		}
	}

	&__count { color: var(--g-muted); }
	&__saved { margin-inline-start: 8px; color: var(--g-accent-text); font-weight: 600; }
	&__error { color: var(--color-error-text); }

	@media (max-width: 600px) {
		&__header {
			flex-wrap: wrap;
			margin-inline: 12px;
		}

		&__face {
			width: 72px;
			height: 72px;
		}

		&__title h2, &__name input {
			font-size: 36px;
		}
	}
}
</style>
