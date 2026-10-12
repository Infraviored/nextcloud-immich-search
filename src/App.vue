<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent appName="glimpse">
		<NcAppNavigation :aria-label="t('Glimpse')">
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

		<!-- pageTitle, not pageHeading: with a heading the tab title got "[object Object]" for the app name -->
		<NcAppContent :pageTitle="t('Glimpse')">
			<div class="glimpse-page">
				<nav class="tabs" :aria-label="t('Glimpse')">
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
						class="tabs__extra"
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
						class="tabs__extra"
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
					:name="t('Glimpse is not set up yet')"
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
				<TimelineView v-else-if="route.name === 'timeline'" :key="'t' + (route.query.at || '') + privateState.version" />
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

		<!-- Over the Nextcloud viewer: jump to the picture in its folder -->
		<Teleport to="body">
			<a
				v-if="viewerState.current"
				class="viewer-folder"
				:href="folderUrl(viewerState.current)">
				<NcIconSvgWrapper :path="mdiFolderOpenOutline" :size="20" />
				{{ t('Show in folder') }}
			</a>
		</Teleport>
	</NcContent>
</template>

<script setup>
import { mdiAccountMultipleOutline, mdiCogOutline, mdiContentDuplicate, mdiFolderMultipleImage, mdiFolderOpenOutline, mdiImageSearchOutline, mdiLockOpenVariantOutline, mdiLockOutline, mdiMapMarkerOutline, mdiTimelineClockOutline } from '@mdi/js'
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
import { folderUrl, viewerState } from './viewer.js'

const page = loadState('glimpse', 'page', { configured: false, isAdmin: false })

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
// Not ".content": that is also the class of Nextcloud's own app container (NcContent's root),
// and a scoped rule reaches a child component's root – it padded the whole frame twice.
.glimpse-page {
	padding: 8px 16px 24px;
}

// Room for the navigation toggle button, which floats over the content's top left on wide screens.
@media (min-width: 1025px) {
	.glimpse-page { padding-left: 56px; }
}

// Narrower: the tabs sit beside the toggle instead of below it.
@media (max-width: 1024px) {
	.glimpse-page { padding-top: 6px; }
	.tabs { margin-inline-start: 44px; }
}

// Phones: every pixel goes to the pictures.
@media (max-width: 600px) {
	.glimpse-page { padding-inline: 8px; }
}

.tabs {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin-bottom: 14px;

	&__spacer { flex: 1; }
}

// Phones: icons only, all in one row; duplicates and settings stay in the side navigation.
@media (max-width: 600px) {
	.tabs {
		flex-wrap: nowrap;
		gap: 0;
	}

	.tabs__label,
	.tabs__extra {
		display: none;
	}
}

.viewer-folder {
	position: fixed;
	z-index: 10002;
	inset-block-end: max(16px, env(safe-area-inset-bottom));
	inset-inline-start: 50%;
	transform: translateX(-50%);
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 16px;
	border-radius: var(--border-radius-pill, 20px);
	background: rgba(0, 0, 0, 0.6);
	color: #fff;
	font-weight: bold;
	white-space: nowrap;

	&:hover, &:focus-visible {
		background: rgba(0, 0, 0, 0.8);
	}
}

.nav-face {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	object-fit: cover;
}
</style>

<style lang="scss">
// Phones: Nextcloud means to drop the frame around apps below 1024px
// (--body-container-margin: 0), but the theme sets the variable again on body. Drop it here.
@media (max-width: 600px) {
	#content-vue.app-glimpse {
		width: 100%;
	}

	#content.app-glimpse {
		width: 100%;
		height: calc(100% - var(--header-height));
		margin: var(--header-height) 0 0;
		border-radius: 0;
	}
}
</style>
