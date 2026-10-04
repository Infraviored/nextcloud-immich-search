<template>
    <div class="selection-bar" role="toolbar" :aria-label="t('Selection')">
        <NcButton variant="tertiary" :aria-label="t('Clear selection')" @click="$emit('clear')">
            <template #icon>
                <NcIconSvgWrapper :path="mdiClose" />
            </template>
        </NcButton>
        <span class="selection-bar__count">{{ n('{count} selected', '{count} selected', count, { count }) }}</span>
        <NcButton v-if="canSelectAll" variant="tertiary" @click="$emit('select-all')">
            {{ t('Select all') }}
        </NcButton>
        <span class="selection-bar__spacer" />
        <NcButton v-if="folderHref" variant="tertiary" :href="folderHref">
            <template #icon>
                <NcIconSvgWrapper :path="mdiFolderOutline" />
            </template>
            {{ t('Show in folder') }}
        </NcButton>
        <NcButton :disabled="busy" @click="$emit('copy')">
            <template #icon>
                <NcIconSvgWrapper :path="mdiContentCopy" />
            </template>
            {{ t('Copy') }}
        </NcButton>
        <NcButton variant="primary" :disabled="busy" @click="$emit('move')">
            <template #icon>
                <NcLoadingIcon v-if="busy" />
                <NcIconSvgWrapper v-else :path="mdiFolderMove" />
            </template>
            {{ t('Move') }}
        </NcButton>
    </div>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { mdiClose, mdiContentCopy, mdiFolderMove, mdiFolderOutline } from '@mdi/js'
import { n, t } from '../l10n.js'

defineProps({
    count: { type: Number, required: true },
    canSelectAll: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    /** Link to the folder of the one selected file, if exactly one is selected. */
    folderHref: { type: String, default: '' },
})
defineEmits(['clear', 'select-all', 'move', 'copy'])
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
}
</style>
