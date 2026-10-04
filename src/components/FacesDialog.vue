<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- The faces Immich found in one picture: boxes on the picture, and who each one is. -->
<template>
	<NcDialog :name="t('Faces in {name}', { name: item.name })" size="large" @closing="$emit('close')">
		<div class="faces">
			<div class="faces__picture">
				<img :src="thumbnailUrl(item.id, 'preview')" alt="">
				<div
					v-for="(f, i) in faces"
					:key="f.id"
					class="faces__box"
					:class="{ 'faces__box--active': active === f.id }"
					:style="{ left: f.x * 100 + '%', top: f.y * 100 + '%', width: f.w * 100 + '%', height: f.h * 100 + '%' }">
					<span>{{ i + 1 }}</span>
				</div>
			</div>
			<div class="faces__list">
				<NcLoadingIcon v-if="loading" />
				<p v-else-if="!faces.length" class="hint">
					{{ t('No faces found in this picture.') }}
				</p>
				<div
					v-for="(f, i) in faces"
					:key="f.id"
					class="face"
					@mouseenter="active = f.id"
					@mouseleave="active = null">
					<span class="face__no">{{ i + 1 }}</span>
					<NcSelect
						v-if="canManage"
						class="face__select"
						:modelValue="current(f)"
						:options="options"
						:taggable="true"
						:inputLabel="t('Person')"
						:placeholder="t('Who is this?')"
						label="name"
						:clearable="false"
						:disabled="busy"
						@update:modelValue="assign(f, $event)" />
					<span v-else class="face__name">{{ f.personName || t('Unnamed') }}</span>
					<NcButton
						v-if="canManage"
						variant="tertiary"
						:aria-label="t('Not a face')"
						:title="t('Not a face')"
						:disabled="busy"
						@click="remove(f)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCloseCircleOutline" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script setup>
import { mdiCloseCircleOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { deleteFace, getFaces, reassignFace, thumbnailUrl } from '../api.js'
import { useAction } from '../composables/useAction.js'
import { t } from '../l10n.js'
import { peopleState, reloadPeople } from '../people.js'

const props = defineProps({
	/** The picture: {id, name, ...} */
	item: { type: Object, required: true },
})
defineEmits(['close'])

const faces = ref([])
const canManage = ref(false)
const loading = ref(true)
const active = ref(null)
const { busy, act } = useAction()

const options = computed(() => peopleState.people.filter((p) => p.name).map((p) => ({ id: p.id, name: p.name })))

function current(f) {
	return f.personName ? { id: f.personId, name: f.personName } : null
}

async function assign(f, choice) {
	if (!choice) {
		return
	}
	const isNew = typeof choice === 'string' || !choice.id
	const name = typeof choice === 'string' ? choice : choice.name
	const res = await act(() => reassignFace(f.id, props.item.id, isNew ? { newName: name } : { personId: choice.id }), t('Saved'))
	if (res) {
		f.personId = res.personId
		f.personName = name
		if (isNew) {
			reloadPeople()
		}
	}
}

async function remove(f) {
	if (await act(() => deleteFace(f.id, props.item.id))) {
		faces.value = faces.value.filter((x) => x.id !== f.id)
		showSuccess(t('Face removed'))
	}
}

onMounted(async () => {
	const res = await act(() => getFaces(props.item.id))
	faces.value = res?.faces ?? []
	canManage.value = res?.canManage ?? false
	loading.value = false
})
</script>

<style scoped lang="scss">
.faces {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;

	&__picture {
		position: relative;
		flex: 1 1 360px;
		align-self: flex-start;

		img {
			display: block;
			width: 100%;
			border-radius: var(--border-radius);
		}
	}

	&__box {
		position: absolute;
		border: 2px solid rgba(255, 255, 255, 0.85);
		border-radius: 6px;
		box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.4);

		span {
			position: absolute;
			top: -22px;
			left: -2px;
			padding: 0 6px;
			border-radius: 4px;
			background: rgba(0, 0, 0, 0.6);
			color: #fff;
			font-size: 12px;
		}

		&--active {
			border-color: var(--color-primary-element);
			box-shadow: 0 0 0 2px var(--color-primary-element);
		}
	}

	&__list {
		display: flex;
		flex: 1 1 260px;
		flex-direction: column;
		gap: 8px;
	}
}

.face {
	display: flex;
	align-items: flex-end;
	gap: 8px;

	&__no {
		min-width: 24px;
		padding-bottom: 10px;
		font-weight: bold;
	}

	&__select {
		flex: 1;
	}

	&__name {
		flex: 1;
		padding-bottom: 10px;
	}
}

.hint { color: var(--color-text-maxcontrast); }
</style>
