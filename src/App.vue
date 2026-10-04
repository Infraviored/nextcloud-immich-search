<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent appName="immich_search">
		<NcAppNavigation :aria-label="t('Photo search')">
			<template #list>
				<NcAppNavigationItem
					:name="t('Search')"
					:href="href('/')"
					:active="route.name === 'search'"
					@click.prevent="navigate('/')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiImageSearchOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('People')"
					:href="href('/people')"
					:active="route.name === 'people'"
					:allowCollapse="named.length > 0"
					:open="true"
					@click.prevent="navigate('/people')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAccountMultipleOutline" />
					</template>
					<NcAppNavigationItem
						v-for="p in named"
						:key="p.id"
						:name="p.name"
						:href="href('/people/' + p.id)"
						:active="route.name === 'person' && route.id === p.id"
						@click.prevent="navigate('/people/' + p.id)">
						<template #icon>
							<img class="nav-face" :src="faceUrl(p.id)" alt="">
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem
						:name="t('Settings')"
						:href="href('/settings')"
						:active="route.name === 'settings'"
						@click.prevent="navigate('/settings')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCogOutline" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent :pageHeading="t('Photo search')">
			<div class="content">
				<nav class="tabs" :aria-label="t('Photo search')">
					<NcButton :variant="route.name === 'search' ? 'primary' : 'tertiary'" :href="href('/')" @click.prevent="navigate('/')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiImageSearchOutline" />
						</template>
						{{ t('Search') }}
					</NcButton>
					<NcButton :variant="route.name === 'people' || route.name === 'person' ? 'primary' : 'tertiary'" :href="href('/people')" @click.prevent="navigate('/people')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAccountMultipleOutline" />
						</template>
						{{ t('People') }}
					</NcButton>
					<span class="tabs__spacer" />
					<NcButton
						variant="tertiary"
						:aria-label="t('Settings')"
						:title="t('Settings')"
						@click="navigate('/settings')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCogOutline" />
						</template>
					</NcButton>
				</nav>
				<NcEmptyContent
					v-if="!page.configured"
					:name="t('Photo search is not set up yet')"
					:description="page.isAdmin ? t('Connect it to Immich in the settings.') : t('Ask an admin to connect it to Immich.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiImageSearchOutline" />
					</template>
					<template v-if="page.isAdmin" #action>
						<NcButton variant="primary" @click="navigate('/settings')">
							{{ t('Open settings') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<SearchView v-else-if="route.name === 'search'" :people="peopleState.people" />
				<PeopleView v-else-if="route.name === 'people'" />
				<PersonView v-else-if="route.name === 'person'" :id="route.id" :key="route.id" />
				<SettingsView v-else-if="route.name === 'settings'" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { mdiAccountMultipleOutline, mdiCogOutline, mdiImageSearchOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { computed, onMounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import PeopleView from './views/PeopleView.vue'
import PersonView from './views/PersonView.vue'
import SearchView from './views/SearchView.vue'
import SettingsView from './views/SettingsView.vue'
import { faceUrl } from './api.js'
import { t } from './l10n.js'
import { peopleState, reloadPeople } from './people.js'
import { userPrefs } from './prefs.js'
import { href, navigate, route } from './router.js'

const page = loadState('immich_search', 'page', { configured: false, isAdmin: false })

/** Named, visible people for quick access in the navigation. */
const named = computed(() => peopleState.people.filter((p) => p.name && !p.hidden))

onMounted(() => {
	if (page.configured) {
		reloadPeople()
	}
	// Plain app URL: open the view the user chose as start view.
	if (route.name === 'search' && !route.query.q && !route.query.person && userPrefs.start_view === 'people') {
		window.history.replaceState(null, '', href('/people'))
		route.name = 'people'
	}
})
</script>

<style scoped lang="scss">
.content {
    padding: 8px 16px 24px;
}

// Room for the navigation toggle button, which floats over the content's top left on wide screens.
@media (min-width: 1025px) {
    .content { padding-left: 56px; }
}

// On narrow screens it sits above the content instead.
@media (max-width: 1024px) {
    .content { padding-top: 48px; }
}

.tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 14px;

    &__spacer { flex: 1; }
}

.nav-face {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
}
</style>
