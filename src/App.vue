<template>
    <NcContent app-name="immich_search">
        <NcAppNavigation :aria-label="t('Photo search')">
            <template #list>
                <NcAppNavigationItem :name="t('Search')" :href="href('/')" :active="route.name === 'search'" @click.prevent="navigate('/')">
                    <template #icon>
                        <NcIconSvgWrapper :path="mdiImageSearchOutline" />
                    </template>
                </NcAppNavigationItem>
                <NcAppNavigationItem :name="t('People')"
                    :href="href('/people')"
                    :active="route.name === 'people'"
                    :allow-collapse="named.length > 0"
                    :open="true"
                    @click.prevent="navigate('/people')">
                    <template #icon>
                        <NcIconSvgWrapper :path="mdiAccountMultipleOutline" />
                    </template>
                    <NcAppNavigationItem v-for="p in named"
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
                    <NcAppNavigationItem :name="t('Settings')" @click.prevent="settingsOpen = true">
                        <template #icon>
                            <NcIconSvgWrapper :path="mdiCogOutline" />
                        </template>
                    </NcAppNavigationItem>
                </ul>
            </template>
        </NcAppNavigation>

        <NcAppContent :page-heading="t('Photo search')">
            <div class="content">
                <NcEmptyContent v-if="!page.configured"
                    :name="t('Photo search is not set up yet')"
                    :description="page.isAdmin ? t('Connect it to Immich in the settings.') : t('Ask an admin to connect it to Immich.')">
                    <template #icon>
                        <NcIconSvgWrapper :path="mdiImageSearchOutline" />
                    </template>
                    <template v-if="page.isAdmin" #action>
                        <NcButton variant="primary" @click="settingsOpen = true">{{ t('Open settings') }}</NcButton>
                    </template>
                </NcEmptyContent>
                <SearchView v-else-if="route.name === 'search'" :people="peopleState.people" />
                <PeopleView v-else-if="route.name === 'people'" />
                <PersonView v-else-if="route.name === 'person'" :id="route.id" :key="route.id" />
            </div>
        </NcAppContent>

        <SettingsDialog v-model:open="settingsOpen" :is-admin="page.isAdmin" />
    </NcContent>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiAccountMultipleOutline, mdiCogOutline, mdiImageSearchOutline } from '@mdi/js'
import SettingsDialog from './components/SettingsDialog.vue'
import PeopleView from './views/PeopleView.vue'
import PersonView from './views/PersonView.vue'
import SearchView from './views/SearchView.vue'
import { faceUrl } from './api.js'
import { t } from './l10n.js'
import { peopleState, reloadPeople } from './people.js'
import { href, navigate, route } from './router.js'

const page = loadState('immich_search', 'page', { configured: false, isAdmin: false })
const settingsOpen = ref(false)

/** Named, visible people for quick access in the navigation. */
const named = computed(() => peopleState.people.filter((p) => p.name && !p.hidden))

onMounted(() => {
    if (page.configured) reloadPeople()
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

.nav-face {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
}
</style>
