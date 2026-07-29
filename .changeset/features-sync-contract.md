---
"@amroksaleh/features": minor
---

Add the offline-first sync contract at `@amroksaleh/features/sync`: the
`SyncStatus` / `Conflict` / `FieldConflict` / `Resolution` / `SyncController`
types, the `useSyncStatus` hook (over `useSyncExternalStore`, with the
referential-stability contract documented), and `createAlwaysSyncedController`
for online-only clients (web, the SPA harness) so shared sync UI degrades to a
no-op without client special-casing. Additive — existing exports unchanged.
