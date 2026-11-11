# Walkthrough: Per-User Hidden Folders and Quick Folder View

## Summary

Implemented a per-user folder hiding feature and a quick "show only this folder" shortcut in Glimpse. Users can hide specific folders from their search results, timeline, map markers, memories, and people samples without deleting files or altering server indexing for other users. When browsing a hidden folder directly in the Folders gallery, users see a notice with a one-click "Show again" action. In addition, users can review and manage all hidden folders under *Settings → Display*.

---

## Changes

### 1. Backend Service & Persistence
- **[HiddenFolders.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Service/HiddenFolders.php)**
  - Stores per-user hidden folders in Nextcloud's `IConfig` (`glimpse`, `hidden_folders`, per-user JSON list).
  - Path normalization: strips trailing slashes, ensures leading slash.
  - Prefix matching: `$path === $f || str_starts_with($path, $f . '/')` (mirrors `PrivateArea::inPrivateFolder`, avoiding false positives such as `/A` matching `/AB`).
  - Limits: Maximum 200 hidden folders per user.
  - Safeguards: Refuses hiding the root folder (`/`).
  - Methods: `get(uid)`, `hide(uid, folder)`, `set(uid, folders)`, `isHidden(uid, path)`, `filter(uid, items)`.

### 2. Endpoints & Routes
- **[HiddenFoldersController.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Controller/HiddenFoldersController.php)** & **[routes.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/appinfo/routes.php)**
  - `GET /api/hidden-folders`: Returns `{"folders": string[]}` for the current user.
  - `POST /api/hidden-folders`: Body `{ "fileId": <int> }`. Resolves the file node from the user's files, resolves parent directory path relative to user root, rejects root `/` with `400 Bad Request` (`The root folder cannot be hidden`), normalizes path, adds to list, and returns `{"folder": string, "folders": string[]}`.
  - `PUT /api/hidden-folders`: Body `{ "folders": string[] }`. Sanitizes and replaces list, returning `{"folders": string[]}`.

### 3. Permission & Visibility Filtering
- **[ApiController.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Controller/ApiController.php)**: Filters items in `search()` and `private()`.
- **[BrowseController.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Controller/BrowseController.php)**:
  - `filter()`: Applies `hiddenFolders->filter($uid, ...)` to timeline and other browse queries.
  - `map()`: Excludes markers whose folder path is hidden.
  - `folder()`: Explicit exception — returns folder contents unfiltered so users can still view pictures when navigating directly into a folder, but includes `'hidden' => true` when the folder or an ancestor is hidden.
- **[MemoriesWidget.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Dashboard/MemoriesWidget.php)**: Excludes items in hidden folders from dashboard memories.
- **[ImmichProvider.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Search/ImmichProvider.php)**: Filters unified search results.
- **[PeopleController.php](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/lib/Controller/PeopleController.php)**: Filters people samples (`samples()`) to exclude faces in hidden folders.

### 4. Frontend State & Interactions
- **[api.js](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/api.js)**: Added `getHiddenFolders()`, `hideFolder(fileId)`, and `setHiddenFolders(folders)`.
- **[hiddenFolders.js](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/hiddenFolders.js)**:
  - Reactive `hiddenFoldersState` with `version` counter for cache-busting / component reloading.
  - `hideFolder(fileId)`: Calls API, increments version, and displays an undo toast (`showUndo`) with a restore action.
- **[App.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/App.vue)**: Bound `version` computed property to `hiddenFoldersState.version` to trigger component reloads on changes.
- **[ViewerBar.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/components/ViewerBar.vue)**:
  - Added "Show only this folder" row (`mdiFolderEyeOutline`) opening `/folders?path=<folder>`.
  - Added "Hide this folder" row (`mdiFolderOffOutline`) when folder is not root `/`, closing viewer and invoking `hideFolder(fileId)` with undo toast.
- **[SelectionBar.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/components/SelectionBar.vue)**:
  - Added "Show only this folder" and "Hide this folder" for single-item selections.
- **[FolderView.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/views/FolderView.vue)**:
  - Displays slim banner `folders__notice` when `data.hidden` is true: *"This folder is hidden from your timeline and search"*.
  - Provides a "Show again" button that strips the folder from the user's hidden list, persists via PUT, and updates UI state.
- **[DisplaySection.vue](file:///home/flo/repos/nextcloud-immich-search/.wt/glimpse-web-ordner-ausblenden-nur-diesen-ordner/src/views/settings/DisplaySection.vue)**:
  - Added "Hidden folders" list showing active hidden folders with remove (`×`) buttons and helpful empty state copy.

### 5. Localization
Added German informal (`l10n/de.json`, `l10n/de.js`) and formal (`l10n/de_DE.json`, `l10n/de_DE.js`) translations for all new strings:
- "Show only this folder" -> "Nur diesen Ordner zeigen"
- "Hide this folder" -> "Diesen Ordner ausblenden"
- "“{folder}” hidden" -> "„{folder}“ ausgeblendet"
- "Hidden folders" -> "Ausgeblendete Ordner"
- "Hide a folder from a picture's menu." -> "Einen Ordner über das Menü eines Bildes ausblenden."
- "This folder is hidden from your timeline and search":
  - informal: "Dieser Ordner ist in deiner Zeitleiste und Suche ausgeblendet"
  - formal: "Dieser Ordner ist in Ihrer Zeitleiste und Suche ausgeblendet"
- "The root folder cannot be hidden" -> "Der oberste Ordner kann nicht ausgeblendet werden"

### 6. Version & Changelog
- Bumped version in `appinfo/info.xml` to `4.13.0`.
- Documented changes under `## [4.13.0] - unreleased` in `CHANGELOG.md`.

---

## Test Results

### 1. Backend PHPUnit Tests
Executed test suite covering `HiddenFoldersTest` and `HiddenFoldersControllerTest`:
```
Runtime:       PHP 8.3.35
Configuration: /app/tests/phpunit.xml

...............................................................  63 / 172 ( 36%)
............................................................... 126 / 172 ( 73%)
..............................................                  172 / 172 (100%)

Time: 00:01.694, Memory: 20.00 MB
OK (172 tests, 679 assertions)
```
- Path prefix test verifies `/A` hides `/A/file.jpg` and `/A/B/file.jpg`, but does not hide `/AB/file.jpg`.
- Deduplication and 200-folder cap tests verified.
- Root `/` rejection and error message verified.
- User separation in `IConfig` verified.

### 2. Code Style & Lint
- `php-cs-fixer fix --dry-run --diff`: 0 files to fix across 88 files.
- `make lint`: PHP syntax and translation JSON validation passed (`lint ok`).
- `npm run lint`: ESLint passed with 0 errors.

### 3. Frontend Vitest Tests
Executed frontend tests including `tests/js/hiddenFolders.test.js`:
```
 ✓ tests/js/sensitiveRules.test.js (4 tests) 8ms
 ✓ tests/js/api.test.js (5 tests) 14ms
 ✓ tests/js/serialSaver.test.js (2 tests) 20ms
 ✓ tests/js/people.test.js (1 test) 10ms
 ✓ tests/js/viewerFix.test.js (3 tests) 25ms
 ✓ tests/js/selection.test.js (9 tests) 8ms
 ✓ tests/js/private.test.js (4 tests) 13ms
 ✓ tests/js/router.test.js (4 tests) 5ms
 ✓ tests/js/hiddenFolders.test.js (2 tests) 26ms

 Test Files  9 passed (9)
      Tests  34 passed (34)
```

### 4. Frontend Asset Build
`npm run build` compiled all client entry points (`glimpse-main.mjs`, `glimpse-files.mjs`, chunks, and localization bundles) without errors.
