# Plugin Data Types — lifecycle and referential guards

*(WC-723, "Door 2" — `registerDataType`. Plugin SDK 1.20.)*

Every plugin that manages records re-implements the same admin surface: a list,
a create/edit form, a delete path, and — if it is careful — a soft-delete state
with a way back. Core already generates part of that from a plugin's OpenAPI
declarations. What it could not generate is anything that depends on knowing
**what a record's states mean** and **what still points at it**.

A data-type declaration supplies exactly those two facts, as data. Core never
learns what any of it means; it learns that `acme_entries` references
`acme_records.id`, and that a blocked delete should say "recorded entries".
That is enough to enforce the guard, phrase the refusal, and generate the
trash / restore / retire affordances.

The plugin keeps its storage, its own routes and its own screens. This raises
the floor; it does not cap the ceiling.

---

## Two end-states, not one

The distinction plugins most often lose:

| | **trashed** | **retired** |
|---|---|---|
| What it means | "this should not exist" — a mistake | "this served its purpose" |
| Reversible? | **yes**, restore returns it to the default state | **never** |
| Accepts new references? | no | no |
| Pending removal? | **yes** | no |
| Can be deleted for real? | yes, once nothing references it | **never**, even when unreferenced |

Retirement removes the *future*, not the past: rows that already reference the
record still resolve against it, which is exactly why it must persist. The two
are independent axes — a type may be retirable without being trashable, and
vice versa — so collapsing them into one "soft delete" flag loses the property
that matters most.

Core enforces the distinction with four refusals and one bypass-closer:

```
live ──trash──▶ trashed ──restore──▶ live
                   │
                   └──delete──▶ gone     (only when no guard blocks)

live ──retire──▶ retired ──▶ (nothing)
```

* a retired record cannot be restored (`retirement_is_permanent`)
* a retired record cannot be deleted (`retired_records_are_permanent`)
* a retired record cannot be trashed (`retired_records_cannot_be_trashed`)
* a trashed record cannot be retired — restore it first (`restore_before_retiring`)
* when a type is trashable, delete is legal **only** from the trashed state
  (`trash_before_deleting`), so no delete path — including a plugin's own
  empty-trash sweep — can skip the reversible step.

---

## Declaring tables (required first step)

Ownership is what earns the right to declare a guard. Implement
`Whity\Sdk\Tenant\PluginTablesInterface`:

```php
public function getOwnedTables(): array
{
    return [
        'acme_records' => self::SCOPE_TENANT,
        'acme_entries' => self::SCOPE_TENANT,
        'acme_counter' => self::SCOPE_GLOBAL,   // no tenant_id column
    ];
}
```

The plugin declares **which** tables; the host stamps **who** owns them, from
`$plugin->getName()`. A plugin may name any table, but it cannot name itself
something else. Core claims every table its own migrations create before any
plugin loads, and a table another source already owns is refused — together
with the rest of that declaration, so ownership never depends on iteration
order.

`SCOPE_GLOBAL` is an honest statement about a table's shape, not a request for
an exemption: core will not build a data type or a guard over a table with no
tenant column, because no tenant predicate could be bound to it.

## Declaring a data type

Implement `Whity\Sdk\DataType\PluginDataTypesInterface`:

```php
public function getDataTypes(): array
{
    return [
        'record' => [                                // BARE slug → `acme:record`
            'table'         => 'acme_records',
            'key'           => 'id',                 // default: 'id'
            'tenant_column' => 'tenant_id',          // default: 'tenant_id'
            'label'         => ['en' => 'Record', 'ar' => 'سجل'],
            'lifecycle'     => [
                'column'        => 'status',
                'states'        => ['draft', 'active', 'retired', 'trashed'],
                'default_state' => 'active',
                'trashable'     => true,
                'retirable'     => true,
                'trashed_state' => 'trashed',        // default when trashable
                'retired_state' => 'retired',        // default when retirable
            ],
            'blocks_delete' => [
                [
                    'table'  => 'acme_entries',
                    'column' => 'record_id',
                    'label'  => 'recorded entries',  // what the refusal says
                    // referencing rows in these states do not block
                    'ignore_when' => ['status' => ['trashed']],
                ],
            ],
            'permissions' => [
                'read'    => 'acme:read',
                'trash'   => 'acme:manage',
                'restore' => 'acme:manage',
                'retire'  => 'acme:retire',
                'delete'  => 'acme:manage',
            ],
        ],
    ];
}
```

Keys are namespaced under the plugin name, so two plugins may both declare
`record` and neither can shadow a core type. The namespace is the same one
[resource-scoped role grants](PERMISSION_SYSTEM.md) use, deliberately:
`acme:record` means one thing across the install.

Every table named — the type's own and every `blocks_delete` table — must be
one this plugin declared above, and must be tenant-scoped. A guard is an
aggregate over the referencing table, so a guard over somebody else's table
would be a way to count rows the plugin cannot otherwise read.

`ignore_when` matters more than it looks: without it, a referencing row that is
itself trashed would pin its parent alive forever, and the guard would be a
leak rather than a protection.

## Honest degradation

An action exists only when **both** its lifecycle support and its permission
are declared. Omit `permissions['retire']` and there is no Retire affordance in
the generated contract *and* the retire endpoint refuses with `405`. The
alternative — rendering a control the endpoint would reject, or running an
ungated one — is worse than omitting it.

An invalid declaration is a logged warning against the plugin, never a dead
host, and rejection is per data type: one malformed entry does not discard the
others.

---

## Generated endpoints

| Method & path | Purpose |
|---|---|
| `GET /api/data-types` | Declared types, filtered per caller, with the actions that caller may use |
| `GET /api/data-types/{type}/{id}` | One record's state plus the references blocking its deletion |
| `POST /api/data-types/{type}/{id}/trash` | Trash |
| `POST /api/data-types/{type}/{id}/restore` | Restore |
| `POST /api/data-types/{type}/{id}/retire` | Retire |
| `DELETE /api/data-types/{type}/{id}` | Delete for real, if every guard permits |

Permissions vary per type, so these routes carry no route-level
`requiredPermission`; the handler resolves the type's declared permission
through the same `RoleChecker` the RBAC middleware uses. Every statement binds
a tenant predicate — a record in another tenant is reported absent, never
forbidden.

A refused delete answers `409` with the blockers attached:

```json
{
  "error": "Still referenced by 3 catalogue notes",
  "details": {
    "reason": "still_referenced",
    "state": "trashed",
    "blockers": [
      { "table": "acme_entries", "label": "catalogue notes", "count": 3 }
    ]
  }
}
```

## Keeping your own delete route

The escape hatch stays open, but enforce through the **same** evaluator — two
enforcement paths that can disagree are worse than one:

```php
$guard = \Whity\app(\Whity\Sdk\DataType\DataTypeGuard::class);

if ($guard->blockingReferences('acme:record', $tenantId, $id) !== []) {
    return Response::error('Still referenced', 409);
}
if (!$guard->isReferenceable('acme:record', $tenantId, $id)) {
    return Response::error('This record no longer accepts new references', 409);
}
```

`DataTypeGuard` is read-only: it answers questions and changes nothing.
`isReferenceable()` is the one a picker or a foreign-key validation should
consult — it is false for both trashed and retired records, which is the single
axis on which the two states agree.

## Reference implementation

`plugins/DemoCatalog` declares `democatalog:item` over `demo_catalog_items`,
guarded by `demo_catalog_item_notes.item_id`. See also
[Plugin-Development.md](Plugin-Development.md) and
[TENANT_ISOLATION.md](TENANT_ISOLATION.md).
