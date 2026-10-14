# Changelog

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com/), versions follow
`appinfo/info.xml`.

## [4.9.4] - unreleased

### Added
- **Sensitive pictures (optional).** Each user decides what counts: search for it, select the
  last result that still counts and choose *Sensitive up to here*; the search is saved as a term
  with that picture's similarity as its limit. Marks by hand always win for their picture, and
  the pictures most like a marked one follow it. No extra model, no extra container: Immich's
  search embeddings are read from its database. Each user can show all pictures (sensitive ones
  marked on their tile), hide them, or see only them; "only" lists straight from the database
  instead of paging through the library. Applies to search, timeline, map, folders, people and
  memories; hiding also to the Nextcloud search bar and the dashboard. Off until the admin allows
  it (`sensitive_detection`) and the user turns it on under *Settings → Display*, where the terms
  are listed with how many pictures each brings in. Needs read access to Immich's
  `smart_search` table and a few `asset` columns (README), and the machine-learning service's
  address. New: `occ glimpse:sensitive`, the hourly `UpdateSensitive` job.

### Changed
- The Immich database connection moved out of `MoveTracker` into `ImmichDb`, shared by moves
  and sensitive pictures.

## [4.7.0] - 2026-10-14

### Added
- **Actions in the viewer, like in the Android app.** Below an open picture: share (public
  link, through the device's share sheet or copied), favorite, similar, info and delete, with
  the app's Material icons. Info opens a sheet with date and time, the people in the picture,
  its folder (in Glimpse or in Files) and download, move, copy, album and tag. Replaces the
  "Show in folder" button. The picture and a video's controls end above the bar.

### Fixed
- A person recognized in the search text ("Tara coffee") now shows in the person filter.
  Picking someone else or "All people" there drops the name from the text.
- Toasts had no styles (`@nextcloud/dialogs/style.css` was never loaded), so they showed as
  plain text in the corner and behind the viewer.

## [4.6.1] - 2026-10-13

### Fixed
- Person timeline review findings: malformed person ids answer with nothing, a person's years
  are cached and refreshed when a new year shows up, "more like this" and date links keep
  working on a person's page.

## [4.6.0] - 2026-10-13

### Added
- **A person's page is a timeline.** Newest pictures first in year bands, the person's years
  along the right edge to jump to (say, their oldest pictures), scrolling on in both directions
  and "Back to today" – like the main timeline. Typing words still searches in their pictures.
  `/api/timeline` takes `person`; page 1 then lists that person's years.

## [4.5.2] - 2026-10-13

### Fixed
- The night window for video stills goes by the server's local time (TZ of the container or
  default_timezone), not UTC.

## [4.5.1] - 2026-10-13

### Changed
- Search inside videos works in a night window (default 2:00–7:00, settable next to the
  switch); `occ glimpse:video-scenes` ignores it.

### Fixed
- Review findings: a run stops when Immich is unreachable instead of giving up on videos, and
  failed videos are retried daily; an interrupted run never uploads a still twice; only local
  storage is read (no temporary copies of remote files); ffmpeg's error output can no longer
  block it; deleted videos' stills are found with a few listings instead of one call per video;
  "more like this" from a video hides the video itself.

## [4.5.0] - 2026-10-13

### Added
- **Search inside videos.** Immich looks at one still per video; Glimpse now takes a still
  every 15 seconds or so (two for short clips, at most 40 for long videos) and hands them to
  Immich as archived pictures. A search finds the moment: the result shows that still with
  its time, and the video starts there. Off by default (settings, "What gets indexed"); needs
  ffmpeg on the Nextcloud server. A background job works through the videos, newest first;
  `occ glimpse:video-scenes` does it at once, `--status` shows progress, `--purge` removes
  all stills again. Stills of deleted videos are removed daily.

### Changed
- The timeline and date-ordered lists ask Immich for timeline pictures only, so the stills
  never show up there; the photo count in the status leaves them out.

## [4.4.1] - 2026-10-13

### Fixed
- Review findings: newer pictures loaded above are anchored on a picture, not on whatever
  sits at that spot; a jump to a year with nothing older still loads the newer ones; the
  years list checks its cache first and asks Immich less often; the own-folder scope needs
  `{user}` in the path pattern and ends in a slash.
- Phones: the selection bar takes the dock's place. Leaving the search clears the pill; the
  name "Glimpse" opens the start view; light theme marks with the darker apricot; settings,
  folders and duplicates keep a margin on phones; the year rail scrolls when it is long.

## [4.4.0] - 2026-10-13

### Changed
- **New look, the same as the Android app.** Night blue around the pictures, the lens's
  blue-to-violet for actions, apricot for what is chosen, years and names in Bricolage
  Grotesque. Nextcloud's light theme gets a light version of it.
- **One navigation instead of two.** The side panel and the row of tabs are gone; the views
  sit as words next to the app's name, the search is a pill that is always there. On phones
  the search and the views sit at the bottom, in reach of the thumb, as in the app.
- **Timeline in year bands.** Each year opens with its number in big type; all years run down
  the right edge, the one in view in apricot, and a click jumps there. "Back to today" floats
  in the corner after a jump.
- An empty search shows the people to start from. Photo/video filters are chips; selected
  pictures get a floating bar at the bottom. A person's name is the page's heading and is
  edited in place.

## [4.3.4] - 2026-10-13

### Changed
- **The timeline goes on in both directions after a jump.** Pick a year and scroll down into
  older pictures as before, or up into newer ones up to today; it is one unbroken list.
  "Show in timeline" works the same way.

### Fixed
- Someone who sees only their own files no longer gets the years of everyone's library, and
  their timeline no longer pages through other people's pictures (empty pages, slow): Immich
  is asked for their own folder only. With files shared to them, it stays as before.

## [4.2.3] - 2026-10-13

### Added
- **"Show in timeline"** for one selected picture: the timeline opens at its day, with the
  pictures before it below and the year buttons to move on.
- **"Open in Memories"**, if the Memories app is installed and the picture lies in one of its
  timeline folders: Memories opens the picture; closing it leaves its timeline at that day.

## [4.2.1] - 2026-10-11

### Added
- **"Not {person}" for many pictures at once:** on a person's page (or a search filtered by a
  person), select the wrongly recognized pictures, then "⋯ → Not Tanja …". Her faces in them
  go to another person, a new named one, one new unnamed person, or are dropped as "not a
  face". Other faces in the same pictures stay. The people list and the person's face picture
  update right away.

## [4.1.2] - 2026-10-11

### Added
- **Tag filter in the search:** pick a collaborative tag next to the person filter. With text
  or a person it narrows the results to tagged files; alone it lists every picture with the
  tag, newest first (straight from Nextcloud, also files Immich has not indexed yet).
- **Share an album right away:** "Add to an album" can share the album with users or groups.
  They see it in Photos under the shared albums; the files are neither moved nor copied.
  Existing shares of the album are kept and shown.

## [4.0.4] - 2026-10-07

### Fixed
- Phones: no double frame and padding around the app (our `.content` class also matched
  Nextcloud's own app container), full width below 600 px, all tabs in one row (duplicates and
  settings stay in the side navigation).
- Browser tab title showed `[object Object]`.
- Person page: "Hide" sits in the filter row next to photos/videos; header fits one row.

### Removed
- Birthdays of people (field, age and backend field).

## [4.0.2] - 2026-10-06

All 49 findings of the code review on #3.

### Security
- Private area: turning off the password prompt needs password confirmation; while locked,
  lists are not shown and can only grow; private people (faces, samples, search, single
  faces) are not found while locked.
- Revoked shares: thumbnail grants are re-checked against the file; share deletions and
  moves invalidate the people and map index; all share types count.
- Tags need write permission on the file; ZIP respects shares without download.
- Duplicate groups can only be dismissed by someone who sees the whole group (or an admin).
- Ids are validated before they go into Immich URLs and encoded in the frontend.

### Immich
- Moves: database timeouts, a failed connection is not retried per file, no password in traces.
- Pruning only touches library assets; folder exclusions only cover the tiny files' types;
  patterns are written before they are recorded and escaped everywhere; entries are cleaned
  up, `occ glimpse:prune --reset`; large state is stored lazily; locks against parallel runs.
- Non-JSON answers from Immich are errors instead of empty results.

### Files and UI
- ZIP via POST with size limits and skipped unreadable files; undo restores the original
  name; copies queue a scan of the owner's library only.
- Map tiles: max zoom 18, 600/min, cache capped at 500 MB by a daily job, stale tile on errors.
- "On this day" in the user's timezone; people search paginates.
- Selection drops pictures that left the view; shift ranges across months; sturdier
  long-press; no stuck spinner; stale responses ignored in timeline, folders, sidebar, people.

## [4.0.0] - 2026-10-05

### Changed
- **Renamed to Glimpse**: app id `glimpse` (was `immich_search`), PHP namespace `OCA\Glimpse`,
  commands `occ glimpse:*`, URL `/apps/glimpse/`. Moving an existing install over: copy the
  app config and user preferences to the new id, move `appdata_*/immich_search` to
  `appdata_*/glimpse`, `occ files:scan-app-data glimpse` (done this way on flo-server).

### Fixed
- Map tiles were always requested at zoom 0, so every tile showed the whole world.


### Added
- Moves and renames in Nextcloud are carried over to Immich (optional, needs a restricted
  database user): pictures keep their analysis and faces instead of being imported again.
- "Show in folder" on the viewer opened from the app.

### Changed
- Search starts on Enter and when leaving the field.
- People are sorted by number of pictures everywhere.
- "Who is this?" is now "Label people", with three faces per person.

### Fixed
- Opening a picture in a folder with thousands of files froze the browser for a minute
  (nextcloud/viewer#3015). The app hands the viewer its list; in the Files app a workaround
  does the same until Nextcloud 33.0.10 / 34.0.5 / 35.0.1.

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
