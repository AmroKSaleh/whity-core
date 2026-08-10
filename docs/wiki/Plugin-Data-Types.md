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

## The published entry round-trips your declaration

`GET /api/data-types` echoes back **every field you declared**, as the host
accepted it — labels, lifecycle, `blocks_delete` including `ignore_when`, and
the permission map. So you never have to read core's source to find out whether
a field took effect: diff the entry against what you wrote.

```jsonc
{
  "key": "acme:record",
  "source": "Acme",
  "label": { "en": "Record" },
  "lifecycle": {
    "declared": true, "column": "status",
    "states": ["draft", "active", "retired", "trashed"],
    "default_state": "active",
    "trashable": true, "retirable": true,
    "trashed_state": "trashed", "retired_state": "retired"
  },
  "blocks_delete": [
    {
      "table": "acme_entries",
      "column": "record_id",
      "label": "recorded entries",
      "ignore_when": { "status": ["trashed"] }   // echoed, so you can verify it
    }
  ],
  "actions": ["read", "trash", "restore", "retire", "delete"],
  "permissions": { "read": "acme:read", "…": "…" }
}
```

Two limits on that, both deliberate:

* it round-trips **what was accepted**, not the bytes you sent — guard values
  are normalised to strings, so `['archived' => [1]]` comes back as
  `{"archived": ["1"]}`. Seeing the normalisation is the point.
* `actions` is the one field filtered **per caller** (see below), so it is the
  intersection of what the type offers and what this caller may use — not a
  copy of your declaration.

No renderer needs `ignore_when`. It is published anyway: a filter that is
enforced but never echoed is indistinguishable from one that was silently
dropped, and telling those apart should not require a source dive.

## Honest degradation

An action exists only when **both** its lifecycle support and its permission
are declared. Omit `permissions['retire']` and there is no Retire affordance in
the generated contract *and* the retire endpoint refuses with `405`. The
alternative — rendering a control the endpoint would reject, or running an
ungated one — is worse than omitting it.

That answer is consistent per record too: a type that does not offer `delete`
reports `deletable: false` with `refusals.delete.reason = "delete_not_offered"`
(see [below](#why-an-action-is-unavailable)), never `true` for a call that would
come back `405`.

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

## Why an action is unavailable

`GET /api/data-types/{type}/{id}` reports the record's position **and the reason
behind every action its current state refuses**, so a generated screen can say
why a control is disabled instead of rendering a dead button:

```json
{
  "data": {
    "key": "acme:record",
    "state": "active",
    "referenceable": true,
    "pending_removal": false,
    "restorable": false,
    "deletable": false,
    "blockers": [],
    "refusals": {
      "restore": {
        "reason": "nothing_to_restore",
        "message": "This record is not in the trash, so there is nothing to restore"
      },
      "delete": {
        "reason": "trash_before_deleting",
        "message": "Move this record to the trash before deleting it"
      }
    }
  }
}
```

**`blockers` and `refusals` are different questions and stay apart.**
`blockers` answers only *how many rows point at this record* — the count a
"3 catalogue notes still reference this" message is built from. `refusals`
answers *which actions are unavailable on this record right now, and why*. A
refusal is not a reference, so it never appears as a synthetic blocker; if it
did, the row count would stop being answerable.

### The invariant, precisely

The payload holds two kinds of field, and only one of them is an action:

| | fields | rule |
|---|---|---|
| **Actions** | `restorable`, `deletable` | each is **exactly** `!refusals[action]` |
| **Properties** | `referenceable`, `pending_removal` | read off `state`; **never** carry a refusal |

**No action-shaped `false` is unexplained.** `restorable: false` always carries
`refusals.restore` and `deletable: false` always carries `refusals.delete` —
whatever the cause, and there are three:

* a **reference** — `still_referenced`, with `blockers` populated;
* the record's **state** — e.g. `trash_before_deleting`, with `blockers` empty;
* the type **not offering the action** — `delete_not_offered`, the same key the
  endpoint's `405` carries.

Both fields answer the same question the same way, so a renderer can disable a
control and say why with one expression rather than two special cases.

The properties are the deliberate exception, not an oversight. There is no
`referenceable` control to disable and nothing to refuse — `referenceable:
false` means the record is trashed or retired, and `state` is published right
beside it. A `refusals.referenceable` would be a refusal for an action that does
not exist.

### Reason keys

`reason` is the **contract**; `message` is core's own sentence, offered as a
**fallback**. Branch on `reason` and localise your own text — string-matching
prose is not an API, and the sentences may be reworded without notice.

| key | meaning |
|---|---|
| `still_referenced` | rows still point at this record; see `blockers` |
| `trash_before_deleting` | a trashable type has no live → gone path |
| `retired_records_are_permanent` | a retired record is never deletable |
| `retired_records_cannot_be_trashed` | retirement is not a mistake to undo |
| `retirement_is_permanent` | a retired record is never restorable |
| `restore_before_retiring` | restore a trashed record before retiring it |
| `nothing_to_restore` | the record is not in the trash |
| `trash_not_offered` · `restore_not_offered` · `retire_not_offered` · `delete_not_offered` | the type does not offer that action |

The state keys are produced by the **same evaluator** the endpoints enforce
with, and the `*_not_offered` keys are the same ones the `405` body carries, so
what a screen predicts and what a click gets cannot drift apart.

`refusals` covers all four mutating actions — `trash`, `restore`, `retire`,
`delete` — and an entry is present **only** when the action is unavailable now.
An idempotent no-op is not a refusal: retiring an already-retired record
succeeds, so it carries no entry. Restore is the one place where "already there"
*is* unavailability — a record that is not in the trash has nothing to restore,
which is what `restorable: false` has always meant; it now says so.

Nothing new is disclosed: every reason is derivable from `state` and the type's
published lifecycle and permissions, all of which the caller already reads. The
permission and ownership gates are untouched — a caller who may not read the
type gets `404`, and never learns the type exists.

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
