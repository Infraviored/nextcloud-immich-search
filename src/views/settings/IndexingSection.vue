<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  What Immich indexes. Formats and folders become exclusion patterns of every Immich
  library (so excluded files are never analysed); the minimum size filters results.
-->
<template>
	<div v-if="s" class="indexing">
		<h3>{{ t('File types not to index') }}</h3>
		<p class="hint">
			{{ t('Immich skips these entirely – no analysis, no results. Changes are applied to all libraries and start a scan.') }}
		</p>
		<div class="indexing__formats">
			<NcCheckboxRadioSwitch
				v-for="f in formatCatalog"
				:key="f.ext"
				:modelValue="formats.includes(f.ext)"
				@update:modelValue="toggleFormat(f.ext, $event)">
				{{ f.label }}
			</NcCheckboxRadioSwitch>
		</div>
		<div class="indexing__add">
			<NcTextField
				v-model="extraFormat"
				:label="t('Other file extension')"
				placeholder="xcf"
				@keydown.enter.prevent="addFormat" />
			<NcButton :disabled="!extraFormat.trim()" @click="addFormat">
				{{ t('Add') }}
			</NcButton>
		</div>
		<div v-if="customFormats.length" class="indexing__chips">
			<span v-for="f in customFormats" :key="f" class="chip">
				.{{ f }}
				<button type="button" :aria-label="t('Remove')" @click="toggleFormat(f, false)">×</button>
			</span>
		</div>

		<h3>{{ t('Folders not to index') }}</h3>
		<p class="hint">
			{{ t('A folder name (excluded wherever it appears) or a full Immich path ending in /**.') }}
		</p>
		<div class="indexing__add">
			<NcTextField
				v-model="extraFolder"
				:label="t('Folder')"
				placeholder="Screenshots"
				@keydown.enter.prevent="addFolder" />
			<NcButton :disabled="!extraFolder.trim()" @click="addFolder">
				{{ t('Add') }}
			</NcButton>
		</div>
		<ul class="indexing__list">
			<li v-for="f in s.excluded_folders" :key="f">
				<code>{{ f }}</code>
				<NcButton variant="tertiary" :aria-label="t('Remove')" @click="removeFolder(f)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</li>
		</ul>

		<h4>{{ t('Suggestions') }}</h4>
		<NcLoadingIcon v-if="suggestions === null" />
		<p v-else-if="!suggestions.length" class="hint">
			{{ t('Nothing suspicious found.') }}
		</p>
		<ul v-else class="indexing__list">
			<li v-for="sug in suggestions.slice(0, suggestionLimit)" :key="sug.pattern">
				<code :title="sug.path">{{ shorten(sug.label) }}</code>
				<NcButton variant="secondary" @click="exclude(sug)">
					{{ t('Exclude') }}
				</NcButton>
			</li>
		</ul>
		<NcButton v-if="suggestions && suggestions.length > suggestionLimit" variant="tertiary" @click="suggestionLimit += 20">
			{{ t('Show more') }}
		</NcButton>

		<h3>{{ t('Minimum picture size') }}</h3>
		<p class="hint">
			{{ t('Pictures whose shorter side is smaller are never shown (icons, emoji, thumbnails). 0 shows everything.') }}
		</p>
		<div class="indexing__add">
			<NcTextField
				v-model="minSize"
				type="number"
				min="0"
				:label="t('Pixels')" />
			<NcButton @click="saveMinSize">
				{{ t('Save') }}
			</NcButton>
		</div>
		<NcCheckboxRadioSwitch v-model="s.prune_small" type="switch" @update:modelValue="save({ prune_small: $event })">
			{{ t('Also take them out of Immich (no analysis, never imported again)') }}
		</NcCheckboxRadioSwitch>
		<p class="hint">
			{{ t('New pictures are checked every 15 minutes. The files in Nextcloud stay untouched.') }}
		</p>
		<NcButton v-if="s.prune_small" :disabled="busy" @click="pruneAll">
			{{ t('Check all pictures now') }}
		</NcButton>

		<h3>{{ t('Search inside videos') }}</h3>
		<p class="hint">
			{{ t('Immich looks at one still per video. With this on, Glimpse takes a still every 15 seconds or so (at most 40 per video) and hands them to Immich, so a search finds the moment in a video and opens it there. Needs ffmpeg on the Nextcloud server; the stills take about 50 KB each in Immich.') }}
		</p>
		<NcCheckboxRadioSwitch v-model="s.video_scenes" type="switch" @update:modelValue="save({ video_scenes: $event })">
			{{ t('Search inside videos') }}
		</NcCheckboxRadioSwitch>

		<h3>{{ t('Libraries per user') }}</h3>
		<p class="hint">
			{{ t('Every Nextcloud user whose files should be searchable needs a read-only mount in the Immich container and a library.') }}
		</p>
		<NcLoadingIcon v-if="!libs" />
		<template v-else>
			<ul class="indexing__list">
				<li v-for="lib in libs.libraries" :key="lib.id">
					<strong>{{ lib.user || lib.name }}</strong>
					<span class="hint">{{ lib.importPaths.join(', ') }}</span>
				</li>
			</ul>
			<template v-if="libs.missing.length">
				<h4>{{ t('Users without a library') }}</h4>
				<ul class="indexing__list">
					<li v-for="u in libs.missing" :key="u.uid">
						{{ u.displayName }} ({{ u.uid }})
						<NcButton variant="secondary" :disabled="busy" @click="addLibrary(u.uid)">
							{{ t('Create library') }}
						</NcButton>
					</li>
				</ul>
				<p class="hint">
					{{ t('Add the mount first, then create the library. Example for the Immich compose file:') }}
				</p>
				<pre class="indexing__snippet">{{ snippet }}</pre>
			</template>
		</template>
	</div>
	<NcLoadingIcon v-else :size="32" />
</template>

<script setup>
import { mdiClose } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { adminSettings, loadAdminSettings, saveAdmin } from '../../adminSettings.js'
import { createLibrary, getFolderSuggestions, getLibraries, pruneSmall } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'
import { serialSaver } from '../../serialSaver.js'

const formatCatalog = [
	{ ext: 'svg', label: t('SVG (vector graphics, icons)') },
	{ ext: 'ico', label: t('ICO (icons)') },
	{ ext: 'icns', label: 'ICNS (macOS icons)' },
	{ ext: 'cur', label: t('CUR (cursors)') },
	{ ext: 'gif', label: t('GIF (animations, stickers)') },
	{ ext: 'webp', label: 'WebP' },
	{ ext: 'png', label: t('PNG (screenshots, graphics)') },
	{ ext: 'psd', label: 'PSD (Photoshop)' },
	{ ext: 'xcf', label: 'XCF (GIMP)' },
	{ ext: 'tif', label: 'TIFF' },
	{ ext: 'bmp', label: 'BMP' },
	{ ext: 'heic', label: 'HEIC (iPhone)' },
	{ ext: 'dng', label: t('DNG (raw photos)') },
	{ ext: 'mkv', label: t('MKV (videos)') },
	{ ext: 'avi', label: t('AVI (videos)') },
]

const s = ref(null)
const libs = ref(null)
const suggestions = ref(null)
const suggestionLimit = ref(20)
const extraFormat = ref('')
const extraFolder = ref('')
const minSize = ref('')
const { busy, act } = useAction()

const formats = computed(() => s.value?.excluded_formats ?? [])
const customFormats = computed(() => formats.value.filter((f) => !formatCatalog.some((c) => c.ext === f) && f !== 'svgz'))
const snippet = computed(() => {
	const pattern = libs.value?.pathPattern ?? '/mnt/nextcloud/{user}/'
	return 'services:\n  immich-server:\n    volumes:\n'
		+ libs.value.missing.map((u) => `      - <nextcloud-data>/${u.uid}/files:${pattern.replace('{user}', u.uid).replace(/\/$/, '')}:ro`).join('\n')
})

function shorten(path) {
	return path.length > 90 ? '…' + path.slice(-88) : path
}

/** One save at a time; clicks during a slow save go out together right after it. */
const saver = serialSaver(saveAdmin)

/**
 * Applied to the page at once, so the next click builds on it and not on the state of the
 * last server answer (rapid toggles used to undo each other).
 *
 * @param partial
 */
async function save(partial) {
	s.value = { ...s.value, ...partial }
	const res = await act(() => saver.save(partial))
	if (saver.idle()) {
		// Settled: show what the server has (after a failure: what it had before).
		s.value = { ...(res ? res.settings : adminSettings.values) }
		if (res) {
			showSuccess(res.exclusionsApplied !== null
				? n('Saved. {count} exclusion pattern applied to Immich, scan started.', 'Saved. {count} exclusion patterns applied to Immich, scan started.', res.exclusionsApplied, { count: res.exclusionsApplied })
				: t('Saved'))
		}
	}
	return res
}

function toggleFormat(ext, on) {
	const list = on ? [...formats.value, ext] : formats.value.filter((f) => f !== ext)
	save({ excluded_formats: list })
}

function addFormat() {
	const ext = extraFormat.value.trim().replace(/^\*?\.?/, '').toLowerCase()
	if (/^[a-z0-9]{1,10}$/.test(ext)) {
		toggleFormat(ext, true)
		extraFormat.value = ''
	}
}

function addFolder() {
	const f = extraFolder.value.trim()
	if (f) {
		save({ excluded_folders: [...s.value.excluded_folders, f] })
		extraFolder.value = ''
	}
}

function removeFolder(f) {
	save({ excluded_folders: s.value.excluded_folders.filter((x) => x !== f) })
}

async function exclude(sug) {
	const res = await save({ excluded_folders: [...s.value.excluded_folders, sug.pattern] })
	if (res) {
		suggestions.value = suggestions.value.filter((x) => x.pattern !== sug.pattern)
	}
}

function pruneAll() {
	act(pruneSmall, (r) => n('{count} picture checked, {pruned} taken out of Immich', '{count} pictures checked, {pruned} taken out of Immich', r.checked, { count: r.checked, pruned: r.pruned }))
}

function saveMinSize() {
	save({ min_size: Number(minSize.value) || 0 })
}

async function addLibrary(uid) {
	const res = await act(() => createLibrary(uid), t('Library created, scan started'))
	if (res) {
		libs.value = await getLibraries()
	}
}

onMounted(async () => {
	s.value = { ...await act(loadAdminSettings) }
	minSize.value = String(s.value.min_size ?? 256)
	libs.value = await act(getLibraries)
	suggestions.value = (await act(getFolderSuggestions)) ?? []
})
</script>

<style scoped lang="scss">
.indexing {
	max-width: 760px;

	h3 { margin-top: 24px; }
	h4 { margin: 16px 0 4px; }

	&__formats {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
		gap: 0 12px;
	}

	&__add {
		display: flex;
		gap: 8px;
		align-items: flex-end;
		max-width: 420px;
		margin: 8px 0;

		> :first-child { flex: 1 1 auto; }
		> button { flex: 0 0 auto; }
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;

		.chip {
			padding: 2px 4px 2px 10px;
			border-radius: var(--border-radius-pill);
			background: var(--color-background-dark);

			button {
				min-height: 0;
				margin: 0;
				padding: 0 6px;
				border: 0;
				background: none;
			}
		}
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 4px;

		li {
			display: flex;
			flex-wrap: wrap;
			gap: 4px 12px;
			align-items: center;
		}

		code {
			overflow-wrap: anywhere;
		}
	}

	&__snippet {
		overflow-x: auto;
		padding: 8px 12px;
		border-radius: var(--border-radius);
		background: var(--color-background-dark);
	}
}

.hint { color: var(--color-text-maxcontrast); }
</style>
