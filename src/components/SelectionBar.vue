<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<Teleport to="body">
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

			<NcButton variant="tertiary" :disabled="busy" @click="$emit('copy', null)">
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
				<template v-if="sensitiveOn">
					<NcActionButton v-if="term && single" :closeAfterClick="true" @click="$emit('upToHere')">
						<template #icon>
							<NcIconSvgWrapper :path="sensitiveIcon" />
						</template>
						{{ t('Sensitive up to here') }}
					</NcActionButton>
					<NcActionButton :closeAfterClick="true" @click="$emit('sensitive', true)">
						<template #icon>
							<NcIconSvgWrapper :path="sensitiveIcon" />
						</template>
						{{ t('Mark as sensitive') }}
					</NcActionButton>
					<NcActionButton :closeAfterClick="true" @click="$emit('sensitive', false)">
						<template #icon>
							<NcIconSvgWrapper :path="sensitiveIcon" />
						</template>
						{{ t('Mark as not sensitive') }}
					</NcActionButton>
				</template>
				<NcActionButton :closeAfterClick="true" @click="downloadZip(fileIds)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('Download as ZIP') }}
				</NcActionButton>
				<template v-if="person">
					<NcActionSeparator />
					<NcActionButton :closeAfterClick="true" @click="$emit('notPerson')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAccountRemoveOutline" />
						</template>
						{{ t('Not {name} …', { name: person.name || t('Unnamed') }) }}
					</NcActionButton>
				</template>
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
					<NcActionButton v-if="single.date" :closeAfterClick="true" @click="$emit('timeline')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarMonthOutline" />
						</template>
						{{ t('Show in timeline') }}
					</NcActionButton>
					<NcActionLink v-if="single.date && inMemories(single.path, memoriesPaths)" :href="memoriesUrl(single)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiImageFilterHdr" />
						</template>
						{{ t('Open in Memories') }}
					</NcActionLink>
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
	</Teleport>
</template>

<script setup>
import {
	mdiAccountRemoveOutline,
	mdiCalendarMonthOutline,
	mdiChevronDown,
	mdiClose,
	mdiContentCopy,
	mdiDownload,
	mdiFaceRecognition,
	mdiFolderMove,
	mdiFolderOutline,
	mdiImageAlbum,
	mdiImageFilterHdr,
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
import { fileUrl, inMemories, memoriesUrl } from '../api.js'
import { recentTargets as recent } from '../composables/useTransfer.js'
import { n, t } from '../l10n.js'
import { sensitiveIcon } from '../materialIcons.js'
import { sensitiveOn } from '../sensitive.js'
import { downloadZip } from '../zip.js'

const props = defineProps({
	count: { type: Number, required: true },
	fileIds: { type: Array, required: true },
	canSelectAll: { type: Boolean, default: false },
	busy: { type: Boolean, default: false },
	/** The selected item, if exactly one is selected. */
	single: { type: Object, default: null },
	photosEnabled: { type: Boolean, default: false },
	/** Memories' timeline folders, for "Open in Memories" */
	memoriesPaths: { type: Array, default: () => [] },
	/** {id, name}: the pictures are listed for this person, offer "Not {name}" */
	person: { type: Object, default: null },
	/** The text of the search the pictures come from, best first: offers "Sensitive up to here" */
	term: { type: String, default: '' },
})
defineEmits(['clear', 'selectAll', 'move', 'copy', 'favorite', 'tag', 'album', 'similar', 'faces', 'private', 'sensitive', 'upToHere', 'delete', 'notPerson', 'timeline'])

const shareHref = computed(() => {
	const dir = props.single.path.slice(0, props.single.path.lastIndexOf('/')) || '/'
	return generateUrl('/apps/files/files/{id}?dir={dir}&opendetails=true', { id: props.single.fileId, dir })
})
</script>

<style scoped lang="scss">
// Floats over the pictures at the bottom, like the app's dock while pictures are chosen
.selection-bar {
	position: fixed;
	z-index: 1001;
	left: 50%;
	bottom: max(20px, env(safe-area-inset-bottom));
	transform: translateX(-50%);
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	width: max-content;
	max-width: calc(100vw - 24px);
	padding: 8px 10px;
	border: 1px solid var(--g-line);
	border-radius: 30px;
	background: var(--g-raised);
	box-shadow: 0 12px 40px rgba(8, 9, 20, 0.45);

	&__count {
		padding: 4px 12px;
		border-radius: 14px;
		background: var(--g-accent);
		color: var(--g-on-accent);
		font-weight: 700;
		white-space: nowrap;
	}

	&__spacer { flex: 1; min-width: 8px; }

	&__split {
		display: flex;
		gap: 2px;
	}
}

@media (max-width: 600px) {
	.selection-bar {
		bottom: max(10px, env(safe-area-inset-bottom));
		justify-content: center;
		padding: 6px;
	}
}
</style>
