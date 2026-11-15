# Joplix 2026.11.79

- Unsaved changes are no longer lost silently: closing or reloading the tab, or leaving Joplix, asks first while a note has unsaved text.
- When a note was changed elsewhere (synchronisation or another window) while it was open, saving now offers a choice instead of failing over and over: save your version as a separate copy, overwrite the other version, load the other version, or keep editing.
- The browser cache of notes and of the note index is now tied to the Nextcloud account. If a different account uses the same browser without a regular logout in between (expired session, closed browser), the previous account's cached notebooks and notes are removed before anything is shown.
- Synchronisation no longer overwrites a note that is saved while a synchronisation is running. The file is checked again at the moment it is replaced; if it changed, your version is kept as a conflict note, as before for changes made between two runs. Saving and synchronising now take turns per note instead of running into each other.
- A change saved at the very moment a note is received from the server is no longer skipped by the next upload.
- Notes can only be created in or moved to an existing notebook, and tags can only be assigned to an existing note and tag.

# Joplix 2026.11.78

- Photos attached in Joplix now appear in the note: an image is inserted as an embedded image (`![photo.jpg](:/id)`, as the Joplin apps do) instead of a plain link. Images already attached as a link stay links; put a `!` in front of the link in the editor to show them as a picture.
- Images that Joplin desktop resized (stored as `<img src=":/id" width="…">`) are shown as pictures at that width instead of as text.
- Clicking an image in a note, an image's name in the attachment list, or its "Open" button shows the image in a preview window, with a separate Download button. Audio and video attachments play in the same window.
- "Open" no longer downloads the file: PDFs open in a new browser tab. Saving a file is now only done by the download button (or the Download button in the preview). Other file types, which the browser cannot show, still download when their link in the note is clicked.

# Joplix 2026.11.77

- Attach files directly from Nextcloud: the "⋯" menu has a new item "Attach from Nextcloud", and the paperclip in the editor toolbar now asks whether to attach from this device or from Nextcloud. A picker shows your Nextcloud files (folders first, filter field, keyboard navigation) and opens in the folder you used last. The chosen file is copied on the server into the note as a normal Joplin attachment, so it syncs to the Joplin apps like an uploaded one, and large files do not have to pass through the browser.
- Files larger than 25 MB are marked in the picker, and attaching one asks for confirmation first, because every attachment is transferred to each synced Joplin device. Nothing is blocked.
- The Joplin sync folder itself and the Joplix workspace folder (`.joplix`) are not shown in the picker.
- After attaching a file (from either source) the attachment list below the note updates right away; before, it only showed the new file after reopening the note.
- A notebook's "⋯" menu could close immediately when opened again after it had been closed with Escape.

# Joplix 2026.11.76

- Small screens (single-column view): the notebook list is now a sidebar that slides in over the notes, like the navigation in other Nextcloud apps such as Collectives. Open it with the menu button next to the search field; choosing a notebook, tapping beside it or pressing Escape hides it again.
- Notebooks with sub-notebooks can be collapsed and expanded with the arrow in front of their name, as in the Joplin apps. Joplix remembers which notebooks are collapsed in this browser. Opening a note or notebook inside a collapsed notebook (for example through a note link) expands it again.

# Joplix 2026.11.75

- "Link to note": the result rows were squashed into each other when the list had more matches than fit; each row now shows its full title and notebook, and the list scrolls.

# Joplix 2026.11.74

- Links to other notes (`[Title](:/id)`, as Joplin's "Copy Markdown link" creates them) no longer appear under Attachments as "application/octet-stream · File missing", and they no longer count as attachments in the note list or search.
- Clicking a note link in the preview opens the linked note, also when it is in another notebook. A link to a note in the trash says so instead. Links to notebooks open the notebook.
- New editor button "Link to note": search for a note and insert a link to it at the cursor. Selected text becomes the link text; otherwise the note title is used. The link is written exactly as Joplin writes it, so it works in the Joplin desktop and mobile apps.
- Link texts with escaped brackets (`\[`, `\]`) are shown correctly in the preview.
- The index is rebuilt once automatically after the update.

# Joplix 2026.11.73

- Notes and notebooks deleted in a Joplin app no longer stay in the note list. Joplin moves deleted items to its trash, which keeps the file on the server with a deletion mark; Joplix ignored that mark for notes.
- New: a trash that works like Joplin's and is shared with it. "Trash" appears below the notebooks whenever something is in it. Notes there open read-only and can be restored or deleted permanently; "Empty trash" removes everything.
- Deleting a note or notebook in Joplix now moves it to the trash instead of removing it for good, so it can be restored from Joplix or from any Joplin app. Deleting a notebook takes its sub-notebooks and notes along.
- Restoring a note whose notebook is itself in the trash asks which notebook it should go to. A restored sub-notebook whose parent is in the trash becomes a top-level notebook.
- Items are removed from the Nextcloud folder only when they are deleted permanently or the trash is emptied, in Joplix or in Joplin.
- The index is rebuilt once automatically after the update.

# Joplix 2026.11.72

- Fixes the in-app updater (Settings › Update), which rejected every release with "ZIP enthaelt keine gueltige Joplix-App".
- Installations up to 2026.11.71 still contain the faulty updater: update them once with `install/install.sh`. From 2026.11.72 on, updating inside Nextcloud works.

# Joplix 2026.11.71

- A newly created note now appears in the note list, and the notebook count goes up, right after saving. Title changes also reach the list without reloading the page.

# Joplix 2026.11.70

- The notebook sidebar now uses the Nextcloud navigation background, so it is translucent over the wallpaper like in other Nextcloud apps.
- Browsers (Edge, Chrome) no longer offer to translate the page from German. English is applied immediately on load instead of after the settings request.

# Joplix 2026.11.69

- The interface now follows the Nextcloud design: colours, radii and control sizes come from the active Nextcloud theme, so light, dark, high-contrast and custom accent colours all apply. Emoji buttons are replaced by icons.
- Column order is now notebooks, note list, note (as in Nextcloud Mail and Notes). Column widths can still be dragged; saved widths from the old layout are reset once.
- One toolbar per note: Preview/Edit switch, Save, and a menu with attach file, rebuild index and delete note. Saving keeps the current mode. Ctrl/Cmd+S saves.
- The preview renders tables, block quotes, nested lists, strikethrough, highlights, headings up to level 6, plain links, and images and attachment links that point to Joplin resources.
- Browser pop-ups (alert, confirm, prompt) are replaced by in-app dialogs; moving a note offers a notebook list instead of asking for a number. Saving shows a short notification.
- On narrow windows one column is shown at a time, with back buttons.
- Unchanged: the editor is still the Markdown source, and a note is saved exactly as typed. The preview never rewrites a note; ticking a checkbox changes only that line.

# Joplix 2026.11.68

- Declares compatibility with Nextcloud 35 (supported range is now 32 to 35).
- The install and uninstall scripts print English messages and list the detected instances when more than one Nextcloud is found.

# Joplix 2026.11.67

- The integrated updater and the one-step installer now use the GitHub repository `kane9287/joplix-xsync` instead of Codeberg. A stored Codeberg update address is replaced by the GitHub address automatically.
- A release no longer needs an attached package: if no `JoplixNC_v<version>.zip` asset exists, the source archive of the release tag is installed. The version in the tag must equal the version in `appinfo/info.xml`.
- GitHub pre-releases form the Beta channel, regular releases the Main channel.
- `install/install.sh` and `install/uninstall.sh` are complete, working scripts again (the previous files were truncated).
- English mode now translates the whole interface, including status messages, dialogs, tooltips and the welcome dialog.

# Joplix 2026.11.66-beta

- Adds two-way synchronisation with a self-hosted Joplin Server (Settings › Synchronisation). Joplix pulls changes from the server into the Joplix folder and pushes edits, new items and deletions made in Joplix.
- The Joplix folder in Nextcloud therefore doubles as a backup: every synchronised change is written through the Nextcloud files API, so Nextcloud file versions and the trash bin keep earlier states and remotely deleted items.
- Synchronises when Joplix opens, shortly after each save, every two minutes while open, and every five minutes through a Nextcloud background job even when Joplix is closed.
- When a note was changed both in Joplix and on another device, the server version wins and the Joplix edit is kept as a Joplin conflict note.
- Refuses to synchronise accounts with end-to-end encryption, and asks for confirmation before deleting a large share of the library on the server (for example after the folder was emptied or moved).
- The Joplin Server password and session are stored encrypted per user and are never returned to the browser. Local or private server addresses must be allowed by an administrator.
- Joplin apps must synchronise with the Joplin Server only. Do not point a Joplin app at the Joplix folder over WebDAV while this synchronisation is enabled.

# Joplix 2026.11.65-beta

- Resolves the selected release and ZIP server-side from the saved Codeberg repository and channel.
- Validates archive paths, app identity, version and Nextcloud compatibility before activation.
- Serializes installations and restores the previous app files on activation or upgrade failure.
- Keeps the release ZIP compatible with the Beta 64 updater.

# Joplix 2026.09.64-beta

- Restricts package management and installation to Nextcloud administrators and requires CSRF tokens for update and settings requests.
- Keeps integrated Codeberg updates available. Listing releases no longer writes settings or changes the workspace.
- Stops automatic migration and deletion of legacy folders in the user's files.
- Preserves note text ending in lines such as `source: …` and saves newly inserted attachment links.

# Joplix 2026.09.63-beta

## Test release

- No functional changes compared with Beta 62.
- Updates the internal application and project version metadata to `2026.09.63` solely to validate Beta 62 → Beta 63 installation through the integrated updater.
- Nextcloud compatibility remains unchanged (32–34).

# Joplix 2026.09.62-beta

- Test release based directly on Beta 61.
- No functional Joplix changes.
- Updates the internal application and project version metadata to `2026.09.62` to validate Beta 61 → Beta 62 installation through the integrated updater.
- Keeps Nextcloud 32 through 34 compatibility and the canonical `JoplixNC_v<version>[-beta].zip` release naming unchanged.

# Joplix 2026.09.61-beta

- Adds declared compatibility with Nextcloud 32, 33 and 34.
- Lowers the Nextcloud minimum version from 34 to 32 while keeping the maximum supported version at 34.
- Keeps the functional Joplix behavior from Beta 60 unchanged so Nextcloud 32 compatibility can be tested with minimal release differences.
- Keeps the canonical `JoplixNC_v<version>[-beta].zip` updater and release naming introduced in Beta 60.

# Joplix 2026.09.60-beta

- Fixes the integrated Codeberg updater installation for canonical `JoplixNC_v<version>[-beta].zip` release assets.
- Keeps the downloaded Codeberg asset filename unchanged instead of appending `_remote_<timestamp>`, which caused the installation-stage package-name validation to reject an otherwise valid release.
- Keeps release discovery, download validation and installation validation on the same `JoplixNC_v<version>[-beta].zip` naming scheme.
- Replaces the obsolete Nextcloud config fallback with `OCP\Server::get(IConfig::class)`.

# Joplix 2026.08.59-beta

- Fixes notebook and sub-notebook deletion in Beta 58.
- Removes the failing pre-confirmation content-count call that could prevent any dialog from appearing.
- Adds a dedicated Joplix confirmation dialog instead of relying on the browser confirm dialog.
- Clearly warns in German and English that contained notes and sub-notebooks are permanently deleted.
- Keeps visible deletion status and error reporting after confirmation.

# Joplix 2026.08.58-beta

- Standardizes notebook terminology: Notebook and Sub-notebook (German: Notizbuch and Unter-Notizbuch).
- Removes the ambiguous plus button next to the Notebooks heading; root notebooks are created only with New notebook, sub-notebooks from the parent notebook context menu.
- Replaces the old LÖSCHEN text-entry prompt with a clear permanent-delete confirmation for notebooks and sub-notebooks.
- Shows affected notes/notebooks and visible success/error status during deletion.
- Updates the internal Codeberg updater to accept the unified JoplixNC_v<version>[-beta].zip release naming.

# Changelog

## 2026.08.57 Beta - 2026-08-17

- Update notification below the Joplix version is now rendered for administrators only.
- Normal users no longer receive or see the automatic update notification in the Joplix sidebar.
- Added a server-rendered admin flag as an additional client-side guard for update checks.
- Update endpoints remain protected by the existing server-side administrator check.

## 2026.08.55 Beta - 2026-08-17

- Update settings and update execution are restricted to Nextcloud administrators; update endpoints enforce admin rights server-side.
- Joplix data-folder setting remains user-specific and server user settings now take precedence over browser-local cached values.
- Visible Joplin product wording in settings was replaced with Joplix wording.
- Added per-user language setting: System language, Deutsch, English. System mode selects German for de/de-* and English for all other languages.
- Added initial German/English UI localization framework with English fallback.

## 2026.08.54 Beta - 2026-08-17

- Version-only beta release for Uplix upload/release testing.
- No functional code changes.

## 2026.08.53 Beta - 2026-08-17

- Version-only beta release for Uplix upload/release testing.
- No functional code changes.

## 2026.08.52 Beta - 2026-08-14

- Completes Nextcloud's app-specific upgrade lifecycle immediately after the integrated updater replaces the Joplix files.
- Updates `joplix/installed_version`, runs future Joplix migrations and repair steps, registers background jobs and dispatches the normal app-update event through Nextcloud's public `IAppManager` API.
- Prevents the global "App update required" screen and installation-wide repair run on the next page load.
- Verifies that Nextcloud persisted the expected Joplix version before reporting the update as successful.

## 2026.08.50 - 2026-08-14

- Changed the release version to the `YYYY.MM.Sprint` calendar scheme.
- Kept numeric updater comparison compatible with both legacy `2.x` releases and new calendar versions.
- Preserved calendar-version formatting in the update API and user interface.
- Fixed note and notebook deletion on Joplin WebDAV/filesystem sync targets: Joplix now removes the remote item file, which is the deletion signal expected by Joplin clients.
- Removes note-tag relation items together with deleted notes so they cannot remain as broken sync objects.
- Continues to create a recoverable Joplix backup before each deleted sync item is removed.
- Reviewed the complete PHP and browser source for performance hotspots; no large or risky refactor is included in this release.

## 2.3.0-beta.10 - 2026-08-02

### UI

- Fixed bright white release asset rows in the dark settings theme.
- Made release filenames readable with theme-aware text and backgrounds.
- Styled **Installieren** as a clear Nextcloud primary action.
- Added consistent hover, focus and pressed feedback for update assets.

## 2.3.0-beta.9 - 2026-08-02

### UI

- Added complete light and dark theme support to the full Joplix settings overlay.
- The settings navigation, headings, cards, form controls, buttons, update status and release list now use consistent theme-aware colors.
- Improved contrast for disabled and read-only fields in dark mode.
- Kept the existing light settings appearance while removing bright white cards from the dark theme.

## 2.3.0-beta.8 - 2026-08-02

### UI

- Made attachment actions clearly recognizable as buttons in the light Nextcloud theme.
- Styled **Bearbeiten** as the primary Nextcloud-colored action.
- Styled **Download** as a bordered secondary action with hover, focus and pressed feedback.
- Improved the attachment card background, border and spacing in light mode.
- Preserved the restrained attachment-card appearance in dark mode.

## 2.3.0-beta.7 - 2026-08-02

### Added

- Every tag chip now includes an accessible remove button.
- The note context menu tag editor can now remove assignments by deleting names from the comma-separated list.

### Fixed

- Tags can be detached from a note without deleting the global tag itself.
- Tag removal updates the open note, browser cache, note list and local index immediately.
- Failed server writes restore the previous tag state instead of leaving the interface inconsistent.

### Compatibility

- Removal deletes only the Joplin `type_: 6` note-tag relation file.
- The Joplin `type_: 5` tag remains available to other notes.
- Note content, resources and synchronization metadata are unchanged.

## 2.3.0-beta.6 - 2026-08-02

### UI

- Reworked the index and background-refresh status below full-text search into a native-looking theme-aware status card.
- Added clear success, progress and error indicators with readable foreground and background contrast.
- Added explicit dark-mode colors so status text remains legible with every Nextcloud appearance setting.
- Harmonized spacing between full-text search, status feedback and the note list.

## 2.3.0-beta.5 - 2026-08-02

### Removed

- Removed the built-in GitHub/Joplin compatibility monitor from the Joplix runtime.
- Removed automatic checks against `laurent22/joplin`, compatibility API endpoints and generated reports.
- Removed all `compat_*` settings and the heuristic read-only mode that could block Joplix writes.
- Removed compatibility-specific UI and theme CSS remnants.

### Migration

- Deletes legacy per-user `compat_*` configuration values automatically.
- Deletes `/.joplix/joplin_compatibility_report.json` when present.
- Leaves note, resource, Office, update and Joplin sync behavior unchanged.

## 2.3.0-beta.4 - 2026-08-02

### Fixed

- Restored mouse and touchpad resizing for the left and right columns.
- Applied runtime grid widths with the same priority as the hardened five-column CSS layout.
- Replaced the legacy mouse-only drag handler with Pointer Events and pointer capture.
- Persisted the selected left and right column widths in browser storage as before.
- Added double-click reset on each divider for its default width.

## 2.3.0-beta.3 - 2026-08-02

### Fixed

- Made the note path bar follow the active Nextcloud light or dark theme.
- Made the index status bar theme-aware instead of leaving a bright background in dark mode.
- Removed nested and duplicate outlines from notebook, chapter and subchapter rows.
- Applied hover and active styling only to the complete notebook row while keeping its child controls transparent.

## 2.3.0-beta.2 - 2026-08-02

### UI

- Added a native dark mode that follows the active Nextcloud appearance automatically.
- Replaced fixed light colors across the sidebar, editor, note list, dialogs, settings, context menus and resource cards with Nextcloud theme variables.
- Added a dedicated muted Joplix brand icon for dark mode.
- Replaced the Nextcloud header/navigation icon with a monochrome theme-compatible SVG.
- Preserved the existing light appearance and all layout, Office, sync, update and resource behavior.

## 2.3.0-beta.1 - 2026-08-02

### Architecture

- Added the unified hidden `/.joplix` workspace with `backups`, `cache`, `editing`, `logs`, `updates` and `updater` directories.
- Added `version.json` with engine and schema versions for future migrations.
- Added `lock.json` as the central state file for update, index, cleanup and per-resource Office locks.
- Added automatic migration of legacy `JoplixUpdates`, `Joplix Editing`, `joplixbackup*` and `joplixwebupdate*` folders.
- Changed the development/update package default from `/JoplixUpdates` to `/.joplix/updates`.
- Added centralized cleanup for completed or stale Office sessions, updater files, caches, logs and optional backups.
- Kept app backups disabled by default.

### Compatibility

- Existing checkbox, tag, Office/EuroOffice, resource, index, sync and one-step installer behavior remains unchanged.

## 2.2.0-beta.6 - 2026-08-02

### Changed

- Added a dedicated Joplix folder icon to every notebook, chapter and nested chapter in the left navigation tree.
- Keeps notebook counts, indentation, selection highlighting and action menus unchanged.
- Uses a lightweight local SVG mask so the icon automatically follows the current text and active-state color.

## 2.2.0-beta.5 - 2026-08-02

### Fixed

- Automatically removes historical `joplixbackup*`, `joplixwebupdate*`, `joplix.webupdate*` and similar app backup directories from the Nextcloud `apps` directory before and after a web update.
- Deletes legacy app backups when automatic backups are disabled; when enabled, archives them outside the app directory.
- Prevents Nextcloud from treating obsolete updater backups as applications and raising `AppPathNotFoundException`.
- Waits for delayed EuroOffice final-save callbacks after the editor window closes.
- Polls the working copy until its content hash is stable before reporting that the XLSX file was fully written back.
- Ensures the final Excel change is present in the original Joplin resource before the success message is shown.

## 2.2.0-beta.4 - 2026-08-02

### Fixed

- Serializes Office write-back polling so the regular poller and window-close check cannot import the same save concurrently.
- Waits for an active import before performing the final check after EuroOffice closes.
- Treats an identical resource binary that was already written by another request as a successful idempotent operation instead of a conflict.
- Shows a real conflict only when the server resource differs from both the original resource and the edited working copy.
- Removes the short-lived false conflict message after a successful Office save.

## 2.2.0-beta.3 - 2026-08-02

### Fixed

- Opens prepared Office working copies directly through the registered EuroOffice editor route `/apps/eurooffice/{fileId}`.
- Removes the unreliable Nextcloud Files DOM lookup and simulated file click.
- Keeps the existing hidden `.joplix/editing` session, automatic write-back polling and Joplin resource synchronization unchanged.
- Retains the Files working-copy path only as session metadata and fallback context.

## 2.2.0-beta.2 - 2026-08-02

### Fixed

- Restored the integrated update check after the attachment-manager refactor.
- Made `JoplixDevUpdateService` compatible with both legacy two-argument construction and the newer configuration-aware construction.
- Prevents partially updated installations from failing with `Too few arguments` while checking or installing releases.
- Keeps automatic app backups disabled by default and preserves the hidden `.joplix` workspace behavior.

## 2.2.0-beta.1 - 2026-08-02

### Added
- Added a generic attachment resource manager with type-specific actions.
- Office files show **Edit** and **Download**.
- PDF and image files show **Open** and **Download**.
- Audio and video files show **Play** and **Download**.
- Archives and unknown files provide **Download**.
- Added the hidden per-user workspace `.joplix/editing`, `.joplix/backups`, `.joplix/cache` and `.joplix/logs`.
- Added automatic cleanup of editing sessions older than 24 hours.
- Added an update setting for optional app backups; backups are disabled by default.

### Changed
- Optional app backups are stored below the hidden Nextcloud data path `.joplix/backups`.
- Old web-update backup folders are moved out of the Nextcloud apps directory.
- The existing Joplin resource IDs and synchronization format remain unchanged.

## 2.1.4 Beta - 2026-08-02

### Fixed

- Web-update backups are no longer created inside the Nextcloud `apps` directory.
- Backups are stored under the configured Nextcloud data directory in `joplix-app-backups`.
- Existing legacy `joplixwebupdate_*` and `joplix.webupdate_*` directories are moved out of the app directory before installation.
- Prevents `OCP\App\AppPathNotFoundException` during Nextcloud repair and upgrade operations.
- The update dialog now reports the complete backup path.

## 2.1.3 Beta - 2026-08-02

### Changed

- After creating the Office working copy, Joplix now locates the exact file in Nextcloud Files by file ID or filename.
- Joplix triggers the registered Nextcloud default file action automatically, equivalent to clicking the file manually.
- The implementation remains independent of the installed Office provider and therefore works with the Office integration registered in Nextcloud.
- If the automatic action cannot be found within 20 seconds, Joplix keeps the working copy visible and asks the user to click the filename once.

### Rollback

- Joplix 2.1.2 Beta remains the known rollback state for the Office working-copy workflow.

## 2.1.2 Beta - 2026-08-02

### Fixed

- Opens the prepared Nextcloud Office working copy through its direct Nextcloud file ID route.
- Replaces the ineffective `openfile` query parameter that only displayed the `Joplix Editing` folder.
- Keeps the configurable separate-window or new-tab behavior and automatic resource write-back unchanged.

## 2.1.1 Beta - 2026-08-02

### Added

- Added a per-user setting for opening Nextcloud Office attachments in a dedicated browser window or a new browser tab.
- Dedicated window mode is the default on desktop browsers.
- Closing the Office window triggers an immediate final check for saved changes.
- Added a clear popup-blocker error with instructions to allow pop-ups for the Nextcloud site.

### Compatibility

- Existing Office work-copy, automatic write-back and Joplin resource synchronization remain unchanged.

## 2.0.5 Beta - 2026-08-02

### Improved

- New tags are displayed immediately using an optimistic temporary tag.
- Added a combined API endpoint that creates or reuses a tag and assigns it to the note in one model load.
- The temporary tag ID is replaced by the real Joplin tag ID after the server response.
- Failed writes roll the optimistic UI state back and show a clear error.
- The Joplin tag (`type_: 5`) and note-tag relation (`type_: 6`) formats remain unchanged.

## 2.0.4 Beta - 2026-08-02

### Fixed

- New tags now appear immediately after the server has written the tag and note-tag relation.
- Removed the blocking full index rebuild from the tag-creation and tag-assignment API requests.
- The existing local tag update can now run immediately instead of waiting roughly 15 seconds for index generation.

### Improved

- The browser updates the open note, note list and local caches first.
- Index rebuilding continues through the existing asynchronous background refresh.
- Tag persistence and Joplin synchronization remain unchanged.

### Compatibility

- No changes to the Joplin tag files or type 6 note-tag relations.
- No changes to checkbox rendering, note saving, metadata handling or sync locking.

## 2.0.3 Beta - 2026-08-02

### Fixed

- Newly assigned tags are displayed immediately in the open note without a browser reload.
- The tag indicator and count in the note list update immediately after a successful assignment.
- Updated tags are written to the memory cache, browser cache and local index together.
- Older in-flight note requests can no longer overwrite the newly assigned local tag state.

### Improved

- Tag assignment no longer triggers a second blocking full index rebuild in the browser.
- A short background check confirms the already rebuilt server index without interrupting the current note.
- Duplicate tag assignments are prevented in the direct Add Tag workflow.

### Compatibility

- The existing server-side Joplin tag and synchronization logic is unchanged.
- Checkbox rendering, note saving and the save queue remain unchanged.

## 2.0.2 Beta - 2026-08-02

### Fixed

- Removed all legacy and competing checkbox layout rules.
- Added one authoritative task-list reset scoped only to rendered checkbox lists.
- Neutralized inherited Nextcloud and browser margins, padding, minimum heights and list markers.
- Checkbox rows now use compact flex alignment with a fixed checkbox column and aligned text.

### Compatibility

- The Markdown checkbox source format remains unchanged.
- The server-side Joplin save, metadata, conflict and synchronization logic is unchanged.
- The existing save queue and visible saving indicator remain active.

## 2.0.1 Beta - 2026-08-02

### Fixed

- Keeps checkbox rows separated by blank Markdown lines inside one rendered task list.
- Removes the empty paragraph spacing that made checkbox rows appear displaced.
- Applies one final, browser-independent checkbox layout with aligned checkbox and text columns.

### Improved

- Shows a spinning save indicator while checkbox changes are written.
- A clicked destination note is highlighted while Joplix waits for the active save to complete.
- Note switching continues automatically after a successful save.

### Compatibility

- The server-side Joplin write and synchronization path is unchanged.
- No Joplin metadata, revision or conflict-handling logic was changed.

## 2.0.0 Beta - Checkbox reliability restart

### Fixed

- Task-list containers no longer inherit the browser default list indentation.
- Checkbox rows align consistently at the left edge with their text.
- A note change waits for an already running save instead of showing a false unsaved-changes warning.
- Successful checkbox saves update the local note cache and dirty snapshot immediately.
- Existing-note saves no longer block on a full index rebuild; the index refresh runs in the background.
- Returning to a saved note keeps exactly the persisted checkbox state.

### Compatibility

- The server-side Joplin write and synchronization path is unchanged.
- Existing note metadata preservation, conflict checks, backups and sync locks remain intact.

## 1.0.63 Beta - Notizwechsel nach Checkbox-Speichern

- Korrigiert: Nach Checkbox-Aenderungen, Speichern und anschliessendem Notizwechsel wird der Wechsel nicht mehr blockiert.
- Schnell-Speichern bei Dirty-State ueberspringt den Index-Rebuild und setzt den Dirty-State explizit zurueck.
- Die Notizliste kann nach dem Speichern sofort die Zielnotiz oeffnen.
- Basis: 1.0.62 Beta.

## 1.0.60 Release - Anzeige-Modus stabilisiert

- Checkbox-Speicherung im Anzeige-Modus mit Save-Queue stabilisiert, damit schnelle Klicks nicht verloren gehen.
- Speichern aus der Anzeige bleibt im Anzeige-Modus und laedt die Notiz nicht unnoetig neu.
- Versionsmetadaten auf 1.0.60 gesetzt, damit Header, Ueber-Fenster und Updatefenster dieselbe installierte Version anzeigen.
- Pflichtdokumentation fuer Release aktualisiert.

## 1.0.59 Beta - Anzeige-/Bearbeiten-Modus

- Standardmodus fuer geoeffnete Notizen ist nun Anzeige statt Editor.
- Markdown wird im Anzeige-Modus gerendert.
- Checkboxen koennen direkt in der Anzeige geklickt werden und speichern wieder in den Markdown-Body.
- Bearbeiten laedt Editor und Toolbar gezielt nach Bedarf.
- App-Version, Pflichtdokumentation und project_version.json wurden aktualisiert.

## 1.0.58 Beta - Update-Logik Fix
- Aktuell installierte Version wird im Update-Fenster sichtbar synchronisiert.
- Gleiche Versionsnummer wird nicht mehr als Beta-Update angeboten, wenn dieselbe Version bereits als Main/Stable installiert ist.
- Erlaubte Wechsel: Main 1.0.57 -> Beta 1.0.58 und Beta 1.0.57 -> Main 1.0.57.
- Codeberg-ZIP wird flach gebaut, ohne joplix-Unterordner.

## 1.0.61 - Release

- Checkbox-Aenderungen im Anzeige-Modus werden nicht mehr sofort automatisch gespeichert, sondern sauber als ungespeicherte Aenderung markiert.
- Sobald im Anzeige-Modus eine Checkbox geaendert wird, erscheint ein Speichern-Button oben neben Bearbeiten und zusaetzlich unten im Aktionsbereich.
- Der normale Bearbeiten-Modus bleibt unveraendert: Speichern wird erst aktiv, wenn tatsaechlich eine Aenderung vorhanden ist.
- Stabilisierung der Dirty-State-Logik: beide Speichern-Buttons verwenden denselben Zustand und werden nach erfolgreichem Speichern wieder deaktiviert.
- Korrektur der README- und CHANGELOG-Struktur fuer Release-Tools, damit Version 1.0.61 eindeutig gefunden werden kann.

## 1.0.62 Beta - 2026-08-02
- Dirty-State-Schutz beim Notizwechsel fuer ungespeicherte Checkbox- und Textaenderungen.
- Checkbox-Klicks im Anzeige-Modus veraendern nur die lokale Arbeitskopie und aktivieren Speichern.
- Sauberere Checkbox-Zeilen im Anzeige-Modus.

## 1.0.64 Beta - Checkbox Save und Layout Fix

- Speichern vor Notizwechsel robuster gemacht: Nach erfolgreichem Speichern werden originalNote und dirty-State sofort synchronisiert.
- Wechsel auf eine andere Notiz nach gespeichertem Checkbox-Änderungsstand blockiert nicht mehr durch eine alte Dirty-Erkennung.
- Checkbox-Zeilen im Anzeige-Modus werden als Flex-Zeile gerendert: Checkbox direkt vor Text mit sauberem Abstand.
- FastDirtySave vermeidet unnötiges Reload während des Wechselvorgangs und hält den lokalen Notizspeicher aktuell.

## 1.0.65 Beta - Checkbox Save/Dirty-State Hotfix

- Checkbox-Speichern setzt den Dirty-State nach erfolgreichem API-Save sofort und dauerhaft zurück.
- Rebuild/Restore nach dem Speichern kann den Dirty-State nicht mehr erneut aktivieren.
- Notizwechsel nach Speichern von Checkbox-Änderungen wird nicht mehr fälschlich blockiert.
- Checkboxen im Anzeige-Modus stehen nun sauber vor dem Text mit kleinem Abstand und ohne horizontale Verschiebung.
