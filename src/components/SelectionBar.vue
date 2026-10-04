<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="selection-bar" role="toolbar" :aria-label="t('Selection')">
		<NcButton variant="tertiary" :aria-label="t('Clear selection')" @click="$emit('clear')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiClose" />
			</template>
		</NcButton>
		<span class="selection-bar__count">{{ n('{count} selected', '{count} selected', count, { count }) }}</span>
		<NcButton v-if="canSelectAll" variant="tertiary" @click="$emit('selectAll')">
			{{ t('Select all') }}
		</NcButton>
		<span class="selection-bar__spacer" />

		<NcButton :disabled="busy" @click="$emit('copy', null)">
			<template #icon>
				<NcIconSvgWrapper :path="mdiContentCopy" />
			</template>
			{{ t('Copy') }}
		</NcButton>
		<div class="selection-bar__split">
			<NcButton variant="primary" :disabled="busy" @click="$emit('move', null)">
				<template #icon>
					<NcLoadingIcon v-if="busy" />
					<NcIconSvgWrapper v-else :path="mdiFolderMove" />
				</template>
				{{ t('Move') }}
			</NcButton>
			<NcActions
				v-if="recent.length"
				variant="primary"
				:aria-label="t('Recent folders')"
				:disabled="busy">
				<template #icon>
					<NcIconSvgWrapper :path="mdiChevronDown" />
				</template>
				<NcActionButton
					v-for="folder in recent"
					:key="folder"
					:closeAfterClick="true"
					@click="$emit('move', folder)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderOutline" />
					</template>
					{{ t('Move to {folder}', { folder }) }}
				</NcActionButton>
				<NcActionSeparator />
				<NcActionButton :closeAfterClick="true" @click="$emit('move', null)">
					{{ t('Choose folder …') }}
				</NcActionButton>
			</NcActions>
		</div>

		<NcActions :aria-label="t('More actions')" :forceMenu="true">
			<NcActionButton :closeAfterClick="true" @click="$emit('favorite', true)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiStarOutline" />
				</template>
				{{ t('Add to favorites') }}
			</NcActionButton>
			<NcActionButton :closeAfterClick="true" @click="$emit('favorite', false)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiStarOffOutline" />
				</template>
				{{ t('Remove from favorites') }}
			</NcActionButton>
			<NcActionButton :closeAfterClick="true" @click="$emit('tag')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiTagOutline" />
				</template>
				{{ t('Tag …') }}
			</NcActionButton>
			<NcActionButton v-if="photosEnabled" :closeAfterClick="true" @click="$emit('album')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiImageAlbum" />
				</template>
				{{ t('Add to album …') }}
			</NcActionButton>
			<NcActionButton :closeAfterClick="true" @click="$emit('private', true)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiLockOutline" />
				</template>
				{{ t('Mark as private') }}
			</NcActionButton>
			<NcActionButton :closeAfterClick="true" @click="$emit('private', false)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiLockOpenVariantOutline" />
				</template>
				{{ t('No longer private') }}
			</NcActionButton>
			<NcActionLink :href="zipHref" :closeAfterClick="true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('Download as ZIP') }}
			</NcActionLink>
			<template v-if="single">
				<NcActionSeparator />
				<NcActionButton :closeAfterClick="true" @click="$emit('similar')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiImageMultipleOutline" />
					</template>
					{{ t('Similar pictures') }}
				</NcActionButton>
				<NcActionButton v-if="single.id" :closeAfterClick="true" @click="$emit('faces')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFaceRecognition" />
					</template>
					{{ t('Faces …') }}
				</NcActionButton>
				<NcActionLink :href="fileUrl(single.fileId)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderOutline" />
					</template>
					{{ t('Show in folder') }}
				</NcActionLink>
				<NcActionLink :href="shareHref">
					<template #icon>
						<NcIconSvgWrapper :path="mdiShareVariantOutline" />
					</template>
					{{ t('Share …') }}
				</NcActionLink>
			</template>
			<NcActionSeparator />
			<NcActionButton :closeAfterClick="true" @click="$emit('delete')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiTrashCanOutline" />
				</template>
				{{ t('Delete') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script setup>
import {
	mdiChevronDown,
	mdiClose,
	mdiContentCopy,
	mdiDownload,
	mdiFaceRecognition,
	mdiFolderMove,
	mdiFolderOutline,
	mdiImageAlbum,
	mdiImageMultipleOutline,
	mdiLockOpenVariantOutline,
	mdiLockOutline,
	mdiShareVariantOutline,
	mdiStarOffOutline,
	mdiStarOutline,
	mdiTagOutline,
	mdiTrashCanOutline,
} from '@mdi/js'
import { generateUrl } from '@nextcloud/router'
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { fileUrl, zipUrl } from '../api.js'
import { recentTargets as recent } from '../composables/useTransfer.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	count: { type: Number, required: true },
	fileIds: { type: Array, required: true },
	canSelectAll: { type: Boolean, default: false },
	busy: { type: Boolean, default: false },
	/** The selected item, if exactly one is selected. */
	single: { type: Object, default: null },
	photosEnabled: { type: Boolean, default: false },
})
defineEmits(['clear', 'selectAll', 'move', 'copy', 'favorite', 'tag', 'album', 'similar', 'faces', 'private', 'delete'])

const zipHref = computed(() => zipUrl(props.fileIds))
const shareHref = computed(() => {
	const dir = props.single.path.slice(0, props.single.path.lastIndexOf('/')) || '/'
	return generateUrl('/apps/files/files/{id}?dir={dir}&opendetails=true', { id: props.single.fileId, dir })
})
</script>

<style scoped lang="scss">
.selection-bar {
	position: sticky;
	top: 0;
	z-index: 10;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);

	&__count { font-weight: bold; }
	&__spacer { flex: 1; }

	&__split {
		display: flex;
		gap: 2px;
	}
}
</style>
