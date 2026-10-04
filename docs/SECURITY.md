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
   (`FaceCropper`), never Immich's person thumbnail. Map tiles are proxied.
3. **Actions run as the user** through Nextcloud APIs (move, copy, delete to trash, tags,
   favorites, albums via WebDAV). A share without delete permission cannot be moved out of.
4. **Changes to Immich** (names, merges, faces, duplicates dismissed) require the person or
   picture to be visible to the user, and the admin's people setting (all / admins / nobody).
5. **The Immich API key** is stored encrypted in app config, readable only by admins' settings
   page (never sent to the browser), and set via stdin on the command line.

## What is shared between users

- **Person names.** One Immich account, one set of people. A name typed by one user is seen
  by every user who can see a picture of that person. Admins can restrict naming.
- **The years that have pictures** (timeline year links) – not their contents.
- Index timing (when people/map were last built).

## Private area

Per user. Private folders, people and single files are filtered out everywhere unless the
user unlocked them in this session (`ISession`, 30 minutes). Unlocking asks for the Nextcloud
password by default (`#[PasswordConfirmationRequired]`). Private pictures never appear in the
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
unknown owner, foreign paths, custom path pattern, icon/size filter, page filling and the
thumbnail grant; `PrivateAreaTest.php` the private filter. Manually on flo-server
(2026-10-04): Tara sees only `/Shared/Photos`, not Flo's unshared `snapchat_backup`, and is
refused its thumbnails; path traversal on the folder endpoint answers 404; unauthenticated
requests 401.

## Reporting

Please report security issues privately to the maintainer, not as a public issue.
