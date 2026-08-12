# Plugin Data Types — lifecycle and referential guards

*(WC-723, "Door 2" — `registerDataType`. Plugin SDK 1.20; the lifecycle
**write** contract, `DataTypeLifecycle`, since SDK 1.24.)*

Every plugin that manages records re-implements the same admin surface: a list,
a create/edit form, a delete path, and — if it is careful — a soft-delete state
with a way back. Core already generates part of that from a plugin's OpenAPI
declarations. What it could not generate is anything that depends on knowing
**what a record's states mean** and **what still points at it**.

A data-type declaration supplies exactly those facts, as data. Core never
learns what any of it means; it learns that `acme_entries` references
`acme_records.id` and that a blocked delete should say "recorded entries", and
that `acme_lines` rows are *part of* a record and go when it goes. That is
enough to enforce the guard, phrase the refusal, delete the composition, and
generate the trash / restore / retire affordances.

The plugin keeps its storage, its own routes and its own screens. This raises
the floor; it does not cap the ceiling.

---

## Two end-states, not one

The distinction plugins most often lose:

| | **trashed** | **retired** |
|---|---|---|
| What it means | "this should not exist" — a mistake | "this served its purpose" |
| Reversible? | **yes**, restore returns it to the state it held | **never** |
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

### A restore is an undo, so `default_state` is a fallback

`restore` returns a record to **the state it actually held** when it was
trashed. A record trashed while `approved` comes back `approved`.

This is worth stating plainly because it did not always hold: `restore` used to
write `default_state` unconditionally, so a trashed `approved` record came back
`draft` and re-entered circulation looking unreviewed — reported by a `200`
identical to a correct undo. If you read the old behaviour into your own screens
(for example by assuming a restored record is always ready for review again),
re-check that assumption.

Nothing is required of your plugin for this. Core keeps the prior state in its
own table, keyed by `(tenant, data type, record id)`; your schema is untouched
and there is no migration to ship. Core removes the row when a restore has spent
it and when the record is hard-deleted **through core** — it can carry no
foreign key to your table (the target varies by type), so no cascade will ever
do it. If you keep your own delete route, delete through
`DELETE /api/data-types/{type}/{id}` or the SDK guard's evaluator where you can;
otherwise a primary key that is later re-used may carry a stale memory until its
next `trash` overwrites it.

`default_state` is now what a restore falls back to, in two cases:

* **nothing was remembered** — every record already sitting in the trash when
  this shipped, and any record whose `status` was set to your trashed state by
  something other than the `trash` endpoint. Those restore to `default_state`,
  exactly as before;
* **the remembered state is no longer usable** — you changed `states` between
  the trash and the restore, or repurposed that state as your `trashed_state` or
  `retired_state`. Writing it back would put the row outside its own vocabulary,
  or walk it into retirement through the restore endpoint, so core uses
  `default_state` instead.

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
            'cascade_delete' => [                    // rows that DIE WITH the record
                [
                    'table'  => 'acme_lines',
                    'column' => 'record_id',
                    'label'  => 'line items',        // what the preview calls them
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

Every table named — the type's own, every `blocks_delete` table and every
`cascade_delete` table — must be one this plugin declared above, and must be
tenant-scoped. A guard is an aggregate over the referencing table, so a guard
over somebody else's table would be a way to count rows the plugin cannot
otherwise read. A cascade is a `DELETE` over it, which is worse, and passes the
same gate for that reason.

`ignore_when` matters more than it looks: without it, a referencing row that is
itself trashed would pin its parent alive forever, and the guard would be a
leak rather than a protection.

---

## What outlives a record, and what dies with it

`blocks_delete` and `cascade_delete` answer the **same question with opposite
answers**, and you need both:

| | `blocks_delete` | `cascade_delete` |
|---|---|---|
| What those rows are | somebody's data that points here | **part of** this record |
| On delete | the delete is **refused** | the rows are **deleted with it** |
| `ignore_when` | supported | **refused** — see below |
| Missing declaration means | nothing refuses the delete | **the rows are orphaned** |

Nothing about a table's shape says which one you mean. In the example above
`acme_entries` and `acme_lines` are shaped identically — a tenant column, a
`record_id`, a payload — and must be handled in opposite ways. With no foreign
keys between plugin tables (the convention here) the database will not choose
either, which is why both halves are declared.

### The gap this closed

Until this shipped, `DataTypeLifecycleService::delete()` ran exactly one
statement:

```sql
DELETE FROM <table> WHERE <key> = :id AND <tenant> = :tenant
```

One row. An adopter proved live that deleting a record through
`DELETE /api/data-types/{type}/{id}` left its own child rows behind — pointing
at an id that no longer resolves, in a state no screen lists and no guard
protects. It answered `200`. `blocks_delete` could not have caught it, because
it declares the opposite relationship.

If you worked around this in a `changing` listener, that still works and nothing
forces you off it — but you can now declare the composition and delete the
listener, and core will do it inside the transition's own transaction.

### `ignore_when` is refused on a composition

A guard legitimately disregards some referencing rows. A cascade that
disregarded some would leave exactly the orphans it exists to remove, so the
field is **rejected at registration** rather than accepted and ignored. If some
of those rows must survive the delete, they are a reference and belong in
`blocks_delete`.

For the same reason a table may not appear in **both** lists, and a type may not
cascade onto its **own** table.

### What core refuses rather than cascading

Three conditions are checked **before anything is written**, and each refuses
instead of doing a partial job. All three are ordinary `409` refusals with
stable reason keys, and all three are predicted by the pre-flight endpoint.

* **`cascade_would_nest`** — a table you own is *itself* a declared type that
  declares its own `cascade_delete`. **Nesting is not supported in this pass.**
  Core deletes one level, so the level below would be orphaned: the identical
  bug, one step further down and harder to notice. Rather than silently doing
  one level, core refuses the delete.

  This is refused at **delete time**, not at registration, and deliberately: it
  is a fact about *two* declarations, and the second may not exist yet when the
  first registers — types arrive from different plugins in load order. Refusing
  at registration would reject whichever declaration happened to arrive second,
  so which plugin lost its whole lifecycle surface would depend on load order.
  By delete time the catalogue is complete, so the answer is deterministic for
  the install and derived purely from declarations — which is why the preview
  can predict it exactly.

* **`composition_is_permanent`** — a row you own is **retired** under its own
  declared type. "A retired record is never deleted" is the strongest promise
  this lifecycle makes; a cascade that quietly removed one would make it
  conditional on nobody having declared a composition over its table.

* **`composition_still_referenced`** — a row you own is protected by its own
  type's `blocks_delete`. Deleting it through the parent would defeat a declared
  guard by approaching it from above, which is the "bypassed through a secondary
  path" failure declared guards exist to end. The refusal carries `blockers`
  under the usual shape, with the declaring plugin's label.

  It is a **separate key** from `still_referenced`, because the two send you to
  different places: one means "detach what points at this record", the other
  "something points at one of its parts". A caller shown the first for the
  second goes looking for references that do not exist.

Only rows that are themselves a **declared data type** can produce the last two.
That is not a gap: both promises being protected are properties a table only has
by being declared.

### Ordering, atomicity and tenant scoping

* **Children first, then the parent**, both inside the transition's own unit of
  work — the same one the vetoable `changing` hook is dispatched in. A veto, or
  a failure part-way through the cascade, leaves the whole composition exactly
  where it was rather than half-removed. When you call a lifecycle method inside
  a transaction **you** opened, core joins it rather than nesting, so your
  rollback takes the cascade with it.
* **Every cascade statement binds `tenant_id`**, on the owned table and on both
  halves of the guard check. A cascade is the most destructive statement core
  generates; an unscoped one would delete another tenant's rows for a record it
  cannot even see.
* **Composition affects `delete` only.** Trashing or retiring a record writes
  nothing to its parts — composition is about a record's existence, not its
  state. If you want children to follow their parent into the trash, that is a
  domain rule, and the [`changing` hook](#refusing-a-transition-datatypelifecyclechanging)
  is where it belongs.
* A cascade announces the **parent's** transition only. No `changed` event and
  no separate audit entry is emitted per owned row; the parent's audit entry
  records what went with it (`metadata.cascaded`), which is the only place that
  count survives the write.

### CI catches the edge you forgot to declare

Both lists are only as good as your memory of writing them. `blocks_delete` and
`cascade_delete` are opposite answers to what happens to a record's children —
but an edge in **neither** list is worse than either: core will not refuse the
delete and will not clean up after it, so the rows are simply left pointing at
an id that no longer resolves, in a state no screen lists and no guard protects.
The delete answers `200`.

`scripts/ci-undeclared-reference-guard.php` fails the build on exactly that
case:

> flag a column named `<something>_id` when it points at a table that actually
> exists, carries **no** `FOREIGN KEY`, and appears in **neither**
> `blocks_delete` nor `cascade_delete`.

Run it over your own plugin the same way you run the tenant conformance kit:

```
php scripts/ci-undeclared-reference-guard.php path/to/YourPlugin
```

**It does not ask you to add foreign keys.** No FKs between plugin tables is the
convention here — it is why these lists exist at all — and a schema with none at
all passes completely, provided its relationships are declared. A column that
names no known table (`stripe_customer_id`, `external_ref_id`) is not treated as
a reference. `tenant_id` is never flagged: that is a different invariant, with
[its own two linters](./TENANT_ISOLATION.md).

Fix a finding in whichever way is **true** — `blocks_delete` if the rows must
outlive the parent, `cascade_delete` if they are part of it, a `FOREIGN KEY` if
the engine should enforce it. If it genuinely is not a reference, annotate the
column inside the `CREATE TABLE`:

```sql
CREATE TABLE acme_import_staging (
    -- @reference-lint-ignore: raw import rows; ids are unresolved until the importer runs
    acme_record_id INTEGER,
    ...
)
```

The reason is **required** — a bare tag silences nothing. Use
`-- @reference-lint-ignore-table: <reason>` to exempt a whole table.

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
  "cascade_delete": [                            // [] when none is declared
    { "table": "acme_lines", "column": "record_id", "label": "line items" }
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

Every refusal these endpoints answer — including the authorization ones —
carries a `details.reason` key. A `404` for an unknown-or-unreadable type is
`unknown_data_type`, a `405` is `<action>_not_offered`, and a `403` is
`insufficient_permissions` (with `details.required` naming the missing slug).
Branch on `reason`, not on the status code.

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
    "cascade": [
      { "table": "acme_lines", "label": "line items", "count": 4 }
    ],
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

**`blockers`, `cascade` and `refusals` are three different questions and stay
apart.**

* **`blockers`** — *what is in the way of deleting this, and what to call it*:
  the count a "3 catalogue notes still reference this" message is built from.
  Those rows point either at the record or at something it owns; `reason` says
  which (`still_referenced` vs `composition_still_referenced`).
* **`cascade`** — *what else this delete would remove*: the rows the record
  owns, with the declaring plugin's label and a count. A record with composition
  is still **deletable**; this is the field a confirmation dialog is built from
  ("this will also delete 4 line items"). Edges with no rows are omitted, so
  `[]` means "nothing else goes".
* **`refusals`** — *which actions are unavailable on this record right now, and
  why*.

A refusal is not a reference, so it never appears as a synthetic blocker; if it
did, the row count would stop being answerable. And a composition is neither: it
does not stop the delete, so folding it into `blockers` would make
`deletable: true` sit beside a non-empty blocker list.

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
| `composition_still_referenced` | rows still point at something this record **owns**; see `blockers` |
| `composition_is_permanent` | a row this record owns is retired, and retirement is permanent |
| `cascade_would_nest` | a table this record owns declares a composition of its own; core does not cascade more than one level |
| `trash_before_deleting` | a trashable type has no live → gone path |
| `retired_records_are_permanent` | a retired record is never deletable |
| `retired_records_cannot_be_trashed` | retirement is not a mistake to undo |
| `retirement_is_permanent` | a retired record is never restorable |
| `restore_before_retiring` | restore a trashed record before retiring it |
| `nothing_to_restore` | the record is not in the trash |
| `blocked_by_plugin` | a plugin refused the transition; the sentence is in `message` — **never predicted here**, see [below](#the-one-thing-this-preview-cannot-predict) |
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

### The one thing this preview cannot predict

> **Read this before relying on "preview and enforcement agree".** That property
> still holds for core's rules — completely and exactly. It does **not** extend
> to a plugin veto, and the difference is not academic: an action published here
> as available can still answer `409 blocked_by_plugin` when it is attempted.

`GET /api/data-types/{type}/{id}` derives every refusal from facts core owns —
the declaration, the record's state, the declared reference counts. It cannot
derive a [veto](#refusing-a-transition-datatypelifecyclechanging), because a
veto is a plugin's domain judgement made at the moment of the attempt.

**It also deliberately does not dispatch the hook to find out.** Doing so would
mean a `GET` executing arbitrary plugin listeners: surprising (nothing about a
preview suggests it), potentially side-effecting (a `changing` listener is
written expecting a mutation is underway, and nothing forbids it from writing,
enqueuing or calling out), and doubling every listener's invocations for a
screen that merely rendered. It would also expire instantly — a "no veto"
prediction is only true until the next write anywhere in the system.

So state the guarantee precisely:

* the preview predicts **core's** rules — complete, exact, stable keys;
* a **plugin veto is discoverable only by attempting the transition**.

For a client this means: keep rendering controls from `refusals`, and stay able
to surface a `409` from an action you had shown as available. Swallowing that
`409`, or treating it as an unexpected error, is the failure this note exists to
prevent.

---

## Refusing a transition (`datatype.lifecycle.changing`)

A lifecycle transition announces itself **twice**, and the difference between
the two is the difference between being told and being asked:

| event | when | can it stop the transition? |
|---|---|---|
| `datatype.lifecycle.changing` | **before** the write | **yes** — throw `HookVetoException` |
| `datatype.lifecycle.changed` | **after** the write | no; observation only |

### Why the veto exists

Core refuses what core can *derive*: a declared `blocks_delete` guard counts
rows, and the state rules above know that retirement is permanent. Neither
reaches a **domain** rule — a record something downstream would be unusable
without, a per-type rule that a retired record is not trashable, a parent whose
children are mid-flight. Those are not foreign keys, and `blocks_delete` governs
`delete`, not `trash`.

Without a veto point the only option was your own route in front of core's — and
that means **two lifecycle memories for one record**, yours and
`data_type_restore_states`, disagreeing about which state a restore returns it
to. Both report `200`. That split brain is what this closes; if you are running
a parallel route for this reason, this is what replaces it.

The hook fires for **all four** mutating actions. A veto that covered `delete`
alone would leave a parallel *trash* route exactly where it was.

### The contract

```php
public function getHooks(): array
{
    return ['datatype.lifecycle.changing' => [$this, 'onLifecycleChanging']];
}

public function onLifecycleChanging(array $data, array $context): array
{
    if ($data['data_type'] === 'acme:record' && $data['action'] === 'trash') {
        if ($this->hasLiveDependants((int) $data['record_id'], (int) $data['tenant_id'])) {
            throw \Whity\Sdk\Hooks\HookVetoException::forEvent(
                'datatype.lifecycle.changing',
                'A downstream record depends on this one.'   // shown to the caller
            );
        }
    }

    return $data;
}
```

The payload is identical to `datatype.lifecycle.changed`, so one listener shape
serves both. On `changing` the values describe the **intent**:

```jsonc
{
  "data_type": "acme:record",
  "source": "Acme",
  "table": "acme_records",
  "action": "trash",              // trash | restore | retire | delete
  "record_id": 42,
  "tenant_id": 7,
  "from": "approved",             // the state the record holds now
  "to": "trashed",                // where it is about to go — null for delete
  "actor_profile_id": 10
}
```

`to` on a **restore** is the state the record will actually be returned to (the
remembered one, or the fallback), not `default_state` — which is exactly what a
listener deciding whether to allow the restore needs to know.

### What a veto does, precisely

* **No write happens.** The hook is dispatched before the transition's own
  statements *and inside the same transaction as them*, so a listener that wrote
  before a later listener vetoed is rolled back with it. When you call a
  lifecycle method inside a transaction **you** opened, core joins it rather than
  nesting — it never ends a unit of work it did not begin, and it has written
  nothing that would need undoing.
* **The caller gets `409`**, in the same envelope as every other refusal:

```json
{
  "error": "A downstream record depends on this one.",
  "details": {
    "reason": "blocked_by_plugin",
    "state": "approved",
    "blockers": []
  }
}
```

  `reason` stays a **stable key** — that is the field clients branch on, so it
  must remain enumerable; your sentence goes in `message` (the `error` field),
  where every other human-readable explanation goes. It is
  `HookVetoException::reason()`, never `getMessage()`: trimmed, control
  characters collapsed, capped at 300 characters, and free of the raw exception
  text a response must never carry.
* **No `changed` event fires**, and no audit entry is written. Nothing happened.
* **Your plugin is not penalised.** A veto does **not** count toward the
  three-strikes failure breaker — it is a healthy plugin doing its job, and
  disabling it would silently stop the very refusals you rely on.

### What a veto is not

* **Not a substitute for a crash.** Any other `Throwable` is caught by the
  per-plugin error boundary, logged, counted toward the breaker, and **the
  transition proceeds** — unchanged behaviour, and deliberate: a plugin that
  crashes is broken, not objecting, and promoting every exception to a veto
  would let one bad deploy freeze an install's whole lifecycle surface.
* **Not consulted for a refusal core already makes.** Core's checks run first —
  the type offers the action, the record exists in this tenant, the state rules
  permit it, no declared guard blocks a delete — so a core refusal keeps its own
  reason key and your listener is never asked about a transition that was not
  going to happen anyway.
* **Not fired for a no-op.** Trashing an already-trashed record writes nothing,
  so there is nothing to veto; neither hook fires, exactly as neither fires for
  `changed` today.
* **Not predictable from the pre-flight endpoint** — see
  [above](#the-one-thing-this-preview-cannot-predict).

Never throw a veto from an `*.async` listener: those run in the relay worker,
long after the request committed, and there is nothing left to refuse.

---

## Clearing a record's remembered state

Core removes the memory row itself when a restore spends it and when the record
is hard-deleted **through core**. If you hard-delete a record **outside** core —
your own route, your own SQL, a bulk purge — nothing else will:

```php
\Whity\app(\Whity\Core\DataType\LifecycleStateMemory::class)
    ->forget('acme:record', $tenantId, $id);
```

Registered in both entry points, so it resolves identically over HTTP and inside
a `whity-cli` command. **Do not `DELETE` from `data_type_restore_states`
directly** — it is a core-owned table and its shape is not part of any contract.

This matters most for **client-supplied keys**. `record_id` can carry no foreign
key (the target table varies by type), so no cascade will ever fire: a row left
behind is inherited by whatever record next occupies that key, and a later record
re-using it can be restored into a state it never held. With a `SERIAL` key the
window is narrower but not closed — restored dumps and reset sequences re-use
ids too.

**Why this is not on `DataTypeGuard`.** That contract is documented as read-only
— "every method answers a question; none trashes, retires or deletes anything" —
and holding it confers no authority a plugin does not already have. `forget()`
writes. Putting it there would falsify the one sentence that makes the guard safe
to hand out, so it is reached as its own service instead. It is a core class
rather than an SDK interface deliberately: no SDK version bump is spent on it
while the shape of "I deleted this outside core" is still settling, and the
better long-term answer for most plugins is not to reach for `forget()` at all
but to delete through `DELETE /api/data-types/{type}/{id}`, which already forgets.

## Performing a transition from your own code

Two contracts, and the split between them is deliberate:

| | `DataTypeGuard` | `DataTypeLifecycle` (SDK 1.24) |
|---|---|---|
| What it does | **asks** | **acts** |
| Methods | `stateOf`, `blockingReferences`, `isReferenceable`, `canDelete` | `trash`, `restore`, `retire`, `delete` |
| Needs an actor | no — it changes nothing | **yes**, and it is required |

```php
$lifecycle = \Whity\app(\Whity\Sdk\DataType\DataTypeLifecycle::class);

$outcome = $lifecycle->trash('acme:record', $tenantId, $id, $actorProfileId);
if (!$outcome->isOk()) {
    return Response::error($outcome->message(), $outcome->httpStatus(), [
        'reason'   => $outcome->reason(),        // the stable key — branch on this
        'blockers' => $outcome->blockers(),
    ]);
}
```

Registered in **both** entry points, so it resolves identically over HTTP and
inside a `whity-cli` command.

### Why this exists

Core told you to route your writes through core, and then published only a read
contract. The only way to actually trash a record in-process was to duck-type
`Whity\Core\DataType\DataTypeLifecycleService` — a core internal, with no
contract and no compatibility promise. That was core's fault, and this is the
fix. If you are doing that today, this is what replaces it.

`DataTypeGuard` stays **read-only**, and that is not an oversight: its whole
guarantee is that holding it confers no authority, which is what makes it safe
to hand out. Writes get a second contract rather than being smuggled into that
one.

### The same gates as the endpoint — including the ones you might hope to skip

Calling in-process is **not** a way around a check:

* a type you may not **read** is reported as **unknown** (`404`), never as
  forbidden — so holding this contract is not a way to enumerate the catalogue;
* an action the type does not **offer** is `405 <action>_not_offered`;
* an action whose declared permission you lack is `403 insufficient_permissions`,
  resolved through the same `RoleChecker` the RBAC middleware uses.

This is not two implementations written to agree. `DataTypesApiHandler` performs
no authorization of its own: the endpoint and this contract call the *same*
object, so there is nothing to drift.

That is also why `$actorProfileId` is **required**. It is the subject of the
permission check and the actor on the audit entry; an optional one could only
fail closed (a parameter that always fails is a trap) or run ungated.

`$tenantId` is explicit, exactly as on `DataTypeGuard` — an in-process caller may
be a queue worker or a CLI command with no ambient request. Passing another
tenant's id is not a way in: the permission resolves per *(profile, tenant)*.

### The outcome is the HTTP vocabulary

`LifecycleOutcome` is the *same object* the endpoint builds its response from —
not a parallel shape kept in step by hand. So `reason` is the same stable key,
`message` the same fallback sentence, `blockers` the same list, and
`httpStatus()` the same status. A plugin calling in-process and a client calling
over HTTP branch on **one** contract.

### Bulk operations: loop over single-record calls

Emptying a trash or retiring a selection is a **loop**, and that is the
sanctioned pattern rather than a stopgap:

```php
foreach ($ids as $id) {
    $outcome = $lifecycle->delete('acme:record', $tenantId, $id, $actorProfileId);
    if (!$outcome->isOk()) {
        $skipped[$id] = $outcome->reason();   // a refusal here is normal, not an error
    }
}
```

The tempting alternative is one statement:

```sql
-- Do NOT do this.
UPDATE acme_records SET status = 'archived' WHERE status = 'trashed' AND tenant_id = :tenant;
```

That bypasses **every** guard, **every** veto and **every** hook at once, and it
does so silently — the exact "bypassed through a secondary path" failure declared
guards exist to end. The loop is slower and correct.

There is deliberately **no bulk API yet**. It needs a decision that has not been
made: does one veto abort the whole batch, or is that record skipped and
reported? Shipping either as an implicit default would be worse than shipping
none, so it is a separate conversation.

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

If your route's reason for existing is that it *performs* the transition rather
than just checking it, reach for `DataTypeLifecycle` above instead: it applies
the same permission gate your route would have had to re-derive, and it keeps
one lifecycle memory for the record rather than two.

## Reference implementation

`plugins/DemoCatalog` declares `democatalog:item` over `demo_catalog_items`,
guarded by `demo_catalog_item_notes.item_id` and composed of
`demo_catalog_item_lines.item_id`. The two child tables are shaped identically
and handled in opposite ways, which is the whole point. See also
[Plugin-Development.md](Plugin-Development.md) and
[TENANT_ISOLATION.md](TENANT_ISOLATION.md).
