<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="person" :class="{ 'person--selected': selected, 'person--hidden': person.hidden }">
		<a class="person__link" :href="href" @click="onClick">
			<img
				class="person__face"
				:src="faceUrl(person.id)"
				alt=""
				loading="lazy">
			<span class="person__name" :class="{ 'person__name--unnamed': !person.name }">
				{{ person.name || t('Unnamed') }}
			</span>
			<span class="person__count">{{ n('{count} picture', '{count} pictures', person.count, { count: person.count }) }}</span>
		</a>
		<button
			v-if="selectable"
			class="person__check"
			type="button"
			:aria-label="selected ? t('Deselect') : t('Select')"
			@click="$emit('toggle')">
			<NcIconSvgWrapper :path="selected ? mdiCheckCircle : mdiCheckboxBlankCircleOutline" :size="24" />
		</button>
	</div>
</template>

<script setup>
import { mdiCheckboxBlankCircleOutline, mdiCheckCircle } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { faceUrl } from '../api.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	person: { type: Object, required: true },
	href: { type: String, required: true },
	selectable: { type: Boolean, default: false },
	selected: { type: Boolean, default: false },
	/** In selection mode a click selects instead of opening. */
	selecting: { type: Boolean, default: false },
})
const emit = defineEmits(['open', 'toggle'])

function onClick(event) {
	if (event.ctrlKey || event.metaKey || event.button !== 0) { // new tab etc.
		return
	}
	event.preventDefault()
	emit(props.selecting ? 'toggle' : 'open')
}
</script>

<style scoped lang="scss">
.person {
	position: relative;

	&__link {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 2px;
		padding: 10px 4px;
		border-radius: 18px;
		color: var(--g-text);
		text-align: center;

		&:hover, &:focus-visible { background: var(--g-raised); }
	}

	&__face {
		width: 100%;
		max-width: 128px;
		aspect-ratio: 1;
		margin-bottom: 8px;
		border-radius: 50%;
		object-fit: cover;
		background: var(--g-raised);
	}

	&__name {
		max-width: 100%;
		overflow: hidden;
		font-weight: 600;
		text-overflow: ellipsis;
		white-space: nowrap;

		&--unnamed { font-weight: normal; color: var(--color-text-maxcontrast); }
	}

	&__count {
		font-size: var(--font-size-small, 13px);
		color: var(--color-text-maxcontrast);
	}

	&__check {
		position: absolute;
		top: 6px;
		left: 6px;
		display: flex;
		margin: 0;
		padding: 4px;
		border: 0;
		border-radius: 50%;
		min-height: 0;
		background: var(--g-bg);
		color: var(--g-muted);
		cursor: pointer;
	}

	&--selected {
		.person__face { outline: 3px solid var(--g-accent-text); outline-offset: 3px; }
		.person__check { color: var(--g-accent-text); }
	}

	&--hidden .person__face { opacity: 0.45; }

	// The round select button shows on pointing at a face, and on all while choosing
	&__check { opacity: 0; transition: opacity 0.12s ease; }
	&:hover &__check, &:focus-within &__check, &--selected &__check { opacity: 1; }
}

:global(.people--choosing) .person__check { opacity: 1; }

@media (hover: none) {
	.person__check { opacity: 1; }
}
</style>
