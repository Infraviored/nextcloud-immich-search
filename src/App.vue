<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent appName="glimpse">
		<!-- pageTitle, not pageHeading: with a heading the tab title got "[object Object]" for the app name -->
		<NcAppContent :pageTitle="t('Glimpse')">
			<div class="glimpse" :class="{ 'glimpse--narrow': narrow }">
				<header class="bar">
					<a class="bar__brand g-display" :href="href(startPath)" @click.prevent="navigate(startPath)">Glimpse</a>
					<nav v-if="!narrow" class="tabs" :aria-label="t('Glimpse')">
						<a
							v-for="v in views"
							:key="v.name"
							class="tabs__tab"
							:class="{ 'tabs__tab--active': isActive(v) }"
							:href="href(v.path)"
							:aria-current="isActive(v) ? 'page' : undefined"
							@click.prevent="navigate(v.path)">{{ v.label }}</a>
					</nav>
					<LensSearch
						v-if="!narrow && page.configured && searchable"
						v-model="searchText"
						class="bar__search"
						:placeholder="t('Search your photos')"
						@submit="search"
						@clear="route.name === 'search' && search()" />
					<div class="bar__tools">
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
					</div>
				</header>

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
				<SearchView v-else-if="route.name === 'search'" :key="searchKey + version" :people="peopleState.people" />
				<TimelineView v-else-if="route.name === 'timeline'" :key="'t' + (route.query.at || '') + version" />
				<MapView v-else-if="route.name === 'map'" :key="'m' + version" />
				<FolderView v-else-if="route.name === 'folders'" :key="'f' + version" />
				<PeopleView v-else-if="route.name === 'people'" :key="'p' + version" />
				<PersonView v-else-if="route.name === 'person'" :id="route.id" :key="route.id + version" />
				<DuplicatesView v-else-if="route.name === 'duplicates'" :key="'d' + version" />
				<SensitiveView v-else-if="route.name === 'sensitive' && sensitiveOn" :key="'s' + version" />
				<NcEmptyContent
					v-else-if="route.name === 'sensitive'"
					:name="t('Sensitive pictures')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiEyeOffOutline" />
					</template>
				</NcEmptyContent>
				<SettingsView v-else-if="route.name === 'settings'" />
				<!-- Opened from the Files sidebar: ?faces=<assetId>&name=<file name> -->
				<FacesDialog
					v-if="route.query.faces"
					:item="{ id: route.query.faces, name: route.query.name || '' }"
					@close="replaceQuery({})" />
			</div>
		</NcAppContent>

		<!-- Phones: search and the views at the bottom, in thumb's reach, as in the app -->
		<!-- At the end of the page: Nextcloud's content frame stacks above anything inside it -->
		<Teleport to="body">
			<div v-if="narrow && !selectionActive" class="dock">
				<LensSearch
					v-if="page.configured && searchable"
					v-model="searchText"
					class="dock__search"
					:placeholder="t('Search your photos')"
					@submit="search"
					@clear="route.name === 'search' && search()" />
				<nav class="tabs tabs--dock" :aria-label="t('Glimpse')">
					<a
						v-for="v in views"
						:key="v.name"
						class="tabs__tab"
						:class="{ 'tabs__tab--active': isActive(v) }"
						:href="href(v.path)"
						:aria-current="isActive(v) ? 'page' : undefined"
						@click.prevent="navigate(v.path)">{{ v.label }}</a>
				</nav>
			</div>
		</Teleport>

		<!-- Over the Nextcloud viewer: what the app's viewer offers for the picture -->
		<ViewerBar />
	</NcContent>
</template>

<script setup>
import { mdiCogOutline, mdiContentDuplicate, mdiImageSearchOutline, mdiLockOpenVariantOutline, mdiLockOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import FacesDialog from './components/FacesDialog.vue'
import LensSearch from './components/LensSearch.vue'
import ViewerBar from './components/ViewerBar.vue'
import DuplicatesView from './views/DuplicatesView.vue'
import FolderView from './views/FolderView.vue'
import MapView from './views/MapView.vue'
import PeopleView from './views/PeopleView.vue'
import PersonView from './views/PersonView.vue'
import SearchView from './views/SearchView.vue'
import SensitiveView from './views/SensitiveView.vue'
import SettingsView from './views/SettingsView.vue'
import TimelineView from './views/TimelineView.vue'
import { t } from './l10n.js'
import { peopleState, reloadPeople } from './people.js'
import { userPrefs } from './prefs.js'
import { loadPrivate, privateState, togglePrivate } from './private.js'
import { href, navigate, replaceQuery, route } from './router.js'
import { selectionActive } from './selection.js'
import { sensitiveOn, sensitiveState } from './sensitive.js'
import { viewerState } from './viewer.js'

const page = loadState('glimpse', 'page', { configured: false, isAdmin: false })

// Searching has no tab: the search pill is always there.
const views = [
	{ name: 'timeline', path: '/timeline', label: t('Timeline') },
	{ name: 'people', path: '/people', label: t('People'), also: ['person'] },
	{ name: 'map', path: '/map', label: t('Map') },
	{ name: 'folders', path: '/folders', label: t('Folders') },
]

/** The view chosen by the user to start with; clicking the brand goes here. */
const startPath = computed(() => {
	const start = views.find((v) => v.name === userPrefs.start_view)
	return start ? start.path : '/'
})

const isActive = (v) => route.name === v.name || (v.also ?? []).includes(route.name)

// The viewer's action bar needs room below the picture
watch(() => !!viewerState.current, (open) => document.body.classList.toggle('glimpse-viewer-bar', open))

// Private people come and go with the lock
watch(() => privateState.version, () => reloadPeople())

// Navigating to /sensitive while the feature is off redirects
watch([() => route.name, sensitiveOn], ([name, on]) => {
	if (name === 'sensitive' && !on) {
		navigate(page.sensitiveAvailable ? '/settings/sensitive' : '/')
	}
}, { immediate: true })

/** Views load again when locking or the sensitive mode changes what they may show. */
const version = computed(() => privateState.version + '.' + sensitiveState.version)

/** On the button while not everything is shown, so the filter is never forgotten. */
/** Pages where searching makes no sense have no search pill */
const searchable = computed(() => !['settings', 'sensitive', 'duplicates'].includes(route.name))

/** The text in the search pill; it shows the current search while one is open. */
const searchText = ref(route.name === 'search' ? (route.query.q || '') : '')
watch(() => (route.name === 'search' ? (route.query.q ?? '') : null), (q) => {
	if (q !== null && !route.query.similar) {
		searchText.value = q
	} else if (q === null) {
		searchText.value = ''
	}
})
/** Every search from the pill starts a fresh search view. */
const searchRun = ref(0)
const searchKey = computed(() => (route.name === 'search' ? (route.query.similar ?? '') + '/' + searchRun.value : ''))

/** Search for the pill's text; type, person and tag filters stay as they were. */
function search() {
	const q = searchText.value.trim()
	const keep = route.name === 'search' ? { type: route.query.type, person: route.query.person, tag: route.query.tag } : {}
	navigate('/', { ...keep, q })
	searchRun.value++
}

/** Phones: navigation goes to a dock at the bottom. */
const phone = window.matchMedia('(max-width: 600px)')
const narrow = ref(phone.matches)
function onWidth() {
	narrow.value = phone.matches
}

onMounted(() => {
	phone.addEventListener('change', onWidth)
	if (page.configured) {
		reloadPeople()
		loadPrivate()
	}
	// Plain app URL: open the view the user chose as start view.
	const start = views.find((v) => v.name === userPrefs.start_view)
	if (route.name === 'search' && !Object.keys(route.query).length && start) {
		window.history.replaceState(null, '', href(start.path))
		route.name = start.name
	}
})
onBeforeUnmount(() => phone.removeEventListener('change', onWidth))
</script>

<style scoped lang="scss">
// Not ".content": that is also the class of Nextcloud's own app container (NcContent's root),
// and a scoped rule reaches a child component's root – it padded the whole frame twice.
.glimpse {
	min-height: 100%;
	padding: 0 28px 48px;
	background: var(--g-bg);
	color: var(--g-text);

	&--narrow {
		padding: 0 0 calc(132px + env(safe-area-inset-bottom));
	}
}

.bar {
	position: sticky;
	top: 0;
	z-index: 20;
	display: flex;
	align-items: center;
	gap: 8px 28px;
	margin: 0 -28px 12px;
	padding: 14px 28px;
	background: var(--g-scrim);
	backdrop-filter: blur(12px);

	&__brand {
		flex: 0 0 auto;
		color: var(--g-text);
		font-size: 34px;
		text-decoration: none;
	}

	&__search {
		flex: 0 1 420px;
		margin-inline-start: auto;
	}

	&__tools {
		display: flex;
		flex: 0 0 auto;
		gap: 2px;

		// The open page or the unlocked area: apricot, like everything chosen
		:deep(.button-vue[aria-pressed='true']) {
			background: var(--g-raised);
			color: var(--g-accent-text);
		}
	}
}

.glimpse--narrow .bar {
	position: static;
	margin: 0 0 4px;
	padding: 14px 12px 6px 16px;
	background: none;
	backdrop-filter: none;

	.bar__tools {
		margin-inline-start: auto;
	}
}

// The views as words, the chosen one marked by the apricot sun beneath it
.tabs {
	display: flex;
	gap: 4px;

	&__tab {
		position: relative;
		padding: 8px 12px 12px;
		border-radius: 12px;
		color: var(--g-muted);
		font-size: 16px;
		font-weight: 600;
		text-decoration: none;
		transition: color 0.15s ease;

		&:hover, &:focus-visible {
			color: var(--g-text);
		}

		&--active {
			color: var(--g-text);

			&::after {
				content: '';
				position: absolute;
				inset-inline-start: 50%;
				bottom: 2px;
				width: 6px;
				height: 6px;
				margin-inline-start: -3px;
				border-radius: 50%;
				background: var(--g-accent-text);
			}
		}
	}

	&--dock {
		justify-content: space-around;

		.tabs__tab {
			font-size: 17px;
		}
	}
}

.dock {
	position: fixed;
	z-index: 1000;
	inset: auto 0 0 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 24px 16px max(6px, env(safe-area-inset-bottom));
	background: linear-gradient(transparent, var(--g-bg) 28px);
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
