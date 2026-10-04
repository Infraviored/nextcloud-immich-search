import { ref } from 'vue'
import { getFilePickerBuilder, showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { errorMessage, transfer } from '../api.js'
import { n, t } from '../l10n.js'

/** Folder picker + move/copy of selected files, with the result as a toast. */
export function useTransfer() {
    const busy = ref(false)

    /** @return {Promise<{target: string, done: number, failedIds: number[]}|null>} null when cancelled */
    async function run(fileIds, mode) {
        const target = await pickFolder(mode)
        if (target === null) {
            return null
        }
        busy.value = true
        try {
            const res = await transfer(fileIds, target, mode)
            const where = target === '/' ? t('Files') : target
            const msg = mode === 'move'
                ? n('Moved {count} file to {folder}', 'Moved {count} files to {folder}', res.done, { count: res.done, folder: where })
                : n('Copied {count} file to {folder}', 'Copied {count} files to {folder}', res.done, { count: res.done, folder: where })
            if (res.failed.length) {
                showWarning(msg + ' · ' + n('{count} not allowed', '{count} not allowed', res.failed.length, { count: res.failed.length }))
            } else {
                showSuccess(msg)
            }
            return { target, done: res.done, failedIds: res.failed.map((f) => f.fileId) }
        } catch (e) {
            showError(errorMessage(e))
            return null
        } finally {
            busy.value = false
        }
    }

    return { busy, run }
}

function pickFolder(mode) {
    let chosen = null
    const label = mode === 'move' ? t('Move here') : t('Copy here')
    const picker = getFilePickerBuilder(mode === 'move' ? t('Move to …') : t('Copy to …'))
        .setMultiSelect(false)
        .allowDirectories(true)
        .addMimeTypeFilter('httpd/unix-directory')
        .setButtonFactory((nodes, currentPath) => [{
            label: nodes[0] ? t('{label} ({folder})', { label, folder: nodes[0].basename }) : label,
            variant: 'primary',
            callback: (picked) => { chosen = picked[0]?.path ?? currentPath },
        }])
        .build()
    return picker.pick().then(() => chosen, () => null)
}
