<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Grid of search hits with multi-select.
    mouse:  checkbox on hover; once something is selected, a click toggles; shift-click selects a range
    touch:  long-press selects; once something is selected, a tap toggles
  Otherwise a click opens the file (event "open").
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
				<span v-if="item.type === 'VIDEO'" class="tile__play" aria-hidden="true">
					<NcIconSvgWrapper :path="mdiPlay" :size="28" />
				</span>
			</button>
			<button
				class="tile__check"
				type="button"
				:aria-label="selected.has(item.fileId) ? t('Deselect') : t('Select')"
				@click.stop="toggle(item, $event.shiftKey)">
				<NcIconSvgWrapper :path="selected.has(item.fileId) ? mdiCheckCircle : mdiCheckboxBlankCircleOutline" :size="24" />
			</button>
			<a
				class="tile__folder"
				:href="fileUrl(item.fileId)"
				:title="t('Show in folder')"
				:aria-label="t('Show in folder')"
				@click.stop>
				<NcIconSvgWrapper :path="mdiFolderOutline" :size="18" />
			</a>
			<button
				v-if="item.id"
				class="tile__similar"
				type="button"
				:title="t('Similar pictures')"
				:aria-label="t('Similar pictures')"
				@click.stop="emit('similar', item)">
				<NcIconSvgWrapper :path="mdiImageMultipleOutline" :size="18" />
			</button>
		</div>
	</div>
</template>

<script setup>
import { mdiCheckboxBlankCircleOutline, mdiCheckCircle, mdiFolderOutline, mdiImageMultipleOutline, mdiPlay } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { fileUrl, previewUrl, thumbnailUrl } from '../api.js'
import { t } from '../l10n.js'
import { prefs } from '../prefs.js'

const props = defineProps({
	items: { type: Array, required: true },
	/** Set of selected file ids; the parent owns it (and the selection rules). */
	selected: { type: Set, required: true },
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
const thumb = (item) => (item.id ? thumbnailUrl(item.id) : previewUrl(item.fileId))

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
	if (selecting.value || event.shiftKey || event.ctrlKey || event.metaKey) {
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
	gap: 4px;

	&--small { --tile: 110px; }
	&--large { --tile: 260px; }
}

@media (max-width: 600px) {
	.grid { --tile: 96px; gap: 2px; }
	.grid--small { --tile: 80px; }
	.grid--large { --tile: 160px; }
}

.tile {
	position: relative;
	aspect-ratio: 1;
	border-radius: var(--border-radius-small, 4px);
	overflow: hidden;
	background: var(--color-background-dark);
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
			transition: transform 0.12s ease;
		}
	}

	&__play {
		position: absolute;
		inset: 50% auto auto 50%;
		transform: translate(-50%, -50%);
		display: flex;
		padding: 6px;
		border-radius: 50%;
		background: rgba(0, 0, 0, 0.5);
		color: #fff;
		pointer-events: none;
	}

	&__check, &__folder, &__similar {
		position: absolute;
		display: flex;
		align-items: center;
		justify-content: center;
		margin: 0;
		padding: 0;
		border: 0;
		border-radius: 50%;
		opacity: 0;
		transition: opacity 0.12s ease;
		cursor: pointer;
	}

	&__check {
		top: 4px;
		left: 4px;
		width: 34px;
		height: 34px;
		min-height: 0;
		background: transparent;
		color: #fff;
		filter: drop-shadow(0 0 2px rgba(0, 0, 0, 0.7));
	}

	&__folder, &__similar {
		top: 6px;
		right: 6px;
		width: 30px;
		height: 30px;
		background: rgba(255, 255, 255, 0.85);
		color: #222;
	}

	&__similar {
		right: 42px;
	}

	&:hover, &:focus-within {
		.tile__check, .tile__folder, .tile__similar { opacity: 1; }
	}

	&--selected {
		.tile__open img { transform: scale(0.88); border-radius: 6px; }
		.tile__check { opacity: 1; color: var(--color-primary-element); filter: drop-shadow(0 0 1px #fff); }
	}
}

// Touch screens have no hover: checkboxes show while selecting; "show in folder"
// moves to the selection bar (one picture selected) instead of cluttering every tile.
@media (hover: none) {
	.tile__folder, .tile__similar { display: none; }
	.grid:has(.tile--selected) .tile__check { opacity: 1; }
}
</style>
