# Joplix Project State 2026.08.57 Beta

Base: 2.3.0-beta.10.

## Current release

- Versioning follows `YYYY.MM.Sprint` starting with `2026.08.50`.
- The updater compares legacy and calendar versions numerically and preserves
  zero-padded calendar versions in its output.
- Deletion removes item files from the Joplin sync target after creating local
  Joplix backups. This is required for Joplin clients to observe remote deletes.
- Deleting a note also removes its `type_: 6` note-tag relation files.
- The PHP and browser sources were reviewed for performance. Larger index and
  model refactors are intentionally deferred until they can be regression-tested.

## Previous checkbox milestone

## Current goal

Checkboxes must render correctly, save reliably, synchronize through the established Joplin path and permit immediate note switching after save.

## Acceptance test

1. Open a note containing multiple Markdown checkboxes.
2. Select exactly one checkbox.
3. Click Save.
4. Immediately open another note without an unsaved-changes dialog.
5. Return to the first note.
6. Exactly the saved checkbox remains selected.
7. Sync Joplin on another device and verify the same state.

## Protected next step

Do not begin attachment editing until these acceptance tests pass.


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
