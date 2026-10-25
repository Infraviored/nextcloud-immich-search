<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- The search pill: the lens, one line of text, a clear button. Enter searches. -->
<template>
	<form class="lens-search" role="search" @submit.prevent="$emit('submit')">
		<LensMark :size="22" />
		<input
			ref="input"
			:value="modelValue"
			class="lens-search__input"
			type="search"
			enterkeyhint="search"
			:placeholder="placeholder"
			:aria-label="label || placeholder"
			@focus="warmUp"
			@input="$emit('update:modelValue', $event.target.value)"
			@blur="$emit('blur')">
		<button
			v-if="modelValue"
			class="lens-search__clear"
			type="button"
			:aria-label="t('Clear')"
			@click="$emit('update:modelValue', ''); $emit('clear'); input.focus()">
			<NcIconSvgWrapper :path="mdiClose" :size="18" />
		</button>
	</form>
</template>

<script setup>
import { mdiClose } from '@mdi/js'
import { ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import LensMark from './LensMark.vue'
import { warmUp } from '../api.js'
import { t } from '../l10n.js'

defineProps({
	modelValue: { type: String, default: '' },
	placeholder: { type: String, default: '' },
	label: { type: String, default: '' },
})

defineEmits(['update:modelValue', 'submit', 'clear', 'blur'])

const input = ref(null)

defineExpose({ focus: () => input.value?.focus() })
</script>

<style scoped lang="scss">
.lens-search {
	display: flex;
	align-items: center;
	gap: 12px;
	height: 48px;
	padding: 0 8px 0 16px;
	border: 1px solid var(--g-line);
	border-radius: 24px;
	background: var(--g-raised);
	transition: border-color 0.15s ease;

	&:focus-within {
		border-color: var(--g-lens-blue);
	}

	&__input {
		flex: 1;
		min-width: 0;
		height: 100%;
		margin: 0;
		padding: 0;
		border: 0 !important;
		outline: none;
		background: transparent !important;
		box-shadow: none !important;
		color: var(--g-text);
		font-size: 16px;
		caret-color: var(--g-accent);

		&::placeholder {
			color: var(--g-muted);
		}

		&::-webkit-search-cancel-button {
			display: none;
		}
	}

	&__clear {
		display: flex;
		flex: 0 0 auto;
		align-self: center;
		align-items: center;
		justify-content: center;
		width: 34px;
		height: 34px;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: 0;
		border-radius: 50%;
		background: transparent;
		color: var(--g-muted);
		line-height: 0;
		cursor: pointer;

		:deep(.icon-vue) {
			display: flex;
		}

		&:hover, &:focus-visible {
			background: var(--g-line);
			color: var(--g-text);
		}
	}
}
</style>
