/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		ignores: ['js/**', 'build/**', 'vendor/**', 'l10n/**'],
	},
	{
		// Small, self-explaining helpers: a JSDoc block per arrow function is noise.
		rules: {
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/require-param-description': 'off',
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-returns': 'off',
			'jsdoc/require-returns-description': 'off',
		},
	},
]
