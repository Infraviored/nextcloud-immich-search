/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
import { loadFont } from './theme.js'

// Toasts and Nextcloud's dialogs bring their own styles, which an app has to load itself
import '@nextcloud/dialogs/style.css'

loadFont()
createApp(App).mount('#glimpse')
