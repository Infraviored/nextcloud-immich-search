# Walkthrough: Shared Sensitive Filters

## Summary

Implemented Shared Sensitive Filters for Glimpse, enabling multiple Nextcloud users to collaborate on training and using a shared sensitive picture recognition filter.

In a household or team scenario (e.g. Florian and Tara), users can share one filter model so that both can contribute training corrections. Personal hand marks act as personal overrides, and privacy is maintained so that members never see each other's private files or pictures.

---

## Architecture & Design Decisions

### 1. Data Segregation & Storage
- **Shared Filter Model (`sensitive/filters/<filterId>.json`)**:
  - `owner`: Nextcloud user UID who owns the filter.
  - `members`: Map of `{uid: role}` where role is `'owner'`, `'editor'`, or `'user'`.
  - `terms`: Sensitive and neutral text terms used for initial centroid embeddings.
  - `svm`: Linear classifier weights `w` and bias `b`.
  - `threshold`: Sensitivity threshold.
  - `modelChanged`, `learned`, `built`: Timestamps and metadata.
- **Per-User Marks & Results (`sensitive/<uid>.json`)**:
  - `marks`: Hand corrections (`fileId -> {positive: bool, at: timestamp, assetId?: string}`).
  - `auto`: Scored file IDs matching the threshold for this user's visible files.
  - `assets`: Mapping of asset IDs to file IDs visible to this user.
  - `counts`: Aggregate counts for user's visible files (`auto`, `manual_positive`, `manual_negative`, `total`).
- **Invitations (`sensitive/invitations/<uid>.json`)**:
  - Stores pending invitations for each user (`filterId`, `owner`, `role`, `at`).

### 2. Role Model
- **`owner`**: Complete management rights: invite members, assign or change roles (`editor`, `user`), remove members, adjust terms or threshold, and train the model.
- **`editor`**: Collaborator who trains the shared filter. Their hand corrections are pooled with the owner's and other editors' marks.
- **`user`**: Consumer of the shared filter. Their hand marks serve strictly as local exceptions on their own account and do not alter the shared model.

### 3. Pooled Training & Local Precedence
- When retraining (`buildFilterModel`), Glimpse collects hand corrections from the filter's `owner` and all `editor` members.
- If multiple editors mark the same picture differently, the newer mark (`at`) takes precedence.
- For all users (owner, editor, user), personal hand marks on their own account always override the model's verdict for their files.

### 4. Privacy Guarantees
- Members of a shared filter share only:
  - Text terms and classifier hyperplane (`w`, `b`).
  - Active sensitivity threshold.
  - Role metadata and member list (UIDs).
- Member pictures, file names, folder paths, and individual markings are never exposed to other members.
- Background jobs (`RebuildSensitive`) recalculate scores separately per member, evaluating only files that resolve in each user's Nextcloud file tree.

### 5. Migration Strategy
- Automatic lazy migration occurs in `SensitiveContent::data()` if a legacy `sensitive/<uid>.json` contains a personal SVM model.
- A Nextcloud repair step (`MigrateSensitiveFilters`) is registered to migrate all user filters during app upgrades.
- Backward compatibility is preserved: users who do not collaborate retain their personal filter seamlessly.

---

## Key Changes

### Backend
- **[UserPrefs.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/lib/Service/UserPrefs.php)**:
  - Added `sensitive_filter` user preference definition with string scalar support.
- **[SensitiveContent.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/lib/Service/SensitiveContent.php)**:
  - Added filter management methods: `inviteMember`, `updateMemberRole`, `removeMember`, `leaveFilter`, `switchFilter`, `acceptInvitation`, `declineInvitation`, `share`.
  - Updated `buildFilterModel` and `rebuild` to pool marks from owner and editors while preserving local user marks.
  - Extended `status()` response with active `filter` object and pending `invitations`.
  - Added lazy migration and `migrateAll()` support.
- **[MigrateSensitiveFilters.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/lib/Migration/MigrateSensitiveFilters.php)**:
  - Registered repair step running `SensitiveContent::migrateAll()`.
- **[SensitiveController.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/lib/Controller/SensitiveController.php)** & **[routes.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/appinfo/routes.php)**:
  - `POST /api/sensitive/invite` (`memberUid`, `role`)
  - `PUT /api/sensitive/role` (`memberUid`, `role`)
  - `DELETE /api/sensitive/member` (`memberUid`)
  - `POST /api/sensitive/leave`
  - `POST /api/sensitive/switch` (`filterId`)
  - `POST /api/sensitive/invitation/accept` (`filterId`)
  - `POST /api/sensitive/invitation/decline` (`filterId`)
- **[SensitiveShareCommand.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/lib/Command/SensitiveShareCommand.php)** & **[SensitiveCommand.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/lib/Command/SensitiveCommand.php)**:
  - `occ glimpse:sensitive:share <owner> <member> [--role=editor|user]`
  - `occ glimpse:sensitive share <owner> <member> [--role=editor|user]`

### Frontend
- **[api.js](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/src/api.js)**:
  - Added client bindings for all 7 invitation and member management API endpoints.
- **[sensitiveFilter.js](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/src/sensitiveFilter.js)**:
  - Helper functions for role labels, filter descriptions, recipient candidate filtering, and "Gemeinsamer Filter mit <name>" indicator formatting.
- **[SensitiveSection.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/src/views/settings/SensitiveSection.vue)**:
  - Pending invitations banner with Accept / Decline actions.
  - Active filter card displaying filter name, owner, and current user role.
  - Member management table for owners: role selector (Editor vs User), Remove action, and pending invitation cancellations.
  - User search picker with autocomplete (`findRecipients`) and role selector to invite new members.
  - Leave filter action for members to return to their personal filter.
- **[SensitiveView.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/src/views/SensitiveView.vue)**:
  - Displays `"Gemeinsamer Filter mit <name>"` banner below the page heading when operating on a shared filter.

### Localization
- **[l10n/](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-shared-sensitive-filter-collaborate/l10n/)**:
  - Added German translations for all UI text across `de.json`, `de.js` (du) and `de_DE.json`, `de_DE.js` (Sie).

---

## Verification Results

### 1. PHPUnit Tests
Ran `vendor/bin/phpunit -c tests/phpunit.xml` in PHP 8.3 container:
```
PHPUnit 10.5.65 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.3.35
Configuration: /app/tests/phpunit.xml
OK (192 tests, 731 assertions)
```
Covers `SensitiveSharedFilterTest` (12 dedicated unit tests for invitations, member roles, leave, migration, pooled training, conflict resolution, CLI commands) and `SensitiveControllerTest` (endpoint validation).

### 2. Frontend Unit Tests (Vitest)
Ran `npm test`:
```
Test Files  10 passed (10)
     Tests  43 passed (43)
```
Includes `tests/js/sensitiveFilter.test.js` covering helper utilities, recipient candidate filtering, and formatters.

### 3. Syntax, Code Style & Linter Checks
- `make lint`: Passed (`lint ok`, PHP syntax and l10n JSON valid).
- `npm run lint`: Passed (`0 errors, 2 warnings` related to existing JSDoc conventions).
- `php-cs-fixer fix --dry-run --diff`: Passed (`Found 0 of 92 files that can be fixed`).
