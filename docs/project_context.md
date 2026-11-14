# Project Context 2.0.1

Joplix 2.0.2 is a clean version-line restart based on the functional 1.0.65 codebase. The patch is intentionally limited to browser-side checkbox rendering and save-state coordination. The established server-side Joplin synchronization implementation is not modified.: 2.3.0-beta.4

Required release files remain README.md, CHANGELOG.md, project_version.json, docs/project_state.md and docs/development_snapshot.md. The install directory remains protected by Uplix.


## 2.0.5 tag update
- Tags are shown optimistically immediately.
- One combined server request creates/reuses and assigns the tag.
- Joplin sync file formats are unchanged.


## Attachment editing milestone

- Office resources have Bearbeiten and Download actions.
- Editing uses a normal Nextcloud working copy in `/Joplix Editing`.
- Saved changes are imported into the existing Joplin resource with conflict checks.
- The Joplix browser tab must remain open during the first beta implementation.


## Office opening mode (2.1.3)
- Per-user choice: dedicated window or new browser tab.
- Dedicated window is the default.
- Closing the Office window triggers a final write-back status check.


## Attachment resource manager (2.2.0-beta.1)
- Office files: Edit and Download.
- PDF and images: Open and Download.
- Audio and video: Play and Download.
- Archives and unknown files: Download.
- Per-user hidden workspace: `.joplix/editing`, `.joplix/backups`, `.joplix/cache`, `.joplix/logs`.
- Editing sessions older than 24 hours are removed automatically.
- App backups are optional and disabled by default.
- Joplin resource IDs and sync storage remain unchanged.


## Compatibility checks

The legacy built-in Joplin repository compatibility monitor was removed in 2.3.0-beta.6. Compatibility analysis is planned as an optional external Uplix addon so it cannot block normal Joplix operation.
