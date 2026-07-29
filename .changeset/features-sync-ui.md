---
"@amroksaleh/features": minor
---

Add the shared sync UI to `@amroksaleh/features/sync`: `UnsyncedBanner` (an
app-wide status strip composing `Alert` that self-hides when fully synced) and
`ConflictResolver` (a field-level mine/theirs/custom picker with a live merged
preview, bidi-safe via `dir="auto"` on user content). Presentational, driven by
the injected `SyncStatus` / `Conflict` — online-only clients render nothing.
