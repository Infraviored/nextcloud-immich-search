/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Loaded into the Files app: a "Similar pictures" action, a sidebar tab with the
// people in a picture, and a workaround for the viewer freezing in large folders.
import { mdiImageMultipleOutline, mdiImageSearchOutline } from '@mdi/js'
import { registerFileAction, registerSidebarTab } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { defineCustomElement } from 'vue'
import SidebarTab from './components/SidebarTab.vue'
import { assetForFile } from './api.js'
import { t } from './l10n.js'
import { installViewerFix } from './viewerFix.js'

const svg = (path) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${path}"/></svg>`
const isPicture = (node) => /^(image|video)\//.test(node?.mime ?? '')
const TAG = 'immich-search-sidebar-tab'

registerFileAction({
	id: 'immich-search-similar',
	displayName: () => t('Similar pictures'),
	iconSvgInline: () => svg(mdiImageMultipleOutline),
	enabled: ({ nodes }) => nodes.length === 1 && isPicture(nodes[0]),
	order: 60,
	async exec({ nodes }) {
		const node = nodes[0]
		try {
			const asset = await assetForFile(node.fileid)
			window.location.href = generateUrl('/apps/immich_search/?similar={id}&name={name}', { id: asset.id, name: node.basename })
			return null
		} catch {
			return false // not indexed (yet): Files shows "action failed"
		}
	},
})

registerSidebarTab({
	id: 'immich_search',
	displayName: t('People'),
	iconSvgInline: svg(mdiImageSearchOutline),
	order: 90,
	tagName: TAG,
	enabled: ({ node }) => isPicture(node),
	async onInit() {
		if (!window.customElements.get(TAG)) {
			// No shadow DOM: the tab uses Nextcloud's global styles like everything around it.
			window.customElements.define(TAG, defineCustomElement(SidebarTab, { shadowRoot: false }))
		}
	},
})

installViewerFix()
