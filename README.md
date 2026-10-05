# Photo search for Nextcloud (`immich_search`)

A photo app for Nextcloud that understands your pictures. Describe what you are looking for –
"woman on the beach", "dog in the snow" – or pick a person. The image understanding (CLIP),
face recognition, memories and duplicate detection come from a self-hosted
[Immich](https://immich.app) that indexes your Nextcloud files **read-only**. This app is the
Nextcloud side: the interface, Nextcloud's search integration and – most importantly – the
permission checks.

## Features

| | |
|---|---|
| **Search** | By content, by person, both ("Tara beach"), photos or videos, "more like this" for any picture |
| **Browse** | Timeline by month with year jumps and "on this day", map of geotagged pictures, folders as galleries |
| **Sort** | Multi-select (hover checkbox, shift range, long-press on touch); move/copy into folders with recent targets and undo; tag; favorite; add to a Photos album; download as ZIP; delete |
| **People** | Name, merge, hide, birthday, favorites; assign or remove single faces; "Label people" to name people in a row, three faces each |
| **Duplicates** | Groups of identical or nearly identical pictures, Immich's choice of what to keep preselected |
| **Private area** | Folders, people and single pictures that only show after unlocking (password, 30 minutes) – never in the global search or the dashboard |
| **Nextcloud search** | "Photos by content" and "People in photos" in the search bar on every page and in the mobile apps; first entry opens all results in the app |
| **Files app** | "Similar pictures" action; sidebar tab with the people in a picture and similar pictures |
| **Dashboard** | "On this day" widget |
| **Index hygiene** | Exclude file types and folders (with suggestions), take tiny images out of Immich for good, status page with queues and libraries |

Everything is a real Nextcloud file: clicking opens it in the Nextcloud viewer (videos play),
actions go through Nextcloud's APIs with the user's own permissions. Pictures, faces and map
tiles are fetched server-side, so Immich and the tile server are never contacted by browsers.

## Who sees what

One shared index, Nextcloud decides. Immich indexes the files of all users under one service
account. For every hit the app maps Immich's path back to the owner's file and checks whether
the searching user can reach that file in their own Nextcloud – own file or shared with them.
If not, the hit is dropped, and so are its thumbnail, its faces, its map marker and its place
in memories and duplicates. Share recipients see files under their own paths.

- **People** are listed for a user only if they appear in a picture that user can open; their
  face is cut from such a picture (not Immich's person thumbnail, which may come from a
  private one). Computed hourly in the background, skipped when nothing changed.
- **Shared across users:** person names (one Immich account) and the list of years that have
  pictures. Who may name, merge and hide people is an admin setting.
- **Private area:** per user, on top of the above.

Details and the reasoning: [docs/SECURITY.md](docs/SECURITY.md).

## Setup

**1. Give Immich read-only access to the Nextcloud data**, one mount per user (or the whole
data directory). Example for the Immich `docker-compose.yml`:

```yaml
  immich-server:
    volumes:
      - /srv/nextcloud-data/alice/files:/mnt/nextcloud/alice:ro
      - /srv/nextcloud-data/bob/files:/mnt/nextcloud/bob:ro
```

For **Nextcloud AIO** the data directory is the volume `nextcloud_aio_nextcloud_datadir`
(or `NEXTCLOUD_DATADIR`); mount `<datadir>/<user>/files`.

**2. In Immich**, as an admin: create an API key with permission "all" (Account settings → API
keys). External libraries can be created from the app later (Settings → What gets indexed).

**3. Install the app** – unpack the release tarball into `custom_apps/` and enable it, or
`make deploy` (see below). Then in the app: Settings → Connection, or

```bash
occ immich_search:configure --url http://immich:2283 --key-stdin < api-key
occ immich_search:configure --path-pattern '/mnt/nextcloud/{user}/'   # default
```

The path pattern says where Immich sees a user's files; `{user}` is the Nextcloud user ID.
The key is stored encrypted and read from stdin so it never shows up in a process list.

**4. Libraries:** Settings → What gets indexed → "Libraries per user" lists users without one
and creates them (the mount from step 1 must exist).

**5. Optional – keep pictures when they are moved.** Immich knows library files only by path:
a moved or renamed picture (or folder) is imported again and analysed from scratch, and faces
assigned by hand are lost. The app can carry every move in Nextcloud over to Immich's
database. Give the Nextcloud container network access to Immich's Postgres and create a user
that may only touch asset paths:

```sql
CREATE ROLE nextcloud_moves LOGIN PASSWORD '…';
GRANT CONNECT ON DATABASE immich TO nextcloud_moves;
GRANT USAGE ON SCHEMA public TO nextcloud_moves;
GRANT SELECT (id, "originalPath", "libraryId", "checksumAlgorithm") ON asset TO nextcloud_moves;
GRANT UPDATE ("originalPath", "originalFileName", checksum) ON asset TO nextcloud_moves;
```

```bash
occ immich_search:configure --db-host immich_postgres --db-user nextcloud_moves --db-password-stdin < password
```

### Background jobs and commands

| What | When | Command |
|---|---|---|
| People and map index | hourly (admin setting: 1/6/24 h), skipped if nothing changed | `occ immich_search:people-index` |
| Take tiny images out of Immich | every 15 min, new images only | `occ immich_search:prune [--full]` |
| Connection | – | `occ immich_search:configure` |

Nextcloud's cron must run (AIO does this by itself).

## Develop

PHP backend, Vue 3 frontend with `@nextcloud/vue` (Vite, `@nextcloud/vite-config`), the
Nextcloud coding standards for both.

```bash
make build     # npm install + vite build into js/
make lint      # PHP syntax + translation JSON
make test      # PHPUnit (against nextcloud/ocp stubs) + Vitest
npm run lint   # ESLint, @nextcloud/eslint-config
composer cs:check
make dist      # build/immich_search-<version>.tar.gz
make deploy    # into a dockerized Nextcloud (CONTAINER=..., default AIO)
NC_URL=… NC_USER=… NC_PASS=<app password> npm run e2e   # live smoke test, pictures blurred
```

`deploy.sh` bumps the patch version whenever shipped files changed: Nextcloud caches routes
and browsers cache scripts by version. PHP changes can take up to a minute to show
(`opcache.revalidate_freq`). CI runs on every push; tagging `vX.Y.Z` builds a release
([docs/RELEASE.md](docs/RELEASE.md)).

| Where | What |
|---|---|
| `lib/Service/AssetSearch.php` | Immich search, permission check, path mapping |
| `lib/Service/PeopleIndex.php`, `MapIndex.php`, `IndexSignature.php` | Per-user people and map visibility, change detection |
| `lib/Service/PrivateArea.php` | Private folders, people, files; session unlock |
| `lib/Service/SmallImagePruner.php`, `IndexAdmin.php` | Index hygiene, status, libraries |
| `lib/Service/FileTransfer.php`, `FileActions.php` | Bulk actions as the user |
| `lib/Service/MoveTracker.php`, `lib/Listener/TrackMoves.php` | Moves in Nextcloud → Immich paths |
| `src/viewerFix.js` | Viewer workaround for large folders in the Files app |
| `lib/Service/Discover.php`, `FolderBrowser.php`, `TileProxy.php` | Memories, duplicates, folders, map tiles |
| `lib/Search/` | Nextcloud search providers |
| `lib/Controller/` | JSON API, permission-checked images |
| `src/views/`, `src/components/` | The app; `src/files.js` the Files integration |
| `l10n/` | English source, German (`de` informal, `de_DE` formal) |

## Limits

- Immich's analysis service must be running for content search; the global search then just
  shows nothing.
- New pictures arrive with Immich's library scan (nightly by default); people and the map
  follow with the next hourly index.
- Immich allows 128 exclusion patterns per library and fails scans on patterns over 10000
  characters or with braces in file names; the app packs and sanitizes its patterns
  accordingly ([details](lib/Service/SmallImagePruner.php)).
- Merging people cannot be undone (Immich has no unmerge).
- Moves are carried over only when they happen through Nextcloud and stay with the same
  owner; this writes to Immich's database directly, as Immich has no API for it.

## License

AGPL-3.0-or-later
