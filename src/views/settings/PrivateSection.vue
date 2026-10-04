<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="private-settings">
		<p class="hint">
			{{ t('Private pictures only show after you unlock them with the lock button – for this browser session, at most 30 minutes. They never appear in the Nextcloud search.') }}
		</p>
		<NcCheckboxRadioSwitch v-model="userPrefs.private_password" type="switch" @update:modelValue="savePref">
			{{ t('Ask for my password when unlocking') }}
		</NcCheckboxRadioSwitch>

		<h3>{{ t('Private folders') }}</h3>
		<ul v-if="privateState.folders.length" class="list">
			<li v-for="f in privateState.folders" :key="f">
				<code>{{ f }}</code>
				<NcButton variant="tertiary" :aria-label="t('Remove')" @click="removeFolder(f)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</li>
		</ul>
		<NcButton @click="addFolder">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFolderLockOutline" />
			</template>
			{{ t('Add folder …') }}
		</NcButton>

		<h3>{{ t('Private people') }}</h3>
		<p v-if="!privateState.people.length" class="hint">
			{{ t('Mark a person private on their page (lock button).') }}
		</p>
		<ul v-else class="list">
			<li v-for="id in privateState.people" :key="id">
				<img :src="faceUrl(id)" alt="" class="face">
				{{ nameOf(id) }}
				<NcButton variant="tertiary" :aria-label="t('Remove')" @click="removePerson(id)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</li>
		</ul>

		<h3>{{ t('Single pictures') }}</h3>
		<p class="hint">
			{{ n('{count} picture marked private (select pictures, then “Mark as private”).', '{count} pictures marked private (select pictures, then “Mark as private”).', privateState.files, { count: privateState.files }) }}
		</p>
	</div>
</template>

<script setup>
import { mdiClose, mdiFolderLockOutline } from '@mdi/js'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { faceUrl } from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'
import { peopleState } from '../../people.js'
import { saveUserPrefs, userPrefs } from '../../prefs.js'
import { loadPrivate, privateState, setPrivateLists } from '../../private.js'

const { act } = useAction()

const nameOf = (id) => peopleState.people.find((p) => p.id === id)?.name || t('Unnamed')

function savePref(on) {
	return act(async () => {
		await saveUserPrefs({ private_password: on })
		await loadPrivate()
	}, t('Saved'))
}

async function addFolder() {
	let chosen = null
	const picker = getFilePickerBuilder(t('Private folder'))
		.allowDirectories(true)
		.addMimeTypeFilter('httpd/unix-directory')
		.setButtonFactory((nodes, path) => [{
			label: t('Make private'),
			variant: 'primary',
			callback: (picked) => {
				chosen = picked[0]?.path ?? path
			},
		}])
		.build()
	await picker.pick().catch(() => null)
	if (chosen && chosen !== '/') {
		act(() => setPrivateLists([...privateState.folders, chosen], privateState.people), t('Saved'))
	}
}

const removeFolder = (f) => act(() => setPrivateLists(privateState.folders.filter((x) => x !== f), privateState.people), t('Saved'))
const removePerson = (id) => act(() => setPrivateLists(privateState.folders, privateState.people.filter((x) => x !== id)), t('Saved'))

onMounted(loadPrivate)
</script>

<style scoped lang="scss">
.private-settings {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 6px;
	max-width: 640px;

	h3 { margin-top: 16px; }
}

.list {
	display: flex;
	flex-direction: column;
	gap: 4px;

	li {
		display: flex;
		align-items: center;
		gap: 8px;
	}
}

.face {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	object-fit: cover;
}

.hint { color: var(--color-text-maxcontrast); }
</style>
