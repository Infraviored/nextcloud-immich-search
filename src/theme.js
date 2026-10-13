/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateFilePath } from '@nextcloud/router'

import './styles/theme.scss'

/**
 * Bricolage Grotesque, shipped with the app (Nextcloud's content policy allows fonts only
 * from its own origin). Until it has loaded, headings use Nextcloud's font.
 */
export function loadFont() {
	if (!window.FontFace || !document.fonts) {
		return
	}
	try {
		const face = new FontFace('Glimpse Bricolage', `url(${generateFilePath('glimpse', 'img', 'bricolage.woff2')}) format('woff2')`, {
			weight: '200 800',
			stretch: '75% 100%',
			display: 'swap',
		})
		document.fonts.add(face)
		face.load().catch(() => {
			// headings fall back to Nextcloud's font
		})
	} catch {
		// headings fall back to Nextcloud's font
	}
}
