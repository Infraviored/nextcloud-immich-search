# Photo search for Nextcloud (`immich_search`)

Find pictures in Nextcloud by describing them – "woman on the beach", "dog in the snow" – or by
person. The image understanding (CLIP) and face recognition come from a self-hosted
[Immich](https://immich.app) that indexes your Nextcloud files **read-only**. This app is the
Nextcloud side: search UI, unified search provider, permission checks.

## What users get

| Where | How |
|---|---|
| Unified search bar (every page) | Section "Photos by content", from 3 characters |
| Nextcloud Android / iOS app | Same search; results carry `fileId`/`path` like Files results |
| "Photo search" page in the app menu | Grid with infinite scroll, person and photo/video filter |

A result is always the **real Nextcloud file**: clicking opens it in the Nextcloud viewer
(videos play), the folder icon jumps to its folder. Thumbnails are fetched server-side, so
Immich does not need to be reachable from the internet.

## Who finds what

One index, Nextcloud decides. Immich indexes the files of all users under one service account.
For every hit the app maps Immich's path back to the owner's file and checks whether the
searching user can reach that file in their own Nextcloud (own file or shared with them). If
not, the hit is dropped – and its thumbnail endpoint refuses it as well. Share recipients see
the file under their own path (e.g. `/Shared/Photos/…`). Unsharing takes effect immediately.

Shared across users: **person names** (one Immich account). The pictures behind a name are
still filtered.

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

No build step – PHP and plain JavaScript.

```bash
make lint      # PHP syntax + translation JSON
make dist      # build/immich_search-<version>.tar.gz, unpack into custom_apps/
make deploy    # install into a dockerized Nextcloud (CONTAINER=..., default AIO)
```

Bump `<version>` in `appinfo/info.xml` for every release; Nextcloud runs the upgrade step then.

| File | Job |
|---|---|
| `lib/Service/AssetSearch.php` | Immich search, permission check, path mapping |
| `lib/Service/ImmichClient.php` | HTTP to Immich, errors as `ImmichException` |
| `lib/Service/Config.php` | URL, encrypted key, path pattern |
| `lib/Search/ImmichProvider.php` | Unified search / mobile apps |
| `lib/Controller/ApiController.php` | JSON API and permission-checked thumbnails |
| `lib/Controller/SettingsController.php`, `lib/Settings/Admin.php` | Admin settings |
| `js/search.js`, `css/search.css`, `templates/main.php` | The search page |
| `l10n/` | Translations (English source, German) |

## Limits

- If Immich or its machine-learning container is down, the search returns nothing (unified
  search shows no section, the page shows an error).
- Persons appear in the filter once they have a name in Immich.
- Files moved since Immich's last library scan are not found until the next scan.
- Immich only analyses what it has finished indexing; a large first import takes hours.

## License

AGPL-3.0-or-later
