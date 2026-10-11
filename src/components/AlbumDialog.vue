<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
  Albums of the Nextcloud Photos app. Optionally shares the album with people right away:
  pictures from all over the place, shared without moving or copying a single file.
-->
<template>
	<NcDialog :name="n('Add {count} picture to an album', 'Add {count} pictures to an album', count, { count })" :buttons="buttons" @closing="$emit('close')">
		<div class="album-dialog">
			<NcSelect
				v-model="chosen"
				:options="albums"
				:taggable="true"
				:inputLabel="t('Album')"
				:placeholder="t('Choose or type a new album')"
				:loading="loading" />
			<NcSelect
				v-model="recipients"
				:options="found"
				:multiple="true"
				:filterable="false"
				:inputLabel="t('Share with (optional)')"
				:placeholder="t('Search users or groups')"
				label="label"
				:loading="searching"
				@search="search">
				<template #no-options>
					{{ term ? t('Nobody found') : t('Type a name') }}
				</template>
			</NcSelect>
			<p v-if="sharedWith.length" class="album-dialog__hint">
				{{ t('Already shared with {names}', { names: sharedWith.map((c) => c.label || c.id).join(', ') }) }}
			</p>
			<p v-else-if="recipients.length" class="album-dialog__hint">
				{{ t('They will find the album in Photos under “Shared albums”. The files stay where they are.') }}
			</p>
		</div>
	</NcDialog>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { albumCollaborators, findRecipients, listAlbums } from '../albums.js'
import { n, t } from '../l10n.js'

defineProps({
	count: { type: Number, required: true },
})
const emit = defineEmits(['close', 'apply'])

const albums = ref([])
const chosen = ref(null)
const loading = ref(true)
const recipients = ref([])
const found = ref([])
const term = ref('')
const searching = ref(false)
/** Who the chosen existing album is shared with already */
const sharedWith = ref([])
let searchCtrl = null
let timer = null

const buttons = computed(() => [
	{ label: t('Cancel'), callback: () => emit('close') },
	{
		label: recipients.value.length ? t('Add and share') : t('Add'),
		variant: 'primary',
		disabled: !chosen.value,
		callback: () => emit(
			'apply',
			chosen.value,
			!albums.value.includes(chosen.value),
			recipients.value.map(({ id, type }) => ({ id, type })),
		),
	},
])

function search(text) {
	term.value = text.trim()
	clearTimeout(timer)
	searchCtrl?.abort()
	if (!term.value) {
		found.value = []
		searching.value = false
		return
	}
	searching.value = true
	timer = setTimeout(async () => {
		const ctrl = searchCtrl = new AbortController()
		try {
			found.value = await findRecipients(term.value, ctrl.signal)
		} catch {
			if (ctrl === searchCtrl) {
				found.value = []
			}
		} finally {
			if (ctrl === searchCtrl) {
				searching.value = false
			}
		}
	}, 250)
}

watch(chosen, async (name) => {
	sharedWith.value = []
	if (!name || !albums.value.includes(name)) {
		return
	}
	try {
		const list = await albumCollaborators(name)
		if (chosen.value === name) {
			sharedWith.value = list.filter((c) => c.type !== 3) // public links are not people
		}
	} catch {
		// only a hint
	}
})

onMounted(async () => {
	try {
		albums.value = await listAlbums()
	} finally {
		loading.value = false
	}
})
</script>

<style scoped lang="scss">
.album-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 8px;

	&__hint {
		color: var(--color-text-maxcontrast);
	}
}
</style>
