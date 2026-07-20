# @amroksaleh/ui

## 0.3.1

### Patch Changes

- `globals.css` now sources its theme tokens from the new `@amroksaleh/tokens` package instead of a hand-maintained, manually-synced block — no visible behavior change, but removes a duplication risk between this package and `web/app/globals.css`. Also adds a CI guard that fails the build if a Next.js-only import ever appears in this package (it must stay usable from a plain Vite/Tauri SPA).

## 0.3.0

### Minor Changes

- Add a shared `AccessDenied` component (a full-page permission-denied card: icon, title, description, optional action) — unifies markup that was previously hand-copied across every admin page gating a whole route on a permission.

## 0.2.0

### Minor Changes

- Add a real, interactive `DataTable` (and its `Table` primitives), built on `@tanstack/react-table`: multi-column sort, per-column and global filtering, client- or server-driven pagination, column visibility toggling, column resizing, and custom cell renderers. Replaces the three previously divergent, hand-rolled table implementations across the app.
