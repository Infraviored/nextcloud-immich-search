# AGENTS.md: working on Glimpse

Glimpse is a Nextcloud app (id `glimpse`, namespace `OCA\Glimpse`) that gives a photo search and
gallery UI over a self-hosted Immich. Immich indexes the users' Nextcloud files read-only, under
**one** service account. Glimpse filters every result through Nextcloud permissions. User docs
are in `README.md`, the threat model in `docs/SECURITY.md`, releasing in `docs/RELEASE.md`.

## Layout

| Path | What |
|---|---|
| `appinfo/info.xml` | The **only** place for the version; dependencies (PHP ≥ 8.1, NC 30–35); jobs, commands, settings, navigation |
| `appinfo/routes.php` | All routes. Each SPA deep link (`/timeline`, `/people/{id}`, …) also has a `page#index` route so reloads work |
| `lib/Controller/` | JSON API (see below) |
| `lib/Service/` | All logic |
| `lib/BackgroundJob/`, `lib/Command/` | Jobs and `occ glimpse:*` |
| `lib/Search/` | Unified search providers `glimpse` ("Photos by content") and `glimpse_people` |
| `lib/Dashboard/MemoriesWidget.php` | "On this day" widget |
| `lib/Listener/` | Files app scripts, move tracking, share removal |
| `src/` | Vue 3 + `@nextcloud/vue` frontend, built by Vite into `js/glimpse-{main,admin,files}.mjs` |
| `l10n/` | `de.json`/`de.js` (informal "du"), `de_DE.json`/`de_DE.js` (formal "Sie") |
| `tests/Unit/` (PHPUnit), `tests/js/` (Vitest), `tests/e2e/smoke.mjs` (Playwright, live) | Tests |

## Backend

**Controllers**

- `PageController`: renders the SPA and provides initial state (`page`, `prefs`, `admin`).
- `ApiController`: search, the thumbnail proxy, and asset-for-file lookup.
- `BrowseController`: timeline (plus years), memories, duplicates, folder, items, map, and map
  tiles.
- `PeopleController`: people list, faces, rename/hide/favorite, merge, and moving faces.
- `FilesController`: transfer (move/copy), undo, favorite, delete, tags, ZIP.
- `PrefsController`: per-user prefs and `/api/status`.
- `PrivateController`: the private area.
- `SettingsController`, `AdminController`: admin only.

**Services** (`lib/Service/`)

- `ImmichClient`: thin REST client. Sends `x-api-key`, base `<url>/api`, 30 s timeout.
- `Config`: app config. `SETTINGS` holds the typed admin settings. The connection values are
  `url`, `api_key` (encrypted), `path_pattern` (default `/mnt/nextcloud/{user}/`) and
  `immich_db`. In storage, `' '` means "empty on purpose" and `''` means unset.
- `AssetSearch`: **the permission core.** `visibleItem` takes Immich `originalPath` → path pattern
  → owner + relative path → the owner's file → the same file id in the searching user's tree
  (own or shared). If nothing resolves, the hit is dropped. Visible assets become 24 h "grants"
  that authorize thumbnails. A grant is honoured only while the file still resolves.
  `collect()` pages through Immich until half a page is visible.
- `PeopleNames`: splits "Tara beach" into person + rest. The name must be a whole word at the
  start or end; the longest match wins. Only named, visible, non-private people count.
- `PeopleIndex`, `MapIndex`: per-user visibility of people and map markers. Built in the
  background and stored in app data as `people/<uid>.json`.
- `IndexSignature`: fingerprint used to skip rebuilds when nothing changed.
- `PrivateArea`: per-user private folders, people and files in `private/<uid>.json`. Unlocking
  sets a session key valid for 30 min. `filter(..., allowUnlock: false)` is used for unified
  search and the dashboard, so private items **never** show there.
- `FaceCropper`: cuts faces (GD) from a preview the user may see. It never uses Immich's person
  thumbnail, which could come from someone else's private picture.
- `Discover`: memories (`/memories?for=`) and duplicates.
- `FolderBrowser`: folder galleries from Nextcloud previews, capped at 5000 items.
- `TagFilter`: collaborative tags as a filter.
- `FileActions`, `FileTransfer`: actions run as the user through Nextcloud APIs.
- `TileProxy`: proxies OSM tiles and caches them (500 MB / 30 days).
- `SmallImagePruner`: takes images under `min_size` out of Immich (trash plus exclusion
  patterns).
- `IndexAdmin`: status, queues, libraries, exclusion patterns, folder suggestions.
- `MoveTracker`: see below.
- `UserPrefs`, `Permissions`.

**Search flow** (`ApiController::search`)

- With text: `POST /search/smart` (CLIP) with `query`.
- "More like this": `queryAssetId`. The user must be allowed to see the source picture.
- Person only, no text: `POST /search/metadata` with `personIds`, ordered by newest.
- A tag alone lists files from Nextcloud directly. With text or a person, the tag narrows the
  Immich hits.
- Every result goes through `AssetSearch`'s permission filter, then `PrivateArea`.

**Timeline**

- `/search/metadata` ordered by date, with `takenBefore` for year jumps and `takenAfter` for
  scrolling upward.
- Years come from `/timeline/buckets`.
- Users with no incoming shares get `originalPath` scoped to their own folder (`ownScope`). For
  them, years are probed per user and cached for 1 h.

**Jobs**

- `BuildPeopleIndex`: every 1/6/24 h (setting `people_interval_hours`). Also rebuilds the map
  index and refreshes the private area.
- `RebuildPeopleIndexNow`: queued by "Update people now" and by `ShareRemoved`.
- `PruneSmallImages`: every 15 min.
- `PruneTileCache`: daily.
- `ScanLibraries`: queued after copies.

**Immich API used**

| Area | Endpoints |
|---|---|
| Search | `/search/smart`, `/search/metadata`, `/timeline/buckets` |
| Assets | `/assets/{id}`, `/assets/{id}/thumbnail`, `DELETE /assets` |
| People and faces | `/people`, `/people/{id}`, `/people/{id}/merge`, `/faces?id=`, `/faces/{id}` |
| Memories and duplicates | `/memories`, `/duplicates` |
| Libraries | `/libraries`, `/libraries/{id}`, `/libraries/{id}/scan`, `/libraries/{id}/statistics` |
| Admin | `/jobs`, `/jobs/{queue}`, `/server/statistics`, `/users/me`, `/view/folder/unique-paths` |

Tested against Immich 3.2. The API key needs permission "all".

**Optional direct Immich DB** (`MoveTracker`, `Listener/TrackMoves`)

- Immich has no API to change an asset's path, so a moved file would be re-imported and
  re-analysed. When `immich_db` is configured (occ only: `glimpse:configure --db-*`), each
  Nextcloud rename or move of an image, video or folder rewrites `asset."originalPath"`,
  `"originalFileName"` and `checksum`. This happens over PDO `pgsql` (requires `pdo_pgsql`)
  with 5 s / 2 s timeouts.
- It never breaks a move. Failures are logged, and Immich re-analyses at its next scan.
- Moves that change the owner are ignored.
- The minimal role and grants are in `README.md`.

## Frontend

- `src/main.js` mounts `App.vue`. `src/admin.js` is the admin settings section. `src/files.js`
  adds the Files action "Similar pictures" and the sidebar tab "People"
  (`components/SidebarTab.vue`).
- `src/router.js` is hand-rolled, with no vue-router. Route names: `search` (`/`), `timeline`,
  `people`, `person`, `map`, `folders`, `duplicates`, `settings`. `App.vue` maps them to
  `src/views/*View.vue`.
- `src/api.js` has one function per route. `seg()` URL-encodes ids taken from the address bar.
- Settings sections are in `src/views/settings/`: Status, Display, Nextcloud search, Private, and
  the admin-only What gets indexed, People and Connection.
- State lives in small modules (`selection.js`, `private.js`, `people.js`, `prefs.js`,
  `adminSettings.js`). There is no store library.
- Composables: `useInfiniteScroll` (both directions, re-checks after loads because the
  permission filter can empty a page), `useTransfer`, `useAction`.
- **Design tokens** live in `src/styles/theme.scss`:
  - night: `--g-bg` #14162B, `--g-raised` #1D2040, `--g-line` #2E3260, `--g-text` #ECEDF7,
    `--g-muted` #9599BE
  - accent: `--g-accent` apricot #FFB27A, used only for "chosen" states
  - lens gradient: `--g-lens` #2B7DE9 → #6A3FD8
  - a light ("paper") variant
  - Nextcloud's CSS variables are mapped onto these tokens

  Use the tokens and never hard-code colours. The display font Bricolage Grotesque is loaded by
  `src/theme.js` via FontFace from `img/bricolage.woff2`; Nextcloud's CSP blocks other font
  origins. It is used through `.g-display` and `.g-heading`.
- **l10n:** import `t`/`n` from `src/l10n.js` (wrappers around `@nextcloud/l10n` for app
  `glimpse`). The PHP side uses `IL10N`. English is the source.
  - For every new string, add the German translation to **all four** files: `l10n/de.json`,
    `de.js` (informal "du") and `de_DE.json`, `de_DE.js` (formal "Sie"). The `.js` files are
    maintained by hand and must equal their `.json` exactly.
  - Plurals use the key `"_%n picture_::_%n pictures_"`.

## Build, test, lint

```bash
npm ci                          # or: make build (npm install + vite build into js/)
npm run build                   # routed through buildlock on flo-server automatically
npm test                        # Vitest, tests/js
npm run lint                    # ESLint, @nextcloud/eslint-config; l10n/ and js/ ignored
make test                       # composer test (PHPUnit) + npm test; uses a php:8.3-cli container if no local php
vendor/bin/phpunit -c tests/phpunit.xml   # after composer install; runs against nextcloud/ocp stubs, no server
composer lint                   # php -l
composer cs:check               # php-cs-fixer (Nextcloud standard); cs:fix to apply
make lint                       # PHP syntax (in the NC container if no local php) + l10n JSON validity
make dist                       # build/glimpse-<version>.tar.gz
NC_URL=… NC_USER=… NC_PASS=<app password> npm run e2e   # live smoke test, all pictures blurred
```

CI (`.github/workflows/ci.yml`) runs PHP 8.1–8.4 lint and tests, `cs:check` on 8.3, and Node 22
lint, tests and build. A `vX.Y.Z` tag must match `info.xml`, or the release workflow fails.

On flo-server, wrap `make test` and `phpunit` in `buildlock`. The `npm` build and test commands
go through it automatically.

## Deploying (`deploy.sh`, `make deploy`)

The script:

1. builds the app
2. **bumps the patch version** if any shipped file changed since the last deploy
3. copies the tarball into `custom_apps/glimpse` of the container (`CONTAINER`, default
   `nextcloud-aio-nextcloud`)
4. reloads php-fpm gracefully
5. runs `occ upgrade` if the version changed

Why:

- Nextcloud caches routes and browsers cache scripts **per app version**. Without a bump, users
  keep the old UI and new routes answer 404.
- `occ upgrade` switches **the whole instance into maintenance mode for a moment**. Every
  deploy with a version change briefly answers 503 for everyone. Don't deploy casually on
  production, and don't mistake that 503 for an app bug.
- Without the php-fpm reload, opcache can rebuild the route table from the old `routes.php`,
  which gives 405s until the next version.
- A malformed `<version>` puts all of Nextcloud into "update required". The script refuses it.

## Pitfalls

- **Permissions first.** Every new endpoint that returns assets, faces, thumbnails or
  coordinates must go through `AssetSearch` (`visibleItem` / `maySee`) and `PrivateArea`.
  Never return Immich data unfiltered. Never use Immich's person thumbnail.
- **One Immich account.** Person names, hiding and merges are global. Dismissing a duplicate
  group affects everyone, so it requires the user to see the whole group or be an admin.
  Merges cannot be undone.
- **Exclusion patterns:** Immich allows at most 128 per library, and a pattern over about
  10,000 characters fails the scan. `SmallImagePruner::pack` keeps brace patterns under 8000
  characters. Special characters become `?`, not a backslash, because Immich also runs the
  patterns as Postgres regexes, where an escaped `{…}` breaks. Patterns the admin wrote by hand
  in Immich are preserved; only the ones in `state_managed_patterns` are replaced.
- Immich matches extensions case-sensitively, so emit both cases.
- `#[NoCSRFRequired]` only on read-only GETs that `<img>` or downloads need. Rate limits
  (`#[UserRateLimit]`) are on search, bulk actions, faces, unlocking and tiles.
- Search providers are built outside DI: get app data through the factory (see `PeopleIndex`).
- Unlocking the private area uses `#[PasswordConfirmationRequired]`. While locked, the API
  returns only counts and the server only *adds* entries.
- `src/viewerFix.js` works around nextcloud/viewer#3015 and turns itself off on fixed
  Nextcloud versions.
- `App.vue` uses the class `.glimpse`, not `.content`, which clashes with Nextcloud's container.
- Optional apps are feature-detected, never required: Viewer, Photos (albums), Memories ("Open
  in Memories"), Files.
- Tests use `nextcloud/ocp` stubs (`dev-stable31`) plus `tests/stubs/OC.php`. Server-internal
  classes are not available.
