# Compatibility Addons Design

Joplix no longer performs repository compatibility checks at runtime. The former method is retained only as a design for optional Uplix addons.

## Joplin Compatibility Check

- Fetch the configured official Joplin repository and branch.
- Read the latest release and current branch head.
- Compare the last analyzed commit with the current head.
- Inspect release notes, commit messages and changed paths.
- Weight terms such as sync, resource, encryption, migration, schema, conflict, metadata, WebDAV, tag, notebook and note history.
- Give additional weight to changes under synchronizer, model, resource, note, folder, tag, revision and encryption paths.
- Analyze a selected Joplix source package and produce a report only; never modify or block Joplix.

## Nextcloud Compatibility Check

- Fetch the configured Nextcloud server repository and target release or branch.
- Inspect API, AppFramework, Files, routing, CSP, theming and app metadata changes relevant to Joplix.
- Analyze a selected Joplix source package and produce a report only.

Both addons should be stored in a Codeberg addon repository, enabled from Uplix Settings and executed manually from an Uplix Addons page.
