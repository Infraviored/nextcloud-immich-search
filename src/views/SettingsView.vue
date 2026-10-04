<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="settings">
		<nav class="settings__nav" :aria-label="t('Settings')">
			<NcButton
				v-for="s in sections"
				:key="s.id"
				:variant="s.id === current ? 'secondary' : 'tertiary'"
				:href="href('/settings/' + s.id)"
				alignment="start"
				@click.prevent="navigate('/settings/' + s.id)">
				<template #icon>
					<NcIconSvgWrapper :path="s.icon" />
				</template>
				{{ s.name }}
			</NcButton>
		</nav>
		<section class="settings__body">
			<h2>{{ currentSection.name }}</h2>
			<component :is="currentSection.component" />
		</section>
	</div>
</template>

<script setup>
import { mdiAccountMultipleOutline, mdiChartBoxOutline, mdiFilterOutline, mdiLanConnect, mdiMagnify, mdiPaletteOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ConnectionSection from './settings/ConnectionSection.vue'
import DisplaySection from './settings/DisplaySection.vue'
import IndexingSection from './settings/IndexingSection.vue'
import PeopleSection from './settings/PeopleSection.vue'
import SearchSection from './settings/SearchSection.vue'
import StatusSection from './settings/StatusSection.vue'
import { t } from '../l10n.js'
import { href, navigate, route } from '../router.js'

const page = loadState('immich_search', 'page', { isAdmin: false })

const sections = [
	{ id: 'status', name: t('Status'), icon: mdiChartBoxOutline, component: StatusSection },
	{ id: 'display', name: t('Display'), icon: mdiPaletteOutline, component: DisplaySection },
	{ id: 'search', name: t('Nextcloud search'), icon: mdiMagnify, component: SearchSection },
	...(page.isAdmin
		? [
				{ id: 'indexing', name: t('What gets indexed'), icon: mdiFilterOutline, component: IndexingSection },
				{ id: 'people', name: t('People'), icon: mdiAccountMultipleOutline, component: PeopleSection },
				{ id: 'connection', name: t('Connection'), icon: mdiLanConnect, component: ConnectionSection },
			]
		: []),
]

const current = computed(() => (sections.some((s) => s.id === route.id) ? route.id : 'status'))
const currentSection = computed(() => sections.find((s) => s.id === current.value))
</script>

<style scoped lang="scss">
.settings {
	display: flex;
	flex-wrap: wrap;
	gap: 16px 32px;
	align-items: flex-start;

	&__nav {
		display: flex;
		flex: 0 0 220px;
		flex-direction: column;
		gap: 2px;
	}

	&__body {
		flex: 1 1 420px;
		min-width: 0;
		max-width: 820px;

		h2 {
			margin-top: 4px;
		}
	}
}

@media (max-width: 700px) {
	.settings__nav {
		flex: 1 1 100%;
		flex-direction: row;
		flex-wrap: wrap;
	}
}
</style>
