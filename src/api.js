import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/immich_search' + path)

/** Error message from our JSON errors, or a generic one. */
export function errorMessage(e) {
    return e?.response?.data?.error || e?.message || String(e)
}

export const thumbnailUrl = (id, size = 'thumbnail') => url(`/api/assets/${id}/thumbnail`) + (size === 'preview' ? '?size=preview' : '')
export const faceUrl = (id) => url(`/api/people/${id}/face`)
export const fileUrl = (fileId) => generateUrl('/f/' + fileId)

export async function search({ q = '', page = 1, person = '', type = '' }, signal) {
    const { data } = await axios.get(url('/api/search'), { params: { q, page, person, type }, signal })
    return data
}

export async function listPeople() {
    return (await axios.get(url('/api/people'))).data
}

export async function updatePerson(id, changes) {
    return (await axios.put(url(`/api/people/${id}`), changes)).data
}

export async function mergePeople(target, ids) {
    return (await axios.post(url(`/api/people/${target}/merge`), { ids })).data
}

export async function transfer(fileIds, target, mode) {
    return (await axios.post(url('/api/files/transfer'), { fileIds, target, mode })).data
}

export async function saveSettings(settings) {
    return (await axios.put(url('/api/settings'), settings)).data
}

export async function testSettings(settings) {
    return (await axios.post(url('/api/settings/test'), settings)).data
}

export async function reindexPeople() {
    return (await axios.post(url('/api/settings/reindex'))).data
}
