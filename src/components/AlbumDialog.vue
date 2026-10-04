<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Albums of the Nextcloud Photos app. -->
<template>
	<NcDialog :name="n('Add {count} picture to an album', 'Add {count} pictures to an album', count, { count })" :buttons="buttons" @closing="$emit('close')">
		<NcSelect
			v-model="chosen"
			:options="albums"
			:taggable="true"
			:inputLabel="t('Album')"
			:placeholder="t('Choose or type a new album')"
			:loading="loading" />
	</NcDialog>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { listAlbums } from '../albums.js'
import { n, t } from '../l10n.js'

defineProps({
	count: { type: Number, required: true },
})
const emit = defineEmits(['close', 'apply'])

const albums = ref([])
const chosen = ref(null)
const loading = ref(true)

const buttons = computed(() => [
	{ label: t('Cancel'), callback: () => emit('close') },
	{
		label: t('Add'),
		variant: 'primary',
		disabled: !chosen.value,
		callback: () => emit('apply', chosen.value, !albums.value.includes(chosen.value)),
	},
])

onMounted(async () => {
	try {
		albums.value = await listAlbums()
	} finally {
		loading.value = false
	}
})
</script>
