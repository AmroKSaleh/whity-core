# @amroksaleh/features

## 0.2.0

### Minor Changes

- 783fba4: Add the offline-first sync contract at `@amroksaleh/features/sync`: the
  `SyncStatus` / `Conflict` / `FieldConflict` / `Resolution` / `SyncController`
  types, the `useSyncStatus` hook (over `useSyncExternalStore`, with the
  referential-stability contract documented), and `createAlwaysSyncedController`
  for online-only clients (web, the SPA harness) so shared sync UI degrades to a
  no-op without client special-casing. Additive — existing exports unchanged.
- 7c8dac5: Add the shared sync UI to `@amroksaleh/features/sync`: `UnsyncedBanner` (an
  app-wide status strip composing `Alert` that self-hides when fully synced) and
  `ConflictResolver` (a field-level mine/theirs/custom picker with a live merged
  preview, bidi-safe via `dir="auto"` on user content). Presentational, driven by
  the injected `SyncStatus` / `Conflict` — online-only clients render nothing.

### Patch Changes

- Updated dependencies [b8ed390]
  - @amroksaleh/ui@0.4.0

## 0.1.0

### Minor Changes

- Initial release: the multi-client feature-extraction pilot.
  - App-shell nav contract (`@amroksaleh/features/nav`): `NavConfig`/`NavItemConfig`/`NavGroupConfig` plain-data types, an injectable `NavLinkAdapter`, and `resolveNavGroups()` bridging a client-authored config into `AppSidebar`'s `AppSidebarNavGroup[]` shape (label translation via an optional injected `t()`, active-route matching against the caller's current path). Ships `exampleNavConfig` as a working reference.
  - `DemoCatalogList` / `DemoCatalogDetail` (`@amroksaleh/features/demo-catalog`): the pilot feature itself — a small, deliberately generic list/detail screen pair with zero Next.js dependency and zero direct data fetching. All data access goes through an injected `DemoCatalogAdapter` (`list`/`get`/`save`); `web/` wires it to the `DemoCatalog` plugin's REST API via its own api-client, `packages/spa-harness` wires it to an in-memory store — proving the same components render unmodified under both.
  - Zero-Next-deps guard added to CI (`.github/workflows/publish-features.yml`), mirroring `packages/ui`'s guard.
