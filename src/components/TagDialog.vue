<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Nextcloud collaborative tags: they show up in Files and its search, also on Android. -->
<template>
	<NcDialog :name="n('Tag {count} file', 'Tag {count} files', count, { count })" :buttons="buttons" @closing="$emit('close')">
		<NcSelect
			v-model="chosen"
			:options="options"
			:multiple="true"
			:taggable="true"
			:pushTags="true"
			:inputLabel="t('Tags')"
			:placeholder="t('Choose or type a new tag')"
			label="name"
			:loading="loading" />
	</NcDialog>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { getTags } from '../api.js'
import { n, t } from '../l10n.js'

defineProps({
	count: { type: Number, required: true },
})
const emit = defineEmits(['close', 'apply'])

const options = ref([])
const chosen = ref([])
const loading = ref(true)

const buttons = computed(() => [
	{ label: t('Cancel'), callback: () => emit('close') },
	{
		label: t('Tag'),
		variant: 'primary',
		disabled: !chosen.value.length,
		callback: () => {
			// Typed tags come in as plain strings or as objects without an id
			const picked = chosen.value.map((c) => (typeof c === 'string' ? { name: c } : c))
			emit('apply', picked.filter((c) => c.id).map((c) => c.id), picked.filter((c) => !c.id).map((c) => c.name))
		},
	},
])

onMounted(async () => {
	try {
		options.value = await getTags()
	} finally {
		loading.value = false
	}
})
</script>
