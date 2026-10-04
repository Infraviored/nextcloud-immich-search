/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate, translatePlural } from '@nextcloud/l10n'

// Short forms so every string is a plain t('…') call (and easy to extract into l10n/).
export const t = (text, vars) => translate('immich_search', text, vars)
export const n = (singular, plural, count, vars) => translatePlural('immich_search', singular, plural, count, vars)
