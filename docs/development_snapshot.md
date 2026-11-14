# Development Snapshot 2026.08.57 Beta

## Release focus

- Calendar-version migration from `2.3.0-beta.10` to `2026.08.50`.
- Integrated updates now call Nextcloud's app-specific upgrade lifecycle and verify the persisted Joplix version before returning success.
- Backward-compatible updater parsing and numeric ordering.
- Joplin-compatible remote deletion by deleting sync-target item files rather
  than writing `deleted_time` into serialized remote items.
- Conservative performance review without a broad refactor.

## Previous focus

Clean restart for the checkbox workflow.

## Implemented

- Dedicated task-list container without default UL indentation.
- Grid-based checkbox/text alignment.
- Shared save promise prevents note switching from racing an active save.
- Saved note body and original snapshot are updated immediately after API success.
- Existing-note index refresh is scheduled in the background.

## Sync safety

No changes were made to JoplixWriteService or the Joplin file format. Existing metadata preservation, known_updated_time conflict handling, backups and locking remain unchanged.

## Next planned topic

Editable attachments such as spreadsheet and office files after checkbox acceptance tests pass.


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
