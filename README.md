# Photo search for Nextcloud (`immich_search`)

Find pictures in Nextcloud by describing them – "woman on the beach", "dog in the snow" – or by
person. The image understanding (CLIP) and face recognition come from a self-hosted
[Immich](https://immich.app) that indexes your Nextcloud files **read-only**. This app is the
Nextcloud side: search UI, unified search provider, permission checks.

## What users get

| Where | How |
|---|---|
| Unified search bar (every page) | Section "Photos by content", from 3 characters; first entry hands the search over to the app |
| Nextcloud Android / iOS app | Same search; results carry `fileId`/`path` like Files results |
| "Photo search" app | Search, people, settings – the full thing, also on a phone browser |

In the app:

- **Search** with person and photo/video filter, infinite scroll.
- **Select many** – checkbox on hover, shift-click for a range; on touch long-press, then tap.
  **Move** or **copy** the selection into any folder (Nextcloud's folder picker, Nextcloud's
  permissions). Immich rescans its libraries right after, so moved pictures are found again.
- **People** – every face cluster Immich found, named ones first. Name a person, hide one,
  select several and merge them. A person's page lists their pictures, searchable.
- **Settings** – tile size per device; for admins the connection and who may manage people.

A result is always the **real Nextcloud file**: clicking opens it in the Nextcloud viewer
(videos play). Thumbnails and faces are fetched server-side, so Immich does not need to be
reachable from the internet.

## Who finds what

One index, Nextcloud decides. Immich indexes the files of all users under one service account.
For every hit the app maps Immich's path back to the owner's file and checks whether the
searching user can reach that file in their own Nextcloud (own file or shared with them). If
not, the hit is dropped – and its thumbnail endpoint refuses it as well. Share recipients see
the file under their own path (e.g. `/Shared/Photos/…`). Unsharing takes effect immediately.

People follow the same rule: a person is listed for a user only if they appear in a picture
that user can open, and the face shown is cut from such a picture (not Immich's own person
thumbnail, which may come from a private one). This is worked out hourly in a background job
(`occ immich_search:people-index` runs it now). Shared across users: the **names** – one
Immich account. Who may name, merge and hide is an admin setting: everyone (default),
admins, or nobody.

## Setup

**1. Give Immich read-only access to the Nextcloud data** – one mount per user, or the whole
data directory. Example for the Immich `docker-compose.yml`:

```yaml
  immich-server:
    volumes:
      - /srv/nextcloud-data/alice/files:/mnt/nextcloud/alice:ro
      - /srv/nextcloud-data/bob/files:/mnt/nextcloud/bob:ro
```

**2. In Immich**, as the service account (an admin): Administration → External libraries →
one library per mount with import path `/mnt/nextcloud/<user>`. Create an API key
(Account settings → API keys) with permission "all".

**3. Install the app** (see below) and set it up – Administration settings → Additional
settings → "Photo search (Immich)", or:

```bash
occ immich_search:configure --url http://immich:2283 --key-stdin < api-key
occ immich_search:configure --path-pattern '/mnt/nextcloud/{user}/'   # default
```

The path pattern says where Immich sees a user's files; `{user}` is the Nextcloud user ID.
The key is stored encrypted and read from stdin so it never shows up in a process list.

## Install / develop

PHP backend, Vue 3 frontend with `@nextcloud/vue` (Vite, `@nextcloud/vite-config`).

```bash
make build     # npm install + vite build into js/
make lint      # PHP syntax + translation JSON
make dist      # build/immich_search-<version>.tar.gz, unpack into custom_apps/
make deploy    # install into a dockerized Nextcloud (CONTAINER=..., default AIO)
```

Bump `<version>` in `appinfo/info.xml` for every release: Nextcloud runs the upgrade step and
browsers fetch the new scripts (they are cached by version).

| File | Job |
|---|---|
| `lib/Service/AssetSearch.php` | Immich search, permission check, path mapping |
| `lib/Service/ImmichClient.php` | HTTP to Immich, errors as `ImmichException` |
| `lib/Service/Config.php` | URL, encrypted key, path pattern |
| `lib/Search/ImmichProvider.php` | Unified search / mobile apps |
| `lib/Service/PeopleIndex.php`, `lib/BackgroundJob/` | Who may see which person (hourly) |
| `lib/Service/FaceCropper.php` | Faces cut from pictures the viewer may open |
| `lib/Service/FileTransfer.php` | Move/copy as the user, then Immich rescan |
| `lib/Controller/` | JSON API, permission-checked images, settings |
| `src/App.vue`, `src/views/`, `src/components/` | The app (search, people, person, settings) |
| `l10n/` | Translations (English source, German; plurals as `_singular_::_plural_`) |

## Limits

- Pictures whose shorter side is below a minimum (default 256 px, admin setting) and SVGs are
  never shown: their embeddings match almost any query. Better still, exclude icon formats in
  the Immich library (`**/*.svg`, `**/*.ico`) so they are not indexed at all.
- If Immich or its machine-learning container is down, the search returns nothing (unified
  search shows no section, the page shows an error).
- New faces and changed sharing show up in People with the next hourly index run.
- Merging people cannot be undone (Immich has no unmerge).
- Files moved since Immich's last library scan are not found until the next scan.
- Immich only analyses what it has finished indexing; a large first import takes hours.

## License

AGPL-3.0-or-later
