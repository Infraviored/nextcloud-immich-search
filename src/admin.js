/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import AdminForm from './components/AdminForm.vue'
import { t } from './l10n.js'

createApp({
	render: () => h(NcSettingsSection, {
		name: t('Glimpse (Immich)'),
		description: t('One connection for everybody. Immich indexes the files of all users; everyone only finds what they can open in Nextcloud themselves.'),
	}, () => [
		h(AdminForm),
		h('p', { style: 'margin-top: 16px' }, [
			h('a', { href: generateUrl('/apps/glimpse/settings/indexing'), class: 'button' }, t('More settings in the app: indexing, search, people, status')),
		]),
	]),
}).mount('#glimpse-admin')
