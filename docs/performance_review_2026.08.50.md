# Performance review 2026.08.50

Scope: all PHP services/controllers, `js/app.js`, templates and styles from the
`2.3.0-beta.10` release baseline. This sprint intentionally avoids broad
architectural changes.

## Findings

- `JoplixModelService` parses every sync item and builds search text in memory.
  This is the largest scaling cost for large Joplin stores. Incremental parsing
  keyed by file etag/mtime would help, but needs dedicated regression tests.
- Write endpoints rebuild the full index synchronously after most mutations.
  Moving all rebuilds to the existing background path would reduce response
  latency, but changes consistency timing and is deferred.
- Notebook note counts scan the note collection repeatedly. Precomputed counts
  would make tree building linear, but belong with the incremental-index work.
- Resource existence checks repeatedly resolve `.resource`. Caching the folder
  object per model instance is a safe future micro-optimization; it is not a
  meaningful bottleneck compared with parsing and indexing.
- Browser note caching, request de-duplication and background refresh are already
  bounded and appropriate. No unbounded polling or cache growth was found.
- CSS is large and historical but static; consolidating it would improve
  maintainability more than runtime performance and is therefore deferred.

## Change policy for this release

No performance refactor was included. The deletion correction and version
migration stay isolated, making the release suitable for focused sync testing.
