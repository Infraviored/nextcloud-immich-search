# Changelog

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com/), versions follow
`appinfo/info.xml`.

## [3.0.0] - 2026-10-04

### Added
- Settings page in the app: status (queues, libraries, people index), display, Nextcloud
  search, what gets indexed (file types, folders with suggestions, minimum size, libraries
  per user), people, connection, private area.
- Tiny images are taken out of Immich for good (trash + packed exclusion patterns).
- "People in photos" in the Nextcloud search; "Tara beach" = person + text.
- Similar pictures; bulk favorite, tags, Photos albums, ZIP, delete, share; undo for moves,
  recent target folders.
- Timeline with "on this day" and year jumps, map (tiles proxied), duplicates, folder galleries.
- Faces per picture (assign, new person, remove), "Who is this?", birthdays, favorites.
- Private area for folders, people and single pictures, unlocked per session with password.
- Files app: "Similar pictures" action and a sidebar tab; dashboard widget "On this day".
- E2E smoke test with blurred pictures; unit tests for people names, pruning, private area.

### Changed
- People and map indexes are skipped when nothing changed (seconds instead of a minute).
- Rate limits on search, bulk actions, face changes, unlocking and tiles.

### Fixed
- Search providers lost their app data and translations when built outside the app container.
- Malformed versions from `deploy.sh` could put the whole instance into "update required".

## [2.3.0] - 2026-10-04

### Added
- Unit tests (PHPUnit against the OCP stubs, Vitest), ESLint and php-cs-fixer with the
  Nextcloud standards, GitHub Actions for CI and releases.

## [2.2.0] - 2026-10-04

### Added
- Minimum picture size (default 256 px, admin setting); SVGs and tiny images never show up
  in results or people.

## [2.1.0] - 2026-10-04

### Added
- Own app icon; tabs Search / People / Settings always visible (also on phones).

## [2.0.0] - 2026-10-04

### Added
- Vue 3 interface with `@nextcloud/vue`: navigation, settings dialog.
- Multi-select (hover checkbox, shift range, long-press on touch); move or copy into a folder
  with Nextcloud's folder picker and permissions; Immich rescans afterwards.
- People: name, hide, merge. Only people from pictures the user may open, faces cut from such
  pictures; hourly background index.
- Unified search: first entry opens all results in the app.

## [1.2.0] - 2026-10-04

### Changed
- One shared Immich index for all users; Nextcloud permissions decide what each user finds.
- Configurable path pattern, translations (English source, German), AGPL license.

## [1.0.0] - 2026-10-04

### Added
- Content search via Immich smart search, unified search provider, search page.
