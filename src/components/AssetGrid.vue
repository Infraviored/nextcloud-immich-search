<!--
  Grid of search hits with multi-select.
    mouse:  checkbox on hover; once something is selected, a click toggles; shift-click selects a range
    touch:  long-press selects; once something is selected, a tap toggles
  Otherwise a click opens the file (event "open").
-->
<template>
    <div class="grid" :class="'grid--' + prefs.tileSize" role="list">
        <div v-for="(item, index) in items"
            :key="item.id"
            class="tile"
            :class="{ 'tile--selected': selected.has(item.fileId) }"
            role="listitem">
            <button class="tile__open"
                type="button"
                :title="tooltip(item)"
                :aria-label="t('Open {name}', { name: item.name })"
                :aria-pressed="selecting ? selected.has(item.fileId) : undefined"
                @click="onClick(item, index, $event)"
                @contextmenu="onContextMenu($event)"
                @touchstart.passive="onTouchStart(item, index)"
                @touchmove.passive="cancelPress"
                @touchend="onTouchEnd($event)">
                <img :src="thumbnailUrl(item.id)" alt="" loading="lazy" decoding="async">
                <span v-if="item.type === 'VIDEO'" class="tile__play" aria-hidden="true">
                    <NcIconSvgWrapper :path="mdiPlay" :size="28" />
                </span>
            </button>
            <button class="tile__check"
                type="button"
                :aria-label="selected.has(item.fileId) ? t('Deselect') : t('Select')"
                @click.stop="toggle(item, index, $event.shiftKey)">
                <NcIconSvgWrapper :path="selected.has(item.fileId) ? mdiCheckCircle : mdiCheckboxBlankCircleOutline" :size="24" />
            </button>
            <a class="tile__folder" :href="fileUrl(item.fileId)" :title="t('Show in folder')" :aria-label="t('Show in folder')" @click.stop>
                <NcIconSvgWrapper :path="mdiFolderOutline" :size="18" />
            </a>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiCheckCircle, mdiCheckboxBlankCircleOutline, mdiFolderOutline, mdiPlay } from '@mdi/js'
import { fileUrl, thumbnailUrl } from '../api.js'
import { t } from '../l10n.js'
import { prefs } from '../prefs.js'

const LONG_PRESS_MS = 450

const props = defineProps({
    items: { type: Array, required: true },
    /** Set of selected file ids; the parent owns it. */
    selected: { type: Set, required: true },
})
const emit = defineEmits(['open', 'update:selected'])

const selecting = computed(() => props.selected.size > 0)
let lastIndex = null
let pressTimer = null
let pressed = false

function tooltip(item) {
    const date = item.date ? new Date(item.date).toLocaleDateString() : ''
    return [date, item.path].filter(Boolean).join('\n')
}

function toggle(item, index, range) {
    const next = new Set(props.selected)
    if (range && lastIndex !== null) {
        const [from, to] = [Math.min(lastIndex, index), Math.max(lastIndex, index)]
        props.items.slice(from, to + 1).forEach((i) => next.add(i.fileId))
    } else if (next.has(item.fileId)) {
        next.delete(item.fileId)
    } else {
        next.add(item.fileId)
    }
    lastIndex = index
    emit('update:selected', next)
}

function onClick(item, index, event) {
    if (pressed) {
        pressed = false // the long-press already selected it
        return
    }
    if (selecting.value || event.shiftKey || event.ctrlKey || event.metaKey) {
        toggle(item, index, event.shiftKey)
    } else {
        emit('open', item, index)
    }
}

function onTouchStart(item, index) {
    pressed = false
    pressTimer = window.setTimeout(() => {
        pressed = true
        toggle(item, index, false)
        window.navigator.vibrate?.(20)
    }, LONG_PRESS_MS)
}

function cancelPress() {
    window.clearTimeout(pressTimer)
}

function onTouchEnd(event) {
    cancelPress()
    if (pressed) {
        event.preventDefault() // no click, no context menu after a long-press
    }
}

function onContextMenu(event) {
    if (pressed) {
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

    &__check, &__folder {
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

    &__folder {
        top: 6px;
        right: 6px;
        width: 30px;
        height: 30px;
        background: rgba(255, 255, 255, 0.85);
        color: #222;
    }

    &:hover, &:focus-within {
        .tile__check, .tile__folder { opacity: 1; }
    }

    &--selected {
        .tile__open img { transform: scale(0.88); border-radius: 6px; }
        .tile__check { opacity: 1; color: var(--color-primary-element); filter: drop-shadow(0 0 1px #fff); }
    }
}

// Touch screens have no hover: checkboxes show while selecting; "show in folder"
// moves to the selection bar (one picture selected) instead of cluttering every tile.
@media (hover: none) {
    .tile__folder { display: none; }
    .grid:has(.tile--selected) .tile__check { opacity: 1; }
}
</style>
