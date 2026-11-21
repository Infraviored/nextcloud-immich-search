<!--
  - SPDX-FileCopyrightText: 2026 Florian Schneider
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!-- Sensitive pictures: on or off, what the filter hides, training it. Only when the admin allows it. -->
<template>
	<div class="sensitive">
		<p class="hint">
			{{ t('A filter that hides the pictures you consider sensitive. You train it yourself: search for something, hide what matches, then check what it hides. Works with what Immich already knows about each picture; nothing leaves this server. Only you see your marks.') }}
		</p>

		<!-- Pending invitations to use someone else's filter -->
		<div v-if="status?.invitations?.length" class="sensitive__invitations">
			<h3>{{ t('Pending invitations') }}</h3>
			<div v-for="inv in status.invitations" :key="inv.id" class="sensitive__invitation">
				<p>
					{{ t('{name} shares their sensitive filter with you (role: {role}).', { name: inv.ownerName || inv.owner, role: roleLabel(inv.role) }) }}
				</p>
				<div class="sensitive__invitation-actions">
					<NcButton
						variant="primary"
						:disabled="busy"
						@click="acceptInvitation(inv.id)">
						{{ t('Use it') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="busy"
						@click="declineInvitation(inv.id)">
						{{ t('Decline') }}
					</NcButton>
				</div>
			</div>
		</div>

		<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_enabled" type="switch" @update:modelValue="toggleSensitive">
			{{ t('Mark sensitive pictures') }}
		</NcCheckboxRadioSwitch>
		<template v-if="userPrefs.sensitive_enabled">
			<p class="sensitive__state" aria-live="polite">
				{{ sensitiveLine }}
			</p>
			<ul v-if="status?.terms?.length" class="sensitive__terms">
				<li v-for="term in status.terms" :key="term.q">
					<a :href="searchHref(term.q)" @click.prevent="navigate('/search', { q: term.q })">“{{ term.q }}”</a>
					<span class="hint">
						{{ term.stale ? t('made with another search model, set it again') : n('{count} picture', '{count} pictures', term.count, { count: term.count }) }}
					</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('Remove “{q}”', { q: term.q })"
						@click="removeTerm(term.q)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClose" />
						</template>
					</NcButton>
				</li>
			</ul>

			<!-- Filter block (personal or shared) -->
			<div v-if="status?.filter" class="sensitive__filter-box">
				<div class="sensitive__filter-header">
					<div>
						<h3>{{ filterHeadline }}</h3>
						<p class="hint">
							{{ filterDescription }}
						</p>
					</div>
					<NcButton
						v-if="canLeaveOrSwitch"
						variant="secondary"
						:disabled="busy"
						@click="leaveOrSwitch">
						{{ t('Use my own filter') }}
					</NcButton>
				</div>

				<!-- Member management for owner -->
				<template v-if="isOwner">
					<div v-if="status.filter.members?.length > 1" class="sensitive__members">
						<h4>{{ t('Members') }}</h4>
						<ul class="sensitive__member-list">
							<li v-for="m in status.filter.members" :key="m.uid" class="sensitive__member">
								<span class="sensitive__member-name">
									{{ m.name || m.uid }}
									<span v-if="m.uid === status.filter.owner" class="hint">({{ t('Owner') }})</span>
								</span>
								<div v-if="m.uid !== status.filter.owner" class="sensitive__member-actions">
									<NcSelect
										:modelValue="roleOptions.find((r) => r.id === m.role) || roleOptions[0]"
										:options="roleOptions"
										:clearable="false"
										:disabled="busy"
										label="label"
										class="sensitive__role-select"
										@update:modelValue="changeMemberRole(m.uid, $event?.id || $event)" />
									<NcButton
										variant="tertiary"
										:disabled="busy"
										:aria-label="t('Remove member')"
										@click="removeMember(m.uid)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiClose" />
										</template>
									</NcButton>
								</div>
							</li>
						</ul>
					</div>

					<!-- Pending invitations sent by owner -->
					<div v-if="status.filter.invitations?.length" class="sensitive__pending-invites">
						<h4>{{ t('Sent invitations') }}</h4>
						<ul class="sensitive__member-list">
							<li v-for="inv in status.filter.invitations" :key="inv.uid" class="sensitive__member">
								<span class="sensitive__member-name">
									{{ inv.name || inv.uid }}
									<span class="hint">({{ roleLabel(inv.role) }}) — {{ t('Waiting for response') }}</span>
								</span>
								<NcButton
									variant="tertiary"
									:disabled="busy"
									:aria-label="t('Cancel invitation')"
									@click="cancelInvite(inv.uid)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiClose" />
									</template>
								</NcButton>
							</li>
						</ul>
					</div>

					<!-- Share filter with another user -->
					<div class="sensitive__invite-box">
						<h4>{{ t('Share filter with another user') }}</h4>
						<div class="sensitive__invite-form">
							<NcSelect
								v-model="inviteTarget"
								:options="foundUsers"
								:filterable="false"
								:placeholder="t('Search user')"
								label="label"
								:loading="searchingUsers"
								class="sensitive__invite-user"
								@search="searchUsers">
								<template #no-options>
									{{ userSearchTerm ? t('Nobody found') : t('Type a name') }}
								</template>
							</NcSelect>
							<NcSelect
								v-model="inviteRole"
								:options="roleOptions"
								:clearable="false"
								label="label"
								class="sensitive__invite-role" />
							<NcButton
								variant="primary"
								:disabled="!inviteTarget || busy"
								@click="sendInvite">
								{{ t('Share') }}
							</NcButton>
						</div>
					</div>
				</template>
			</div>

			<h3>{{ t('Show') }}</h3>
			<NcCheckboxRadioSwitch
				v-for="m in sensitiveModes"
				:key="m.value"
				:modelValue="userPrefs.sensitive_mode"
				type="radio"
				:value="m.value"
				name="sensitive-mode"
				@update:modelValue="setSensitiveMode(m.value)">
				{{ m.label }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="userPrefs.sensitive_badge" type="switch" @update:modelValue="save({ sensitive_badge: $event })">
				{{ t('Show a small mark on sensitive pictures') }}
			</NcCheckboxRadioSwitch>
			<div class="sensitive__actions">
				<NcButton variant="primary" @click="navigate('/sensitive')">
					{{ t('Train the sensitive filter') }}
				</NcButton>
				<NcButton @click="review">
					{{ t('Review sensitive pictures') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="busy"
					@click="recompute">
					{{ t('Recompute now') }}
				</NcButton>
			</div>
			<p class="hint">
				{{ t('Mark single pictures as sensitive or not in the viewer or with a selection. Your mark always counts for the picture, and from 20 marks on Glimpse learns from them.') }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { mdiClose } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { findRecipients } from '../../albums.js'
import {
	acceptSensitiveInvitation,
	declineSensitiveInvitation,
	getSensitive,
	inviteSensitiveMember,
	leaveSensitiveFilter,
	rebuildSensitive,
	removeSensitiveMember,
	removeSensitiveTerm,
	updateSensitiveMemberRole,
} from '../../api.js'
import { useAction } from '../../composables/useAction.js'
import { n, t } from '../../l10n.js'
import { saveUserPrefs, userPrefs } from '../../prefs.js'
import { href, navigate } from '../../router.js'
import { sensitiveModes, sensitiveState, setSensitiveMode } from '../../sensitive.js'
import {
	filterRecipientCandidates,
	formatFilterDescription,
	formatFilterHeadline,
	roleLabel,
} from '../../sensitiveFilter.js'

const { act } = useAction()

const save = (changes) => act(() => saveUserPrefs(changes), t('Saved'))

const status = ref(null)
const busy = ref(false)
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })

const roleOptions = [
	{ id: 'editor', label: t('Editor (trains filter)') },
	{ id: 'user', label: t('User (only uses filter)') },
]

const isOwner = computed(() => status.value?.filter?.role === 'owner')
const canLeaveOrSwitch = computed(() => !!status.value?.filter && !isOwner.value)

const filterHeadline = computed(() => formatFilterHeadline(status.value?.filter))
const filterDescription = computed(() => formatFilterDescription(status.value?.filter))

const sensitiveLine = computed(() => {
	const s = status.value
	if (!s) {
		return ''
	}
	if (s.error) {
		return t('Last attempt failed: {error}', { error: s.error })
	}
	const parts = [n('{count} picture counts as sensitive.', '{count} pictures count as sensitive.', s.flagged ?? 0, { count: s.flagged ?? 0 })]
	if (s.marked + s.unmarked) {
		parts.push(n('{count} picture marked by you.', '{count} pictures marked by you.', s.marked + s.unmarked, { count: s.marked + s.unmarked }))
	}
	if (s.model) {
		parts.push(n('{count} more by what Glimpse learned from your marks.', '{count} more by what Glimpse learned from your marks.', s.byModel ?? 0, { count: s.byModel ?? 0 }))
	}
	if (s.built) {
		parts.push(t('Last computed {date}.', { date: dateFormat.format(new Date(s.built * 1000)) }))
	}
	return parts.join(' ')
})

// User search & invite state
const inviteTarget = ref(null)
const inviteRole = ref(roleOptions[0])
const foundUsers = ref([])
const searchingUsers = ref(false)
const userSearchTerm = ref('')
let userSearchCtrl = null
let userSearchTimer = null

function searchUsers(term) {
	userSearchTerm.value = term
	clearTimeout(userSearchTimer)
	userSearchCtrl?.abort()
	if (!term) {
		foundUsers.value = []
		searchingUsers.value = false
		return
	}
	searchingUsers.value = true
	userSearchTimer = setTimeout(async () => {
		const ctrl = (userSearchCtrl = new AbortController())
		try {
			const res = await findRecipients(term, ctrl.signal)
			if (ctrl === userSearchCtrl) {
				const existing = status.value?.filter?.members?.map((m) => m.uid) ?? []
				foundUsers.value = filterRecipientCandidates(res, existing)
			}
		} catch {
			if (ctrl === userSearchCtrl) {
				foundUsers.value = []
			}
		} finally {
			if (ctrl === userSearchCtrl) {
				searchingUsers.value = false
			}
		}
	}, 250)
}

async function sendInvite() {
	if (!inviteTarget.value?.id) {
		return
	}
	busy.value = true
	const role = inviteRole.value?.id || inviteRole.value || 'editor'
	const res = await act(() => inviteSensitiveMember(inviteTarget.value.id, role), t('Invitation sent'))
	busy.value = false
	if (res) {
		status.value = res
		inviteTarget.value = null
		foundUsers.value = []
	}
}

async function acceptInvitation(id) {
	busy.value = true
	const res = await act(() => acceptSensitiveInvitation(id), t('Invitation accepted'))
	busy.value = false
	if (res) {
		userPrefs.sensitive_enabled = true
		status.value = res
		sensitiveState.version++
	}
}

async function declineInvitation(id) {
	busy.value = true
	const res = await act(() => declineSensitiveInvitation(id), t('Invitation declined'))
	busy.value = false
	if (res) {
		status.value = res
	}
}

async function leaveOrSwitch() {
	busy.value = true
	const res = await act(leaveSensitiveFilter, t('Using your own filter now.'))
	busy.value = false
	if (res) {
		status.value = res
		sensitiveState.version++
	}
}

async function changeMemberRole(uid, role) {
	busy.value = true
	const res = await act(() => updateSensitiveMemberRole(uid, role), t('Role updated'))
	busy.value = false
	if (res) {
		status.value = res
	}
}

async function removeMember(uid) {
	busy.value = true
	const res = await act(() => removeSensitiveMember(uid), t('Member removed'))
	busy.value = false
	if (res) {
		status.value = res
		sensitiveState.version++
	}
}

async function cancelInvite(uid) {
	busy.value = true
	const res = await act(() => removeSensitiveMember(uid), t('Invitation canceled'))
	busy.value = false
	if (res) {
		status.value = res
	}
}

async function loadStatus() {
	try {
		status.value = await getSensitive()
	} catch {
		status.value = null
	}
}

const searchHref = (q) => href('/search', { q })

async function removeTerm(q) {
	busy.value = true
	const res = await act(() => removeSensitiveTerm(q), t('Removed'))
	busy.value = false
	if (res) {
		status.value = res
		sensitiveState.version++
	}
}

async function toggleSensitive(on) {
	await save({ sensitive_enabled: on })
	loadStatus()
}

async function recompute() {
	busy.value = true
	const res = await act(rebuildSensitive)
	busy.value = false
	if (res) {
		status.value = res
		sensitiveState.version++
	}
}

/** "Only sensitive", in the timeline: the list to clean up. */
async function review() {
	await setSensitiveMode('only')
	navigate('/timeline')
}

onMounted(loadStatus)
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
.hint, .sensitive__state { max-width: 62ch; }

.sensitive__invitations {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	margin: 12px 0 16px;
	background: var(--color-background-hover);
}

.sensitive__invitation {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-top: 8px;

	p {
		margin: 0;
	}
}

.sensitive__invitation-actions {
	display: flex;
	gap: 8px;
}

.sensitive__filter-box {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin: 16px 0;
	max-width: 680px;
}

.sensitive__filter-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;

	h3 {
		margin: 0 0 4px;
	}

	p {
		margin: 0;
	}
}

.sensitive__members, .sensitive__pending-invites, .sensitive__invite-box {
	margin-top: 16px;
	padding-top: 14px;
	border-top: 1px solid var(--color-border);

	h4 {
		margin: 0 0 8px;
		font-size: 0.95rem;
	}
}

.sensitive__member-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.sensitive__member {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 4px 0;
}

.sensitive__member-name {
	font-weight: 500;
}

.sensitive__member-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sensitive__role-select {
	min-width: 160px;
}

.sensitive__invite-form {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.sensitive__invite-user {
	flex: 1 1 200px;
}

.sensitive__invite-role {
	flex: 0 1 180px;
}

.sensitive__terms {
	margin: 8px 0;

	li {
		display: flex;
		align-items: center;
		gap: 4px 12px;
		flex-wrap: wrap;
	}
}

.sensitive__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 12px 0 4px;
}
</style>
