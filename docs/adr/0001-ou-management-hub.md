# ADR 0001 — Organizational Units Management Hub

- **Status:** Accepted
- **Date:** 2026-06-06
- **Tracking:** GitHub #44 (Tasker WC-44)
- **Deciders:** Project owner + maintainers

## Context

The Organizational Units (OU) backend is complete: `organizational_units` has a
`parent_id` hierarchy and `tenant_id`; `OusApiHandler` provides CRUD plus
`assignRole`/`removeRole`; OU-assigned roles now genuinely grant access via the
role/OU inheritance resolved in `RoleChecker` (ADR-adjacent work, GitHub #54).

The admin UI, however, is a **flat `DataTable`** (`web/app/(protected)/admin/ous/page.tsx`)
showing `name`/`slug`/`description`/`parent_id`. It does not visualize the
hierarchy, and there is **no UI at all** for assigning roles to an OU or seeing
which users belong to one (those capabilities are API-only or implicit). #44 asks
for "customizable hierarchy" — interpreted here as a real management surface.

## Decision

Replace the flat table with an **OU Management Hub**: two switchable views of the
hierarchy plus a shared detail drawer for managing the selected OU's structure,
roles, and members.

### Views (toggle, choice persisted per user)

The page fetches `GET /api/ous` once (a **flat list** of
`{id, parent_id, name, slug, description, created_at}`) and builds the tree
client-side from `parent_id`. A `Tree | Graph` toggle (persisted in
`localStorage`) renders the same tree data through one of:

- **`OuTree`** — indented, collapsible, file-explorer-style tree. Hand-built with
  the installed shadcn/Radix/Tailwind/`@tabler/icons-react`. **No new dependency.**
  Expand/collapse, select, and a per-node action menu. This is the **default**
  view and is fully keyboard-accessible.
- **`OuGraph`** — a top-down node graph rendered with **`@xyflow/react`**
  (react-flow). **Dynamically imported** so it stays out of the main bundle and
  does not run during SSR. Nodes are **select-only** (no drag-to-reparent);
  selecting a node opens the drawer, and actions are reached via a node menu.
  Node positions come from a **hand-rolled layered layout** computed from each
  node's depth (breadth within a depth spread evenly) — no graph-layout
  dependency. If hand-rolled layout proves inadequate for real hierarchies,
  adding `@dagrejs/dagre` is the documented fallback (revisit, do not pre-add).

### Detail drawer (the "hub")

Selecting an OU in either view opens a shared shadcn drawer/sheet, `OuDetailDrawer`:

- **Details** — `name`/`slug`/`description`/parent, with actions: create child,
  edit, **move** (see below), delete (delete keeps the existing guard: blocked
  when the OU has child OUs or assigned users).
- **Roles** — lists the roles assigned to the OU; add via a role picker
  (`assignRole`), remove (`removeRole`).
- **Members** — lists the users whose `ou_id` is this OU. **Read-only in v1**;
  assigning a user to an OU remains part of the user-management flow.

### Re-parenting

Done via a **"Move to parent" picker** in the edit dialog (a dropdown of eligible
parents). The picker **excludes the OU itself and all of its descendants** so a
cycle cannot be selected. The backend **independently rejects cycles** (defense in
depth — see backend changes).

### Backend changes

1. **`GET /api/ous/{id}/roles`** *(new)* — roles assigned to an OU, joining
   `ou_role_assignments` → `roles`, tenant-scoped (system tenant 0 sees all);
   returns `{id, name, description}` rows.
2. **`GET /api/ous/{id}/members`** *(new)* — users with `ou_id = {id}`,
   tenant-scoped, returned in the public user shape (`toPublicUser`; never the
   password hash).
3. **Cycle prevention in `OusApiHandler::update()`** *(fix)* — when `parent_id`
   changes, reject setting it to the OU itself or any descendant (walk the
   proposed parent's ancestor chain; if the OU appears, reject with a typed
   domain error → 4xx). Currently only parent existence + tenant are validated.
4. **Dependency:** add **`@xyflow/react`** (react-flow) to `web/package.json` —
   **MIT** licensed (no GPL conflict), widely used and audited, tree-shakeable;
   used only by the lazily-imported graph view. No other new dependency
   (no `dnd-kit`, no `dagre` in v1). This is the dependency-policy sign-off.

### Routes

`GET /api/ous/{id}/roles` and `GET /api/ous/{id}/members` are registered in
`public/index.php` next to the existing `/api/ous` routes (additive; the WC-4
worker/route wiring is not disturbed). Both require the same admin protection as
the other OU routes.

## Component boundaries

| Unit | Responsibility | Interface |
|------|----------------|-----------|
| `ous/page.tsx` | Orchestration: fetch flat OUs, build tree, hold view toggle + selected id, render the active view + drawer | — |
| `OuTree` | Render an indented tree; emit select + per-node action events | props: `tree`, `selectedId`, `on*` callbacks |
| `OuGraph` | Render a react-flow layered graph; emit select + action events | same prop shape as `OuTree` (interchangeable) |
| `OuDetailDrawer` | Show + manage the selected OU (details, roles add/remove, members view); owns its own roles/members fetch | props: `ouId`, `onChanged` |
| Create/Edit/Delete modals | Existing modals; Edit gains the Move-to picker | existing + `parentOptions` |

`OuTree` and `OuGraph` are interchangeable dumb renderers (same props), so the
toggle just swaps the component. The drawer is the only unit that fetches
per-OU detail. The page owns no OU business logic beyond tree-building.

## Alternatives considered

- **Visualization-only / keep CRUD in modals** — rejected: adds little over the
  current table; #44 wants real management.
- **Org-chart graph as the only view** — rejected: a graph alone is hard to make
  accessible and awkward for deep trees; the indented tree is the accessible
  default, with the graph as an opt-in visual.
- **Drag-to-reparent on the canvas** — deferred: needs a drag lib + live
  cycle-guarding + extra a11y work; the Move-to picker covers the need now.
- **`dagre`/elk for graph layout** — deferred: a hand-rolled depth-layered layout
  avoids a second dependency for v1.
- **Manage members (set `ou_id`) from the hub** — deferred to a follow-up; v1
  lists members read-only.

## Consequences

- **+** One coherent surface for OU structure, roles, and members; the hierarchy
  is finally visible; OU role assignment gets its first UI (and it actually grants
  access since #54).
- **+** The accessible tree is the dependency-free default; the graph is additive
  and lazy-loaded, so the bundle/SSR cost is opt-in.
- **+** Cycle prevention closes a real backend correctness gap.
- **−** Adds `@xyflow/react` (accepted, MIT). Two render paths (tree + graph) to
  keep in sync behind one prop contract.
- **−** Two new read endpoints to maintain.

## Testing

- **Backend (real-engine SQLite, per the project's mocked-PDO lesson):**
  - `GET /api/ous/{id}/roles` returns exactly the OU's assigned roles; tenant-scoped.
  - `GET /api/ous/{id}/members` returns exactly the OU's users (no password); tenant-scoped.
  - `update()` re-parent: moving an OU under its own descendant is rejected (4xx,
    no row change); a valid move succeeds; cross-tenant parent rejected.
- **Frontend (Playwright, against the live stack):**
  - Tree renders the seeded hierarchy; expand/collapse; create child under a node;
    rename; delete (guard surfaces for non-empty OUs); move via picker (and the
    picker omits self + descendants).
  - Graph view renders nodes for the hierarchy; selecting a node opens the drawer.
  - Drawer: assign a role to the OU and see it listed; remove it; members list shows.
  - Lint/tsc/build clean; react-flow lazy import verified not to break build/SSR.

## Out of scope (follow-ups)

- Drag-to-reparent on the graph; `dagre` auto-layout.
- Managing OU membership (assigning users to OUs) from the hub.
- A `users.name` column (display names) — tracked separately.
