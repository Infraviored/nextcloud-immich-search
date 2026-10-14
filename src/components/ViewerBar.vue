<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Over the Nextcloud viewer: the actions of the Android app's viewer for the picture shown,
  with the same icons. Share, favorite, similar, info and delete along the bottom; the info
  sheet has when, who and where, and the rest (download, move, copy, album, tag).
-->
<template>
	<Teleport to="body">
		<template v-if="item">
			<div
				v-show="!dialog && !info"
				class="viewer-bar"
				role="toolbar"
				:aria-label="t('Picture actions')">
				<div class="viewer-bar__row">
					<button
						v-for="a in barActions"
						:key="a.key"
						type="button"
						class="viewer-bar__action"
						:disabled="a.disabled"
						@click="a.run">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="a.icon" /></svg>
						<span>{{ a.label }}</span>
					</button>
				</div>
			</div>

			<div v-if="info" class="viewer-sheet__scrim" @click="info = false" />
			<section
				v-if="info"
				ref="sheet"
				class="viewer-sheet"
				role="dialog"
				aria-modal="true"
				:aria-label="t('Info')"
				tabindex="-1">
				<div class="viewer-sheet__handle" />
				<template v-if="taken">
					<h2 class="viewer-sheet__date">
						{{ taken.date }}
					</h2>
					<p class="viewer-sheet__time">
						{{ taken.time }}
					</p>
				</template>

				<template v-if="people.length">
					<h3 class="viewer-sheet__heading">
						{{ t('In this photo') }}
					</h3>
					<ul class="viewer-sheet__people">
						<li v-for="p in people" :key="p.id">
							<button type="button" @click="go('/people/' + p.id)">
								<img :src="faceUrl(p.id)" alt="">
								<span>{{ p.name || t('Unnamed') }}</span>
							</button>
						</li>
					</ul>
				</template>

				<div class="viewer-sheet__rows">
					<div class="viewer-sheet__folder">
						<button type="button" class="viewer-sheet__row" @click="go('/folders', { path: folder })">
							<span class="viewer-sheet__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="folderIcon" /></svg></span>
							<span class="viewer-sheet__text">
								<span class="viewer-sheet__title">{{ item.name }}</span>
								<span class="viewer-sheet__subtitle">{{ folder }}</span>
							</span>
						</button>
						<a
							class="viewer-sheet__files"
							:href="folderUrl(item)"
							:title="t('Show in Files')"
							:aria-label="t('Show in Files')">
							<NcIconSvgWrapper :path="mdiOpenInNew" :size="20" />
						</a>
					</div>
					<button
						v-for="r in sheetRows"
						:key="r.key"
						type="button"
						class="viewer-sheet__row"
						@click="r.run">
						<span class="viewer-sheet__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="r.icon" /></svg></span>
						<span class="viewer-sheet__text">
							<span class="viewer-sheet__title">{{ r.label }}</span>
						</span>
					</button>
				</div>
			</section>

			<TagDialog
				v-if="dialog === 'tag'"
				:count="1"
				@close="dialog = null"
				@apply="doTag" />
			<AlbumDialog
				v-if="dialog === 'album'"
				:count="1"
				@close="dialog = null"
				@apply="doAlbum" />
			<NcDialog
				v-if="dialog === 'delete'"
				:name="n('Delete {count} file?', 'Delete {count} files?', 1, { count: 1 })"
				:message="t('They go to the Nextcloud trash bin and can be restored from there.')"
				:buttons="deleteButtons"
				@closing="dialog = null" />
		</template>
	</Teleport>
</template>

<script setup>
import { mdiOpenInNew } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import AlbumDialog from './AlbumDialog.vue'
import TagDialog from './TagDialog.vue'
import { faceUrl, getFaces, publicLink } from '../api.js'
import { usePictureActions } from '../composables/usePictureActions.js'
import { useTransfer } from '../composables/useTransfer.js'
import { n, t } from '../l10n.js'
import { albumIcon, copyIcon, deleteIcon, downloadIcon, favoriteIcon, folderIcon, infoIcon, moveIcon, shareIcon, similarIcon, tagIcon } from '../materialIcons.js'
import { navigate } from '../router.js'
import { closeViewer, downloadUrl, folderUrl, viewerState } from '../viewer.js'

const page = loadState('glimpse', 'page', { photosEnabled: false })
const { setFavorite, tag, album, remove } = usePictureActions()
const { run: runTransfer } = useTransfer(() => viewerState.onRefresh?.())

const item = computed(() => viewerState.current)
const info = ref(false)
const dialog = ref(null)
const sheet = ref(null)
const people = ref([])

const folder = computed(() => item.value.path.slice(0, item.value.path.lastIndexOf('/')) || '/')

// Immich's local time comes as if it were UTC: read it back as such, not shifted
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeZone: 'UTC' })
const timeFormat = new Intl.DateTimeFormat(undefined, { timeStyle: 'short', timeZone: 'UTC' })
const taken = computed(() => {
	const d = item.value?.date ? new Date(item.value.date) : null
	return d && !isNaN(d) ? { date: dateFormat.format(d), time: timeFormat.format(d) } : null
})

const barActions = computed(() => [
	{ key: 'share', icon: shareIcon, label: t('Share'), run: share },
	{ key: 'favorite', icon: favoriteIcon, label: t('Favorite'), run: () => setFavorite([item.value.fileId], true) },
	{ key: 'similar', icon: similarIcon, label: t('Similar'), run: similar, disabled: !item.value.id },
	{ key: 'info', icon: infoIcon, label: t('Info'), run: () => { info.value = true } },
	{ key: 'delete', icon: deleteIcon, label: t('Delete'), run: () => { dialog.value = 'delete' } },
])

const sheetRows = computed(() => [
	item.value.id && { key: 'similar', icon: similarIcon, label: t('Show similar photos'), run: similar },
	{ key: 'download', icon: downloadIcon, label: t('Download'), run: download },
	{ key: 'move', icon: moveIcon, label: t('Move to folder …'), run: () => transfer('move') },
	{ key: 'copy', icon: copyIcon, label: t('Copy to folder …'), run: () => transfer('copy') },
	page.photosEnabled && { key: 'album', icon: albumIcon, label: t('Add to album …'), run: () => openDialog('album') },
	{ key: 'tag', icon: tagIcon, label: t('Tag …'), run: () => openDialog('tag') },
].filter(Boolean))

/** Who is in the picture, loaded when the info sheet opens. */
let loads = 0
watch([info, () => item.value?.id], async ([open, id]) => {
	const mine = ++loads
	people.value = []
	if (!open || !id) {
		return
	}
	try {
		const faces = (await getFaces(id)).faces ?? []
		const seen = new Map()
		for (const f of faces) {
			if (f.personId && f.known && !seen.has(f.personId)) {
				seen.set(f.personId, { id: f.personId, name: f.personName })
			}
		}
		if (mine === loads) {
			people.value = [...seen.values()]
		}
	} catch {
		// no faces: the sheet shows the rest
	}
})

watch(() => item.value?.fileId, () => {
	info.value = false
	dialog.value = null
})

watch(info, async (open) => {
	if (open) {
		await nextTick()
		sheet.value?.focus()
	}
})

/**
 * While the sheet is open, Escape closes it and the arrows do not page the viewer behind it.
 *
 * @param e
 */
function onKey(e) {
	if (!info.value) {
		return
	}
	if (e.key === 'Escape') {
		info.value = false
	} else if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) {
		return
	}
	e.stopImmediatePropagation()
	e.preventDefault()
}
window.addEventListener('keydown', onKey, true)
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true))

function openDialog(name) {
	info.value = false
	dialog.value = name
}

/**
 * Leave the viewer for one of Glimpse's own views.
 *
 * @param path
 * @param query
 */
function go(path, query = {}) {
	closeViewer()
	navigate(path, query)
}

function similar() {
	go('/', { similar: item.value.id, name: item.value.name })
}

/** The picture left the view it was opened from: tell that view, and close. */
function gone() {
	viewerState.onRemoved?.([item.value.fileId])
	closeViewer()
}

/** A public link: through the device's share sheet where there is one, else to the clipboard. */
async function share() {
	const current = item.value
	try {
		const link = await publicLink(current.path)
		if (navigator.share) {
			try {
				await navigator.share({ title: current.name, url: link })
				return
			} catch (e) {
				if (e?.name === 'AbortError') {
					return
				}
				// no share sheet after all: copy instead
			}
		}
		await navigator.clipboard.writeText(link)
		showSuccess(t('Link copied'))
	} catch (e) {
		showError(t('Could not share: {error}', { error: e?.message ?? String(e) }))
	}
}

function download() {
	info.value = false
	const a = document.createElement('a')
	a.href = downloadUrl(item.value)
	a.download = item.value.name
	document.body.appendChild(a)
	a.click()
	a.remove()
}

async function transfer(mode) {
	info.value = false
	const fileId = item.value.fileId
	const res = await runTransfer([fileId], mode)
	if (res && mode === 'move' && !res.failedIds.includes(fileId)) {
		gone()
	}
}

function doTag(tagIds, newTags) {
	dialog.value = null
	tag([item.value.fileId], tagIds, newTags)
}

function doAlbum(name, isNew, recipients = []) {
	dialog.value = null
	album([item.value], name, isNew, recipients)
}

async function doDelete() {
	dialog.value = null
	const res = await remove([item.value.fileId])
	if (res && res.done > 0) {
		gone()
	}
}

const deleteButtons = computed(() => [
	{ label: t('Cancel'), callback: () => { dialog.value = null } },
	{ label: t('Delete'), variant: 'error', callback: doDelete },
])
</script>

<style lang="scss">
// Teleported to <body>, so not scoped: everything is prefixed.

// Buttons are nested one class deeper than needed: Nextcloud styles every
// button:not(.button-vue), which outranks a single class.

// Room for the bar: the picture, and a video's controls, end above it (50px: viewer header)
body.glimpse-viewer-bar .viewer .modal-container {
	max-height: calc(100% - 50px - 68px);
}

// Phones: the viewer's backdrop is not quite opaque, and Glimpse's dock would show through the bar
body.glimpse-viewer-bar .dock {
	visibility: hidden;
}

// A dialog over the viewer (folder picker, tags, delete …) covers the bar too
body:has(.modal-mask:not(.viewer)) .viewer-bar {
	display: none;
}

.viewer-bar {
	position: fixed;
	z-index: 10002;
	inset: auto 0 0 0;
	padding: 20px 8px max(8px, env(safe-area-inset-bottom));
	background: linear-gradient(transparent, rgba(0, 0, 0, 0.7) 24px);
	pointer-events: none;

	&__row {
		display: flex;
		justify-content: space-evenly;
		max-width: 560px;
		margin: 0 auto;
	}

	& &__action {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 4px;
		min-width: 64px;
		min-height: 0;
		margin: 0;
		padding: 6px 10px;
		border: 0;
		border-radius: 16px;
		background: none;
		color: #fff;
		font-family: inherit;
		font-size: 12px;
		font-weight: 500;
		line-height: 16px;
		letter-spacing: 0.5px;
		cursor: pointer;
		pointer-events: auto;

		svg {
			width: 24px;
			height: 24px;
			fill: currentColor;
		}

		span {
			color: rgba(255, 255, 255, 0.85);
		}

		&:hover:not(:disabled), &:focus-visible {
			background: rgba(255, 255, 255, 0.12);
		}

		&:focus-visible {
			outline: 2px solid #fff;
			outline-offset: -2px;
		}

		&:disabled {
			opacity: 0.4;
			cursor: default;
		}
	}
}

.viewer-sheet__scrim {
	position: fixed;
	z-index: 10003;
	inset: 0;
	background: rgba(0, 0, 0, 0.32);
}

.viewer-sheet {
	position: fixed;
	z-index: 10004;
	inset-block-end: 0;
	inset-inline-start: 50%;
	box-sizing: border-box;
	width: min(100%, 560px);
	max-height: 85vh;
	overflow-y: auto;
	padding: 0 20px max(24px, env(safe-area-inset-bottom));
	border-radius: 28px 28px 0 0;
	background: var(--g-raised);
	color: var(--g-text);
	transform: translateX(-50%);
	animation: viewer-sheet-in 220ms cubic-bezier(0.2, 0, 0, 1);
	outline: none;

	&__handle {
		width: 32px;
		height: 4px;
		margin: 16px auto 18px;
		border-radius: 2px;
		background: var(--g-muted);
		opacity: 0.4;
	}

	&__date {
		margin: 0;
		color: var(--g-text);
		font-size: 24px;
		font-weight: 400;
		line-height: 32px;
	}

	&__time {
		margin: 0 0 16px;
		color: var(--g-muted);
		font-size: 14px;
	}

	&__heading {
		margin: 0 0 10px;
		color: var(--g-text);
		font-size: 16px;
		font-weight: 500;
	}

	& &__people {
		display: flex;
		gap: 12px;
		margin: 0 0 16px;
		padding: 0;
		overflow-x: auto;
		list-style: none;

		button {
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 4px;
			width: 68px;
			min-height: 0;
			margin: 0;
			padding: 0;
			border: 0;
			border-radius: 12px;
			background: none;
			color: var(--g-text);
			cursor: pointer;
		}

		img {
			width: 56px;
			height: 56px;
			border-radius: 50%;
			background: var(--g-line);
			object-fit: cover;
		}

		span {
			max-width: 100%;
			overflow: hidden;
			font-size: 12px;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	}

	&__folder {
		display: flex;
		align-items: center;
		gap: 4px;

		.viewer-sheet__row {
			flex: 1;
			min-width: 0;
		}
	}

	&__files {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 44px;
		height: 44px;
		border-radius: 50%;
		color: var(--g-muted);

		&:hover, &:focus-visible {
			background: var(--g-line);
			color: var(--g-text);
		}
	}

	& &__row {
		display: flex;
		align-items: center;
		gap: 14px;
		width: 100%;
		min-height: 0;
		margin: 0;
		padding: 12px 4px;
		border: 0;
		border-radius: 14px;
		background: none;
		color: var(--g-text);
		font-weight: normal;
		text-align: start;
		cursor: pointer;

		&:hover, &:focus-visible {
			background: color-mix(in srgb, var(--g-line) 55%, transparent);
		}
	}

	&__icon {
		display: flex;
		flex: none;
		align-items: center;
		justify-content: center;
		width: 40px;
		height: 40px;
		border-radius: 12px;
		background: var(--g-line);

		svg {
			width: 22px;
			height: 22px;
			fill: var(--g-accent-text);
		}
	}

	&__text {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__title, &__subtitle {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__title {
		font-size: 16px;
		font-weight: 500;
		line-height: 24px;
	}

	&__subtitle {
		color: var(--g-muted);
		font-size: 12px;
		font-weight: normal;
	}
}

@keyframes viewer-sheet-in {
	from { transform: translate(-50%, 100%); }
}

@media (prefers-reduced-motion: reduce) {
	.viewer-sheet { animation: none; }
}
</style>
