# ADR 0002 — Family Relations Management System

- **Status:** Proposed
- **Date:** 2026-06-06
- **Tracking:** GitHub #65 (Tasker WC-65)
- **Deciders:** Project owner + maintainers

## Context

#65 asks for a "Family Relations Management System" — the ability to record and
manage familial relationships (Parent/Child/Spouse/Sibling) between people in a
tenant. The original triage sketch scoped it to `user_relations` between platform
users and flagged two open questions: **is this core or a plugin**, and **what is
the data model**. Both were resolved during brainstorming:

1. **Core, not a plugin.** Although a domain feature like this is a textbook
   plugin candidate for a white-label platform, building it as a plugin would
   require maturing the plugin *frontend* extensibility story (the #33 work, which
   is currently deprioritized). For this release-blocking feature we build it in
   core, mirroring the existing OUs/Roles handlers, and leave a clean future
   extraction to a plugin open.
2. **Genealogy-grade, not users-only.** Relations must be able to include people
   who do **not** have an account (e.g. a child, a deceased parent), not just
   existing platform users.
3. **Unified person-node graph.** Rather than polymorphic edges over two node
   types (users + persons), there is a **single** graph node type — `persons` —
   and a platform user participates by having an (auto-provisioned) person row
   linked via `persons.user_id`. Edges are always `person → person`. This keeps
   every query, tenant-isolation check, and UI render on one uniform code path
   and makes "a non-user relative later gets an account" a one-field update.

## Decision

Add a core **Family Relations** capability: a `persons` graph-node table (users
participate via an auto-provisioned linked person), a seeded relationship-type
vocabulary with inverses, a single-row-per-relationship edge table whose
reciprocal is derived at read time, a tenant-scoped + RBAC-gated API, and an
admin **Relations hub** that reuses the OU hub's UI architecture.

### Data model

Three tenant-scoped tables, each with a tested `down()`, numbered after the
current head migration `016_create_audit_log` (so `017`/`018`/`019`; the
implementer keeps the `MigrationSchemaTest` contiguous-prefix invariant and adds
its schema assertions):

**`017_create_persons`** — the one and only graph node:
```
id, tenant_id (NOT NULL, FK → tenants ON DELETE CASCADE),
display_name (NOT NULL),
user_id (NULLABLE, UNIQUE, FK → users ON DELETE SET NULL),  -- set when the person has a login
birth_date (NULLABLE), deceased (BOOL DEFAULT false), notes (NULLABLE),
created_at
```
Every human in a family graph is a `persons` row. A platform user's person row is
**auto-provisioned** on demand (first time the user is referenced by a relation
or their relations are listed) and is invisible to the operator.

**`018_create_relationship_types`** — the vocabulary, seeded:
```
id, name (UNIQUE), inverse_type_id (FK → relationship_types.id), symmetric (BOOL), created_at
seeds: Parent ↔ Child (directed inverses);  Spouse ↔ Spouse, Sibling ↔ Sibling (symmetric self-inverses)
```
Fixed seeded set for v1; the `inverse_type_id`/`symmetric` columns leave room for
tenant-custom types later without a schema change.

**`019_create_relations`** — the edges, plus the RBAC seed:
```
id, tenant_id (NOT NULL, FK → tenants ON DELETE CASCADE),
from_person_id (FK → persons ON DELETE CASCADE),
to_person_id   (FK → persons ON DELETE CASCADE),
relationship_type_id (FK → relationship_types.id),
created_at
UNIQUE (tenant_id, from_person_id, to_person_id, relationship_type_id)
```
This migration also seeds the `relations:read` and `relations:manage` permissions
(idempotent `ON CONFLICT`) and grants them to the seeded `admin` role, matching
the `audit:read` / `delegation:manage` pattern.

**One row per relationship; the reciprocal is derived at read time.** Adding
"Alice **Parent-of** Bob" writes a single edge. Listing **Bob's** relations flips
it through the type's `inverse_type_id` → "Child of Alice". Deletion is one row.
This avoids the dual-row drift that duplicated/derived state has repeatedly caused
in this codebase. Symmetric types (Spouse/Sibling) are their own inverse, so they
read correctly from either end.

### Integrity rules

- **No self-relation** (a person cannot relate to itself) → `SelfRelationException` (422).
- **No duplicate** (same tenant + pair + type) → `DuplicateRelationException` (422),
  also backed by the `UNIQUE` constraint.
- **Same-tenant only** — both persons must be in the acting tenant; a cross-tenant
  reference is treated as not-found (no cross-tenant disclosure).
- **Deferred to v2:** deep ancestor-cycle detection (A ancestor-of B *and* B
  ancestor-of A across a chain). v1 prevents the direct contradiction only; this
  limitation is called out, not silently skipped.

### API surface

New permissions `relations:read` / `relations:manage`. All routes tenant-scoped
and fail-closed; the system tenant (id 0) sees all, consistent with the other
admin handlers.

| Method + path | Permission | Purpose |
|---|---|---|
| `GET /api/relationship-types` | `relations:read` | Vocabulary for the UI picker |
| `GET /api/persons` | `relations:read` | List/search persons (non-user relatives + user shadows) |
| `POST /api/persons` | `relations:manage` | Create a non-user relative |
| `GET /api/persons/{id}` | `relations:read` | One person |
| `PATCH /api/persons/{id}` | `relations:manage` | Edit a non-user relative |
| `DELETE /api/persons/{id}` | `relations:manage` | Delete a **non-user** person (cascades edges); guarded — a person linked to a user cannot be deleted here |
| `GET /api/persons/{id}/relations` | `relations:read` | A node's relations (reciprocal-derived) |
| `GET /api/users/{id}/relations` | `relations:read` | Sugar: resolve user → person, return that node's relations |
| `POST /api/relations` | `relations:manage` | Create one edge |
| `DELETE /api/relations/{id}` | `relations:manage` | Remove an edge |

**Relating a user — polymorphism only at the boundary.** Edges store
`person → person`, but the UI must relate users too, so `POST /api/relations`
accepts references that can be a user or a person and resolves each to a person
node at the boundary (auto-provisioning the user's shadow person):
```
POST /api/relations
{ from: {kind:"user"|"person", id}, to: {kind:"user"|"person", id}, relationshipTypeId }
→ resolve both refs to person ids → validate (same tenant, both exist, no self,
  no duplicate, type exists) → insert ONE edge
```
Storage stays uniform; the only place that knows about user-vs-person is the
resolver.

### Component boundaries

| Unit | Responsibility | Interface |
|------|----------------|-----------|
| `PersonRepository` | All `persons` SQL; tenant-scoped; `(int)` casts for Postgres parity | repository methods |
| `RelationRepository` | All `relations` SQL; tenant-scoped; reciprocal/inverse-aware reads | repository methods |
| `RelationResolver` | Resolve `{kind,id}` refs → person ids (auto-provision user shadows); derive the reciprocal view via `inverse_type_id` | pure service over the repos |
| `PersonsApiHandler` | persons CRUD; no raw SQL (uses `PersonRepository`) | HTTP handler |
| `RelationsApiHandler` | relations + `users/{id}/relations` sugar; no raw SQL | HTTP handler |
| Typed exceptions | `SelfRelationException`, `DuplicateRelationException`, `CrossTenantReferenceException`, `PersonNotFoundException` → 4xx, never leaking internals | — |

No direct DB queries in handlers (per IS); FrankenPHP worker-safe (no
request-state in statics).

### UI — the Relations hub (reuses the OU hub architecture)

A `web/app/(protected)/admin/relations` hub that **reuses the OU hub's UI logic**:
the view-toggle + `localStorage` persistence pattern, the shared detail drawer
(`sheet.tsx`), the `DataTable`, the modal patterns, and the react-flow graph
component.

- **List | Graph toggle**, **List default** (per the chosen layout).
- **List view** — a persons `DataTable`: Name · Has account (solid vs "—" for
  non-user relatives) · # relations · search.
- **Graph view** — a react-flow family graph reusing the polished OU graph
  component (draggable nodes, **bezier** edges, type-labelled), with nodes marked
  account vs non-user. **Adaptation:** family relations are a *general* graph, not
  a single-parent hierarchy, so the auto-layout is a best-effort spread (not the
  OU tidy-tree) and relies on draggable repositioning; edges show the relationship
  type from the viewing node's perspective (derived reciprocal).
- **Detail drawer** — selecting a person (row or node) opens the shared drawer:
  person details + their relations list (add / remove) and the add-relation flow
  (pick target user/person + type).
- **Modals** — create/edit person; add-relation (choose from/to refs + type),
  reusing existing modal patterns.
- Sidebar nav entry gated on `relations:read`. Design tokens only (no raw
  hex/inline spacing).

## Alternatives considered

- **Build as a plugin** — deferred: cleaner for a white-label platform and would
  prove the plugin system, but needs the plugin-frontend maturation (#33) that is
  deprioritized; too much risk/time for a release-blocking item. Clean extraction
  remains possible later.
- **Polymorphic edges (users + persons as two node types)** — rejected: every
  read, isolation check, and UI render would branch on user-vs-person, and the
  "non-user later becomes a user" transition is awkward (edge migration / split
  identity).
- **Users-only relations** — rejected: cannot record relatives without accounts,
  which the product needs.
- **Persist both directions of each relationship** — rejected: trivially simpler
  queries but two rows to keep in sync (drift/bug surface); deriving the reciprocal
  from one row is the robust choice.

## Consequences

- **+** One coherent, tenant-isolated surface for family relations including
  non-user relatives; uniform person-node graph keeps queries/isolation/UI on a
  single code path.
- **+** Reuses the OU hub's proven UI (toggle, drawer, DataTable, react-flow), so
  the build is mostly composition, not new patterns.
- **+** New `relations:*` permissions follow the established RBAC pattern.
- **−** A platform user now has an invisible "shadow" person row (auto-provisioned).
- **−** Domain-specific "family" logic lives in generic core (accepted; future
  plugin extraction noted).
- **−** Three new tables + two handlers to maintain; family graph auto-layout is
  best-effort (draggable) rather than a tidy tree.

## Testing

- **Backend (real-engine SQLite, `PDO::ATTR_STRINGIFY_FETCHES` for Postgres parity):**
  - Reciprocal derivation: Parent-of reads as Child-of from the other end; Spouse/
    Sibling read symmetrically; type vocabulary + inverses correct.
  - Integrity: self-relation → 422; duplicate → 422 (+ UNIQUE); cross-tenant ref →
    not-found.
  - Auto-provision: a user with no person row gets one on first relation /
    `users/{id}/relations`; `user_id` stays unique.
  - Person delete guard: a user-linked person can't be deleted via the persons
    endpoint; a non-user person deletes and cascades its edges.
  - Tenant isolation: persons/relations seeded for tenant A and B; a tenant-A
    admin sees only A; system tenant sees all. Every new handler has an
    integration test covering RBAC route protection + tenant isolation.
- **Frontend (Playwright, against the live stack):** list renders + search;
  toggle to graph; create a non-user person; add a relation (user↔person and
  user↔user) and see it from both ends; remove a relation; delete guard surfaces
  for user-linked persons; lint/tsc/build clean.
- **OpenAPI** regenerated for the new routes; wiki updated (PERMISSION_SYSTEM +
  a new RELATIONS page).

## Out of scope (follow-ups)

- Deep ancestor-cycle detection across the relationship chain.
- Tenant-custom relationship types (schema already accommodates them).
- Extraction to a plugin once the plugin frontend story matures (#33).
- Richer genealogy fields / merge tooling beyond linking a person to a user.
