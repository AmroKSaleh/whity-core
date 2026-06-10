# Family Relations

Whity Core records and manages familial relationships (Parent/Child/Spouse/Sibling) between people in a tenant (WC-65), including relatives who do **not** have a platform account. It is a **core** feature (not a plugin), mirroring the OUs/Roles handlers, and is fully tenant-scoped and RBAC-gated. This page is grounded in the current source; the design rationale lives in [ADR 0002](../adr/0002-family-relations-management.md).

Related: [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [AUDIT_TRAIL](AUDIT_TRAIL.md) · [Architecture](Architecture.md).

## The unified person-node graph

There is **one** graph node type — `persons` — and edges are always `person → person`. A platform user participates by having an **auto-provisioned** person row linked via `persons.user_id` (nullable, UNIQUE, `ON DELETE SET NULL`); a relative without an account is simply a person with `user_id = NULL`. This keeps every query, tenant-isolation check, and UI render on one uniform code path, and makes "a non-user relative later gets an account" a one-field update.

The user's shadow person is created **on demand** — the first time the user is referenced by a relation or `GET /api/users/{id}/relations` — and is invisible to the operator.

## The pieces

| Component | Responsibility | File |
| --- | --- | --- |
| `persons` table | The one and only graph node. | `database/migrations/018_create_persons.php` |
| `relationship_types` table | Seeded vocabulary with inverses (`inverse_type_id`, `symmetric`). | `database/migrations/019_create_relationship_types.php` |
| `relations` table | The edges + the `relations:*` RBAC seed. | `database/migrations/020_create_relations.php` |
| `PersonRepository` | All `persons` SQL; tenant-scoped; `(int)` casts for Postgres parity. | `src/Core/Relations/PersonRepository.php` |
| `RelationRepository` | All `relations`/`relationship_types` SQL; reciprocal-aware reads. | `src/Core/Relations/RelationRepository.php` |
| `RelationResolver` | Resolve `{kind,id}` refs → person ids (auto-provision shadows) + integrity rules. | `src/Core/Relations/RelationResolver.php` |
| `PersonsApiHandler` | Persons CRUD + a node's relations; no raw SQL. | `src/Api/PersonsApiHandler.php` |
| `RelationsApiHandler` | Relation edges, the vocabulary, and `users/{id}/relations` sugar. | `src/Api/RelationsApiHandler.php` |
| `relations:read` / `relations:manage` | Gate the read / write surface. | `src/Core/RBAC/CorePermissions.php` |

## Schema

```sql
CREATE TABLE persons (
    id           SERIAL PRIMARY KEY,
    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    display_name VARCHAR(255) NOT NULL,
    user_id      INTEGER NULL UNIQUE REFERENCES users(id) ON DELETE SET NULL, -- shadow link
    birth_date   DATE NULL,
    deceased     BOOLEAN NOT NULL DEFAULT false,
    notes        TEXT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE relationship_types (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(64) NOT NULL UNIQUE,
    inverse_type_id INTEGER NULL REFERENCES relationship_types(id) ON DELETE SET NULL,
    symmetric       BOOLEAN NOT NULL DEFAULT false,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
-- Seeds: Parent ↔ Child (directed inverses); Spouse ↔ Spouse, Sibling ↔ Sibling (symmetric self-inverses)

CREATE TABLE relations (
    id                   SERIAL PRIMARY KEY,
    tenant_id            INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    from_person_id       INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
    to_person_id         INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
    relationship_type_id INTEGER NOT NULL REFERENCES relationship_types(id),
    created_at           TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, from_person_id, to_person_id, relationship_type_id)
);
```

## One row per relationship; reciprocal derived at read time

Adding "Alice **Parent-of** Bob" writes a **single** edge. Listing **Bob's** relations flips it through the type's `inverse_type_id` (Parent → Child) → "Child of Alice". Deletion is one row. Symmetric types (Spouse/Sibling) are their own inverse, so they read identically from either end. This avoids the dual-row drift that duplicated state has repeatedly caused in this codebase. The reciprocal flip lives in `RelationRepository::listForPerson()`.

## Integrity rules

Enforced in `RelationResolver`, surfaced as typed exceptions → safe 4xx (never leaking internals):

| Rule | Exception | HTTP |
| --- | --- | --- |
| No self-relation (a person cannot relate to itself) | `SelfRelationException` | 422 |
| No duplicate (same tenant + pair + type; UNIQUE backs it) | `DuplicateRelationException` | 422 |
| Same-tenant only (a cross-tenant reference is not disclosed) | `CrossTenantReferenceException` | 404 |
| Referenced person / user / type absent or not visible | `PersonNotFoundException` | 404 |

> Deep ancestor-cycle detection (A ancestor-of B *and* B ancestor-of A across a chain) is explicitly **v2** and not built; v1 prevents only the direct contradiction.

## API surface

All routes are tenant-scoped and fail-closed; the system tenant (id 0) sees all. Reads are gated on `relations:read`, writes on `relations:manage`.

| Method + path | Permission | Purpose |
| --- | --- | --- |
| `GET /api/relationship-types` | `relations:read` | The seeded vocabulary for the UI picker |
| `GET /api/persons` | `relations:read` | List/search persons (`?search=`), each with a relation count |
| `POST /api/persons` | `relations:manage` | Create a non-user relative |
| `GET /api/persons/{id}` | `relations:read` | One person |
| `PATCH /api/persons/{id}` | `relations:manage` | Edit a non-user relative (a user-linked shadow is 409) |
| `DELETE /api/persons/{id}` | `relations:manage` | Delete a non-user person (cascades edges); a user-linked person is 409 |
| `GET /api/persons/{id}/relations` | `relations:read` | A node's relations (reciprocal-derived) |
| `GET /api/relations` | `relations:read` | The tenant's stored edges (for the graph view) |
| `GET /api/users/{id}/relations` | `relations:read` | Sugar: resolve user → shadow person, return its relations |
| `POST /api/relations` | `relations:manage` | Create one edge |
| `DELETE /api/relations/{id}` | `relations:manage` | Remove an edge |

### Relating a user — polymorphism only at the boundary

Edges store `person → person`, but the UI must relate users too, so `POST /api/relations` accepts polymorphic references and resolves each to a person id at the boundary (auto-provisioning the user's shadow):

```
POST /api/relations
{ "from": {"kind":"user"|"person", "id": <id>},
  "to":   {"kind":"user"|"person", "id": <id>},
  "relationshipTypeId": <id> }
→ resolve both refs to person ids → validate (same tenant, both exist, no self,
  no duplicate, type exists) → insert ONE edge
```

Storage stays uniform; the only unit that knows about user-vs-person is `RelationResolver`.

## The Relations hub (admin UI)

`web/app/(protected)/admin/relations` reuses the OU hub's UI architecture:

- **List | Graph toggle** (List default), persisted in `localStorage` (`wc:relations:view`).
- **List view** — a persons `DataTable`: Name · Has account (vs `—` for non-user relatives) · # relations · client-side name search.
- **Graph view** — a react-flow family graph reusing the OU graph component (draggable nodes, **bezier** edges, type-labelled, nodes marked account vs non-user). Because a family is a *general* graph (not a single-parent hierarchy), the auto-layout is a best-effort circular spread (NOT the OU tidy-tree) and relies on dragging.
- **Detail drawer** — the shared `sheet.tsx` drawer: a person's details + their relations (add / remove) and the add-relation flow.
- Sidebar nav entry gated on `relations:read`.

## Testing

Real-engine (SQLite, `PDO::ATTR_STRINGIFY_FETCHES` on for Postgres parity):

- `tests/Core/Relations/RelationsRealEngineTest.php` — reciprocal derivation, integrity, auto-provision, delete cascade, tenant isolation.
- `tests/Api/RelationsApiHandlerRealEngineTest.php` — handler behaviour incl. the person delete guard and the typed-exception → 4xx mapping.
- `tests/Integration/RelationsApiRbacTest.php` — RBAC route protection (read vs manage) + tenant isolation through `RbacMiddleware` + `Router` + handlers.
- `tests/Database/MigrationSchemaTest.php` — schema assertions for `persons` / `relationship_types` / `relations` and the contiguous-prefix invariant.
