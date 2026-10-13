# Security model

The app puts a shared index (one Immich account sees everyone's pictures) in front of users
who must only see what Nextcloud lets them see. Everything below follows from that.

## Rules

1. **Nextcloud is the authority.** A picture is shown to a user only if its file resolves in
   that user's own file tree (`AssetSearch::visibleItem`): Immich path → owner's file →
   same file id in the user's tree (own file or share). Search hits, timeline, memories,
   duplicates, folders, map markers and the global search all go through it.
2. **Images are checked too.** Thumbnails are served only for assets the user was shown or
   that pass the same check (`maySee`); faces are cut from a picture the user may open
   (`FaceCropper`), never Immich's person thumbnail, and that picture is checked live even
   when it comes from the people index. Map tiles are proxied. A thumbnail grant (24 h cache)
   holds the file id and is only honoured while that file still resolves in the user's tree,
   so taking a share back ends access at once.
3. **Actions run as the user** through Nextcloud APIs (move, copy, delete to trash, tags,
   favorites, albums via WebDAV). A share without delete permission cannot be moved out of.
4. **Changes to Immich** (names, merges, faces, duplicates dismissed) require the person or
   picture to be visible to the user, and the admin's people setting (all / admins / nobody).
   Dismissing a duplicate group removes it for everyone, so it needs every picture of the
   group to be visible to the user (or an admin). Immich's "keep" suggestion is only passed
   on for pictures the user sees.
5. **The Immich API key** is stored encrypted in app config, readable only by admins' settings
   page (never sent to the browser), and set via stdin on the command line.

## What is shared between users

- **Person names.** One Immich account, one set of people. A name typed by one user is seen
  by every user who can see a picture of that person. Admins can restrict naming.
- **The years that have pictures** (timeline year links) – not their contents.
- Index timing (when people/map were last built).

## The people and map indexes

Who may see which person and which map markers is worked out in the background (hourly by
default) and served from that index; faces, samples, thumbnails and search check live. The
index is rebuilt when `IndexSignature` changes: Immich's counts and scans, every share that
makes a file resolve for its recipient (user, group, circle, Talk room, Deck), moves carried
over to Immich, and a counter bumped by `ShareRemoved` whenever a share is deleted. That
listener also queues a one-off rebuild for the next cron run, so a share taken back drops
out of the map and the people list within minutes, not at the next scheduled run.

## Private area

Per user. Private folders, people and single files are filtered out everywhere unless the
user unlocked them in this session (`ISession`, 30 minutes). Unlocking asks for the Nextcloud
password by default (`#[PasswordConfirmationRequired]`); turning that off needs the password
too (unlocked, or confirmed in the last 30 minutes).

While locked, a private person is treated like one the user cannot see (face, samples,
renaming, merging and search by name or id answer "not found"), `GET /api/private` gives only
counts, not the folder paths or person ids, and entries can be added but not removed. Private pictures never appear in the
global Nextcloud search or the dashboard, unlocked or not. This is a convenience against
over-the-shoulder views, not encryption: the files themselves are unchanged and visible in
the Files app as before.

## Endpoints

- All API endpoints require a logged-in user; admin settings and index actions require an
  admin (no `#[NoAdminRequired]`).
- `#[NoCSRFRequired]` only on read-only GETs that browsers load without headers (images,
  tiles, ZIP download).
- Rate limits (`#[UserRateLimit]`) on search, bulk actions, face changes, unlocking and tiles.
- Input: ids are validated as UUIDs, paths reject `..` and are resolved by Nextcloud's file
  API, tile coordinates are range-checked.

## Tested

`tests/Unit/Service/AssetSearchTest.php` covers owner, share recipient, unshared file,
unknown owner, foreign paths, custom path pattern, icon/size filter, page filling, the
thumbnail grant and its end when the share is taken back; `PrivateAreaTest.php` the private
filter; `DiscoverTest.php` duplicate groups across users; `tests/Unit/Controller/` the locked
private area (lists, password setting) and private people. Manually on flo-server
(2026-10-04): a second user sees only `/Shared/Photos`, not the owner's unshared folders, and is
refused its thumbnails; path traversal on the folder endpoint answers 404; unauthenticated
requests 401.

## Reporting

Please report security issues privately to the maintainer, not as a public issue.
