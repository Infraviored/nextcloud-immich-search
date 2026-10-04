/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'

// Two entry points: the app page and the admin settings section.
// Output goes to js/ as immich_search-<entry>.mjs, which Util::addScript loads.
export default createAppConfig({
	main: 'src/main.js',
	admin: 'src/admin.js',
}, {
	inlineCSS: { relativeCSSInjection: true },
	extractLicenseInformation: false,
})
