# Navigation Customization (design)

Status: **specified, not yet built.** Tasker tasks — backend `62cf12fb`,
frontend `e0075641`.

## How the sidebar works today

The sidebar is **entirely server-driven**. `GET /api/v1/navigation`
(`web/lib/navigation-context.tsx`) returns the **RBAC-permitted** set of nav
items for the current user; `web/components/sidebar.tsx` renders them grouped by
`group` + `order`. Core routes and plugins register items on the backend
(`src/Api/NavigationApiHandler.php`, `src/Core/PluginNavigationBridge.php`).

Consequence: a page appears in the nav **only if the backend registers a nav
item for it**. Newly added frontend pages (e.g. the Documents designer at
`/admin/documents`) have no nav item until registered — that registration
(with a `documents:read` gate) is part of the backend task.

## Goal: two customization layers, tenant-gated

- **Tenant default layout** — a tenant admin (permission `navigation:manage`)
  sets, for each available item, `{ visible, order, group }`, and defines/orders
  groups. Applies to everyone in the tenant.
- **Per-user overrides** — each user may further show/hide/reorder/regroup their
  own sidebar, **but only when** the tenant setting
  `navigation.allow_user_customization` is on.

### Principles
- **RBAC is the ceiling.** Customization can hide/reorder/regroup but can never
  reveal a link the user lacks permission for.
- **Resolution is server-side.** `GET /api/v1/navigation` returns the final
  effective list = `RBAC-permitted ∩ tenant layout ∩ user prefs` (precedence per
  item: `user ?? tenant ?? registry default`). The Sidebar component stays
  unchanged.

## Backend surface

- Tables (tenant-owned; register in `TenantOwnedTables`): `tenant_navigation_config`,
  `user_navigation_prefs`.
- Tenant setting `navigation.allow_user_customization` (per-tenant ?? global
  default).
- New permission `navigation:manage`.
- Endpoints (OpenAPI-first):
  - `GET /api/v1/navigation` — resolved effective list (existing; now layered).
  - `GET /api/v1/navigation/catalog` — full permitted set incl. hidden items +
    groups + the allow flag (for the editors).
  - `GET/PUT /api/v1/navigation/tenant-config` — gated by `navigation:manage`.
  - `GET/PUT /api/v1/navigation/user-prefs` — 403/no-op when the tenant setting
    is off.

## Frontend surface

- **Tenant-admin editor** (e.g. `/admin/settings/navigation`, gated by
  `navigation:manage`): list available links grouped, toggle visible, drag to
  reorder, assign/manage groups, and the "allow users to customize" switch.
- **Per-user personalization** (from account settings; shown only when allowed):
  the same interactions scoped to the user, plus "reset to tenant default".
- Both consume `/navigation/catalog` and write the configs; after save, call the
  navigation context `refresh()` so the sidebar updates without a reload. The
  Sidebar itself needs no change.
