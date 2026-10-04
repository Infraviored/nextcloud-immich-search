# Changelog

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com/), versions follow
`appinfo/info.xml`.

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
