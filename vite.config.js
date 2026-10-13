/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'

// Entry points: the app page, the admin settings section, the Files app integration.
// Output goes to js/ as glimpse-<entry>.mjs, which Util::addScript loads.
export default createAppConfig({
	main: 'src/main.js',
	admin: 'src/admin.js',
	files: 'src/files.js',
}, {
	inlineCSS: { relativeCSSInjection: true },
	extractLicenseInformation: false,
})
