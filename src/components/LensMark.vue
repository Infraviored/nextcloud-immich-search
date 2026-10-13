<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- The lens from the icon: a blue-to-violet ring with the apricot sun beside it. -->
<template>
	<svg
		class="lens-mark"
		:width="size"
		:height="size"
		viewBox="0 0 24 24"
		aria-hidden="true">
		<defs>
			<linearGradient
				:id="id"
				x1="0"
				y1="0"
				x2="1"
				y2="1">
				<stop offset="0" stop-color="#2B7DE9" />
				<stop offset="1" stop-color="#6A3FD8" />
			</linearGradient>
		</defs>
		<circle
			cx="11"
			cy="13"
			r="7.2"
			fill="none"
			:stroke="`url(#${id})`"
			stroke-width="3.2" />
		<circle
			cx="19.6"
			cy="4.6"
			r="2.2"
			fill="#FFB27A" />
	</svg>
</template>

<script setup>
defineProps({
	size: { type: Number, default: 24 },
})

const id = 'lens-' + Math.random().toString(36).slice(2, 8)
</script>

<style scoped>
.lens-mark {
	flex: 0 0 auto;
}
</style>
