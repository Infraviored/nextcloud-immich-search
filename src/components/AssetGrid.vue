<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Grid of search hits with multi-select.
    mouse:  checkbox on hover; once something is selected, a click toggles; shift-click selects a range
    touch:  long-press selects; once something is selected, a tap toggles
  Otherwise a click opens the file (event "open"). With clickOpens (training the sensitive
  filter) a click always opens, also while selecting; shift-click, ctrl-click and the circle
  in the corner select.
-->
<template>
	<div class="grid" :class="'grid--' + prefs.tileSize" role="list">
		<div
			v-for="item in items"
			:key="item.fileId"
			class="tile"
			:class="{ 'tile--selected': selected.has(item.fileId) }"
			role="listitem">
			<button
				class="tile__open"
				type="button"
				:title="tooltip(item)"
				:aria-label="t('Open {name}', { name: item.name })"
				:aria-pressed="selecting ? selected.has(item.fileId) : undefined"
				@click="onClick(item, $event)"
				@contextmenu="onContextMenu($event)"
				@touchstart.passive="onTouchStart(item, $event)"
				@touchmove.passive="onTouchMove($event)"
				@touchend="onTouchEnd($event)"
				@touchcancel="onTouchCancel">
				<img
					:src="thumb(item)"
					alt=""
					loading="lazy"
					decoding="async">
				<span
					v-if="item.type === 'VIDEO'"
					class="tile__play"
					:class="{ 'tile__play--at': item.at }"
					aria-hidden="true">
					<NcIconSvgWrapper :path="mdiPlay" :size="18" />
					<span v-if="item.at" class="tile__at">{{ clock(item.at) }}</span>
				</span>
				<span
					v-if="item.sensitive && sensitiveBadge"
					class="tile__sensitive"
					:title="t('Sensitive')">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="sensitiveIcon" /></svg>
					<span class="hidden-visually">{{ t('Sensitive') }}</span>
				</span>
			</button>
			<button
				class="tile__check"
				type="button"
				:aria-label="selected.has(item.fileId) ? t('Deselect') : t('Select')"
				@click.stop="toggle(item, $event.shiftKey)">
				<NcIconSvgWrapper :path="selected.has(item.fileId) ? mdiCheckCircle : mdiCheckboxBlankCircleOutline" :size="22" />
			</button>
			<div v-if="!clickOpens" class="tile__tools">
				<a
					class="tile__tool"
					:href="fileUrl(item.fileId)"
					:title="t('Show in folder')"
					:aria-label="t('Show in folder')"
					@click.stop>
					<NcIconSvgWrapper :path="mdiFolderOutline" :size="18" />
				</a>
				<button
					v-if="item.id"
					class="tile__tool"
					type="button"
					:title="t('Similar pictures')"
					:aria-label="t('Similar pictures')"
					@click.stop="emit('similar', item)">
					<NcIconSvgWrapper :path="mdiImageMultipleOutline" :size="18" />
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { mdiCheckboxBlankCircleOutline, mdiCheckCircle, mdiFolderOutline, mdiImageMultipleOutline, mdiPlay } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { fileUrl, previewUrl, thumbnailUrl } from '../api.js'
import { t } from '../l10n.js'
import { sensitiveIcon } from '../materialIcons.js'
import { prefs } from '../prefs.js'
import { sensitiveBadge } from '../sensitive.js'

const props = defineProps({
	items: { type: Array, required: true },
	/** Set of selected file ids; the parent owns it (and the selection rules). */
	selected: { type: Set, required: true },
	/** A click always opens, also while selecting; shift-, ctrl-click and the corner select */
	clickOpens: { type: Boolean, default: false },
})

/**
 * toggle: (item, range) select/deselect, range = shift held; the parent resolves ranges
 *         over all grids of the view
 */
const emit = defineEmits(['open', 'similar', 'toggle', 'update:selected'])

const LONG_PRESS_MS = 450
/** A finger that moves further than this is scrolling, not pressing. */
const MOVE_TOLERANCE_PX = 10

/**
 * Immich thumbnail when the picture is indexed, Nextcloud's own preview otherwise (folders).
 *
 * @param item
 */
const thumb = (item) => (item.id ? thumbnailUrl(item.thumbId || item.id) : previewUrl(item.fileId))

/**
 * "1:23": where in a video the search found it.
 *
 * @param {number} s seconds
 */
function clock(s) {
	const t = Math.floor(s)
	return t >= 3600
		? `${Math.floor(t / 3600)}:${String(Math.floor(t / 60) % 60).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
		: `${Math.floor(t / 60)}:${String(t % 60).padStart(2, '0')}`
}

const selecting = computed(() => props.selected.size > 0)
let pressTimer = null
let pressed = false
/** A finger is down on a tile (the context menu must not open meanwhile). */
let touching = false
let startX = 0
let startY = 0

function tooltip(item) {
	const date = item.date ? new Date(item.date).toLocaleDateString() : ''
	return [date, item.path].filter(Boolean).join('\n')
}

function toggle(item, range) {
	emit('toggle', item, range)
}

function onClick(item, event) {
	if (pressed) {
		pressed = false // the long-press already selected it
		return
	}
	if (event.shiftKey || event.ctrlKey || event.metaKey || (selecting.value && !props.clickOpens)) {
		toggle(item, event.shiftKey)
	} else {
		emit('open', item)
	}
}

function onTouchStart(item, event) {
	cancelPress() // a second finger must not leave the first timer running
	pressed = false
	touching = true
	if (event.touches.length > 1) {
		return // pinch or two-finger gesture, no long-press
	}
	startX = event.touches[0].clientX
	startY = event.touches[0].clientY
	pressTimer = window.setTimeout(() => {
		pressTimer = null
		pressed = true
		toggle(item, false)
		window.navigator.vibrate?.(20)
	}, LONG_PRESS_MS)
}

/**
 * Fingers always wobble a little; only a real move (scrolling) cancels the long-press.
 *
 * @param event
 */
function onTouchMove(event) {
	const touch = event.touches[0]
	if (pressTimer && touch && Math.hypot(touch.clientX - startX, touch.clientY - startY) > MOVE_TOLERANCE_PX) {
		cancelPress()
	}
}

function cancelPress() {
	window.clearTimeout(pressTimer)
	pressTimer = null
}

function onTouchEnd(event) {
	cancelPress()
	if (event.touches.length === 0) {
		touching = false
	}
	if (pressed) {
		event.preventDefault() // no click, no context menu after a long-press
	}
}

function onTouchCancel() {
	cancelPress()
	touching = false
}

function onContextMenu(event) {
	// Android opens the context menu while the finger is still down, before the long-press fired.
	if (pressed || touching) {
		event.preventDefault()
	}
}

function onKey(event) {
	if (event.key === 'Escape' && selecting.value && !document.querySelector('.viewer, .modal-mask')) {
		emit('update:selected', new Set())
	}
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<style scoped lang="scss">
.grid {
	--tile: 170px;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(var(--tile), 1fr));
	gap: 3px;

	&--small { --tile: 110px; }
	&--large { --tile: 260px; }
}

// Phones: edge to edge, as in the app
@media (max-width: 600px) {
	.grid { --tile: 96px; gap: 3px; }
	.grid--small { --tile: 76px; }
	.grid--large { --tile: 150px; }
}

.tile {
	position: relative;
	aspect-ratio: 1;
	overflow: hidden;
	background: var(--g-raised);
	-webkit-touch-callout: none;
	user-select: none;

	&__open {
		display: block;
		width: 100%;
		height: 100%;
		margin: 0;
		padding: 0;
		border: 0;
		border-radius: 0;
		background: none;
		cursor: pointer;

		img {
			display: block;
			width: 100%;
			height: 100%;
			object-fit: cover;
			transition: transform 0.14s ease, border-radius 0.14s ease;
		}

		&:focus-visible {
			outline: 3px solid var(--g-lens-blue);
			outline-offset: -3px;
		}
	}

	&__play {
		position: absolute;
		top: 8px;
		right: 8px;
		display: flex;
		padding: 3px;
		border-radius: 50%;
		background: rgba(20, 22, 43, 0.6);
		color: #fff;
		pointer-events: none;

		:deep(svg) {
			width: 18px;
			height: 18px;
		}
	}

	// Found inside the video: the mark says where
	&__play--at {
		align-items: center;
		padding: 3px 9px 3px 4px;
		border-radius: 14px;
		background: rgba(20, 22, 43, 0.78);
	}

	&__at {
		margin-inline-start: 3px;
		font-size: 12px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
	}

	// Counted as sensitive (only when the user wants to see it): a small light mark in the
	// corner nothing else uses, no chip around it
	&__sensitive {
		position: absolute;
		bottom: 5px;
		left: 5px;
		display: flex;
		color: rgba(255, 255, 255, 0.8);
		filter: drop-shadow(0 0 1.5px rgba(0, 0, 0, 0.8));
		pointer-events: none;

		svg {
			width: 13px;
			height: 13px;
			fill: currentColor;
		}
	}

	// Nextcloud styles every button (padding, min sizes, margins): reset them all here
	&__check, &__tool {
		display: flex;
		align-items: center;
		justify-content: center;
		box-sizing: border-box;
		margin: 0;
		padding: 0;
		min-width: 0;
		min-height: 0;
		border: 0;
		border-radius: 50%;
		color: #fff;
		cursor: pointer;
	}

	&__check {
		position: absolute;
		top: 6px;
		left: 6px;
		width: 28px;
		height: 28px;
		background: rgba(20, 22, 43, 0.45);
		opacity: 0;
		transition: opacity 0.12s ease;

		&:hover, &:focus-visible {
			background: rgba(20, 22, 43, 0.7);
		}
	}

	// One quiet pill in the other corner, the buttons inside it
	&__tools {
		position: absolute;
		top: 6px;
		right: 6px;
		display: flex;
		gap: 2px;
		padding: 2px;
		border-radius: 999px;
		background: rgba(20, 22, 43, 0.6);
		opacity: 0;
		transition: opacity 0.12s ease;
	}

	&__tool {
		width: 28px;
		height: 28px;
		background: none;

		&:hover, &:focus-visible {
			background: rgba(255, 255, 255, 0.16);
		}
	}

	// The tools take the corner the video mark sits in
	&:hover .tile__play, &:focus-within .tile__play {
		opacity: 0;
	}

	&:hover, &:focus-within {
		.tile__check, .tile__tools { opacity: 1; }
	}

	&--selected {
		background: var(--g-bg);

		.tile__open img {
			transform: scale(0.86);
			border-radius: 10px;
		}

		.tile__check, .tile__check:hover {
			opacity: 1;
			background: #fff;
			color: var(--g-accent);
		}
	}
}

// Touch screens have no hover: checkboxes show while selecting; "show in folder"
// moves to the selection bar (one picture selected) instead of cluttering every tile.
@media (hover: none) {
	.tile__tools { display: none; }
	.tile:hover .tile__play { opacity: 1; }
	.grid:has(.tile--selected) .tile__check { opacity: 1; }
}
</style>
