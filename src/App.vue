<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent appName="immich_search">
		<NcAppNavigation :aria-label="t('Photo search')">
			<template #list>
				<NcAppNavigationItem
					v-for="v in views"
					:key="v.name"
					:name="v.label"
					:href="href(v.path)"
					:active="isActive(v)"
					:allowCollapse="v.name === 'people' && named.length > 0"
					:open="v.name === 'people'"
					@click.prevent="navigate(v.path)">
					<template #icon>
						<NcIconSvgWrapper :path="v.icon" />
					</template>
					<template v-if="v.name === 'people'">
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
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem
						:name="t('Duplicates')"
						:href="href('/duplicates')"
						:active="route.name === 'duplicates'"
						@click.prevent="navigate('/duplicates')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentDuplicate" />
						</template>
					</NcAppNavigationItem>
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
					<NcButton
						v-for="v in views"
						:key="v.name"
						:variant="isActive(v) ? 'primary' : 'tertiary'"
						:href="href(v.path)"
						:aria-label="v.label"
						:class="{ 'tabs__tab--active': isActive(v) }"
						class="tabs__tab"
						@click.prevent="navigate(v.path)">
						<template #icon>
							<NcIconSvgWrapper :path="v.icon" />
						</template>
						<span class="tabs__label">{{ v.label }}</span>
					</NcButton>
					<span class="tabs__spacer" />
					<NcButton
						variant="tertiary"
						:aria-label="privateState.unlocked ? t('Hide private pictures') : t('Show private pictures')"
						:title="privateState.unlocked ? t('Hide private pictures') : t('Show private pictures')"
						:pressed="privateState.unlocked"
						@click="togglePrivate">
						<template #icon>
							<NcIconSvgWrapper :path="privateState.unlocked ? mdiLockOpenVariantOutline : mdiLockOutline" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('Duplicates')"
						:title="t('Duplicates')"
						:pressed="route.name === 'duplicates'"
						@click="navigate('/duplicates')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentDuplicate" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('Settings')"
						:title="t('Settings')"
						:pressed="route.name === 'settings'"
						@click="navigate('/settings')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCogOutline" />
						</template>
					</NcButton>
				</nav>

				<NcEmptyContent
					v-if="!page.configured && route.name !== 'settings'"
					:name="t('Photo search is not set up yet')"
					:description="page.isAdmin ? t('Connect it to Immich in the settings.') : t('Ask an admin to connect it to Immich.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiImageSearchOutline" />
					</template>
					<template v-if="page.isAdmin" #action>
						<NcButton variant="primary" @click="navigate('/settings/connection')">
							{{ t('Open settings') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<SearchView v-else-if="route.name === 'search'" :key="searchKey + privateState.version" :people="peopleState.people" />
				<TimelineView v-else-if="route.name === 'timeline'" :key="'t' + privateState.version" />
				<MapView v-else-if="route.name === 'map'" :key="'m' + privateState.version" />
				<FolderView v-else-if="route.name === 'folders'" :key="'f' + privateState.version" />
				<PeopleView v-else-if="route.name === 'people'" :key="'p' + privateState.version" />
				<PersonView v-else-if="route.name === 'person'" :id="route.id" :key="route.id + privateState.version" />
				<DuplicatesView v-else-if="route.name === 'duplicates'" :key="'d' + privateState.version" />
				<SettingsView v-else-if="route.name === 'settings'" />
				<!-- Opened from the Files sidebar: ?faces=<assetId>&name=<file name> -->
				<FacesDialog
					v-if="route.query.faces"
					:item="{ id: route.query.faces, name: route.query.name || '' }"
					@close="replaceQuery({})" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { mdiAccountMultipleOutline, mdiCogOutline, mdiContentDuplicate, mdiFolderMultipleImage, mdiImageSearchOutline, mdiLockOpenVariantOutline, mdiLockOutline, mdiMapMarkerOutline, mdiTimelineClockOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { computed, onMounted, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import FacesDialog from './components/FacesDialog.vue'
import DuplicatesView from './views/DuplicatesView.vue'
import FolderView from './views/FolderView.vue'
import MapView from './views/MapView.vue'
import PeopleView from './views/PeopleView.vue'
import PersonView from './views/PersonView.vue'
import SearchView from './views/SearchView.vue'
import SettingsView from './views/SettingsView.vue'
import TimelineView from './views/TimelineView.vue'
import { faceUrl } from './api.js'
import { t } from './l10n.js'
import { peopleState, reloadPeople } from './people.js'
import { userPrefs } from './prefs.js'
import { loadPrivate, privateState, togglePrivate } from './private.js'
import { href, navigate, replaceQuery, route } from './router.js'

const page = loadState('immich_search', 'page', { configured: false, isAdmin: false })

const views = [
	{ name: 'search', path: '/', label: t('Search'), icon: mdiImageSearchOutline },
	{ name: 'timeline', path: '/timeline', label: t('Timeline'), icon: mdiTimelineClockOutline },
	{ name: 'people', path: '/people', label: t('People'), icon: mdiAccountMultipleOutline, also: ['person'] },
	{ name: 'map', path: '/map', label: t('Map'), icon: mdiMapMarkerOutline },
	{ name: 'folders', path: '/folders', label: t('Folders'), icon: mdiFolderMultipleImage },
]

const isActive = (v) => route.name === v.name || (v.also ?? []).includes(route.name)

/** Named, visible people for quick access in the navigation. */
const named = computed(() => peopleState.people.filter((p) => p.name && !p.hidden))

// Private people come and go with the lock
watch(() => privateState.version, () => reloadPeople())

// A new "similar" or query from elsewhere (timeline, map) starts a fresh search view.
const searchKey = computed(() => (route.name === 'search' ? (route.query.similar ?? '') : ''))

onMounted(() => {
	if (page.configured) {
		reloadPeople()
		loadPrivate()
	}
	// Plain app URL: open the view the user chose as start view.
	const start = views.find((v) => v.name === userPrefs.start_view)
	if (route.name === 'search' && !Object.keys(route.query).length && start && start.name !== 'search') {
		window.history.replaceState(null, '', href(start.path))
		route.name = start.name
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
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin-bottom: 14px;

	&__spacer { flex: 1; }
}

// Phones: icons only, except for the active tab.
@media (max-width: 600px) {
	.tabs__tab:not(.tabs__tab--active) .tabs__label {
		display: none;
	}
}

.nav-face {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	object-fit: cover;
}
</style>
