# Workspace plugins

A workspace plugin is a `plugin.json` manifest that belongs to one workspace. It's published as data, without a deploy, and is served on the next request (WHIT-760). It is the runtime under Whity Builder (`docs/design/WHITY-BUILDER.md` section 2.3), and it uses the same format as a code plugin's manifest ([Plugin-Development.md](./Plugin-Development.md), Step 13).

| | Code plugin | Workspace plugin |
| --- | --- | --- |
| Lives in | `plugins/` on disk | `plugin_manifest_versions`, one workspace |
| Installed by | an operator, with a deploy | publishing a manifest |
| Loaded | once per worker, at boot | read per request |
| Behaviour | PHP routes, hooks and jobs | core's fixed entry points only |

## How it is served

`TenantManifestHost` reads the workspace's live, switched-on plugins once per request: one query for the version hash of each. Parsed manifests are cached per worker by hash, so a new publish is picked up by every worker on its next request, and nothing is reloaded. The read is cleared at the end of each request, like the RoleChecker's cache.

Core's fixed entry points ask the host, so nothing is registered into a worker's routing:

- `GET /api/frontend/features` adds the workspace's screens after core's and install-wide plugins' screens. A workspace screen that reuses a taken id is dropped. All screens go through the same permission filter and block validation.
- The navigation adds a menu item for each of those screens (`TenantPluginNavigation`), and the navigation handler applies each item's permission.
- Record routes serve the plugin's record types (below). MCP operations for them are a follow-up.

## Publishing

`TenantManifestPublisher::publish($tenantId, $manifest, ...)` is the one write path. The manifest versions API (WHIT-764) adds previews, impact, tests and proposals in front of it. A publish:

1. validates the manifest as a whole, then applies the workspace-plugin policy below;
2. stores an immutable version and points the plugin at it;
3. adds its permission slugs to the global `permissions` catalogue so roles can grant them, and records them as this workspace's;
4. seeds its suggested roles into this workspace only;
5. writes `tenant_plugin.published` to the audit trail.

The system tenant can't publish a workspace plugin, because its roles are global.

### The versions API (WHIT-764)

| Route (served under `/api/v1`) | Permission |
| --- | --- |
| `GET /api/workspace-plugins` (the workspace's plugins, live version, switches) | `plugins:read` |
| `POST /api/workspace-plugins/validate` `{manifest}` | `plugins:build` |
| `POST /api/workspace-plugins/impact` `{manifest}` | `plugins:build` |
| `POST /api/workspace-plugins/test` `{manifest}` | `plugins:build` |
| `POST /api/workspace-plugins` `{manifest, notes?, confirmDestructive?}` | `plugins:publish` |
| `GET /api/workspace-plugins/{plugin}/versions` | `plugins:read` |
| `GET /api/workspace-plugins/{plugin}/versions/{id}` (with its manifest) | `plugins:read` |
| `POST /api/workspace-plugins/{plugin}/versions/{id}/activate` `{confirmDestructive?}` | `plugins:publish` |

- **Validate** answers `{ok, errors}`. It runs the whole manifest check and the workspace-plugin policy, so names of plugins installed for every workspace are refused here too.
- **Impact** compares a draft with the live version (`ManifestImpact`) and counts the records each change touches. Each change has an `effect`, a `path`, an English `message` and an Arabic `messageAr`:
  - `safe`: nothing existing changes.
  - `data`: existing records need attention, such as a required field they don't have yet, or a permission roles will lose.
  - `destructive`: existing values stop being served, such as a record type, field or state removed while records use it, or a field's type changed under its values.
  - The plan's `level` is its worst change.
- **Tests** (SDK 1.54) are examples a version's rules must keep, declared under `tests`:
  - Each test `create`s a record, or starts from a `given` record in a `state` and `update`s fields or makes a `move`.
  - It acts with the roles `as` lists.
  - It `expect`s the write `refused` (optionally on the fields in `issues`) or the `fields` and `state` it leaves.
  - They run through the same write path as the API, so the version's real rules, defaults, computed fields, locks and lifecycle apply, in a transaction that is always rolled back.
  - Permissions and automations aren't exercised.
  - `test` runs them and answers `{passed, failed, results}`. A failed result has a `reason` (`refused`, `not_refused`, `issues_differ`, `field_differs`, `state_differs`, `error`) and what the write actually gave.
- **Publish** runs the version's tests first and answers `422` (`tests_failed`) with the results when one fails.
- **Publish and activate** run the same comparison first. A destructive plan answers `409` with the plan until it is sent again with `confirmDestructive: true`.
  - Nothing is deleted from the record store either way, so rolling back serves the old values again.
  - Every publish (`tenant_plugin.published`), activation (`tenant_plugin.activated`) and refused publish (`tenant_plugin.publish_refused`, with the reason) is audited.
- **Activate** makes an older version live again (a rollback), or a newer one after a rollback.
  - The version is checked as if it were being published, so a version that no longer passes the policy is refused.
  - Its permission slugs become the plugin's again, and its roles are seeded where missing.
- `{plugin}` is the plugin's slug. Another workspace's plugin or version answers `404`.

`plugins:build` and `plugins:publish` (migration 177) are granted to every role that holds `plugins:enable`. The split lets someone draft a change that someone else puts live.

Not yet:

- previewing a draft against real data with its writes rolled back;
- replaying an automation on a past event;
- OpenAPI schemas and the MCP tools derived from them;
- publish and activate as agent proposals (agentPolicy `propose`) once agent proposals exist.

## What a workspace plugin may not declare

`TenantManifestPolicy` refuses:

- `tables`, `dataTypes` and `limits`. A workspace plugin owns no tables (its records go in the shared record store), and limits are the operator's to set.
- Dependencies on other plugins.
- A name an install-wide plugin already uses.
- Permissions outside its own name. A plugin called CakeOrders may declare only `cakeorders:…` or `cakeorders_…:…`.
- A role whose parent isn't one of its own roles, so it can't inherit an administrator's permissions.
- Any API path in a screen outside its own record routes, `/api/x/<name>/`. Every string in the block tree is checked, so a block type added later can't open a gap.

## Privacy between workspaces

Permission slugs live in one global catalogue, so role grants can reference them. Two filters stop one workspace from learning another's plugin logic from those names:

- `GET /api/permissions` leaves out slugs that only other workspaces' plugins declare.
- The roles API refuses to grant such a slug.

Two workspaces may each have a plugin of the same name. Each one's permissions are filed separately in the worker's permission registry, and grants are per workspace role.

## Records (SDK 1.50, migration 168)

A workspace plugin declares record types under `records` in its manifest: typed fields, an optional lifecycle of states and named moves, and `read`, `create`, `edit` and `delete` permissions, all of them slugs the manifest declares. The records live in one shared table, `plugin_records`, so changing a record type needs no DDL.

| Route (served under `/api/v1`) | Permission |
| --- | --- |
| `GET /api/x/{plugin}/{type}?state=&after=&limit=` | `read` |
| `GET /api/x/{plugin}/{type}/{id}` | `read` |
| `POST /api/x/{plugin}/{type}` | `create` |
| `PATCH /api/x/{plugin}/{type}/{id}` | `edit` |
| `POST /api/x/{plugin}/{type}/{id}/moves/{move}` | the move's own permission |
| `DELETE /api/x/{plugin}/{type}/{id}` | `delete` |

- `{plugin}` is the plugin's lowercase name, the same one its permissions start with.
- Every write is checked against the declaration (`RecordValues`). A refusal answers `422` with one issue per field, in the shape block forms already render. Money is minor units, and a link must point at a record of the linked type in the same workspace.
- A new record starts in the lifecycle's `initial` state. A move is refused with `409` from a state it doesn't list.
- A caller without `read` gets `404` for the type, and anything another workspace owns is `404` too, so ids can't be probed.
- Writes are audited as `tenant_record.created`, `.updated`, `.moved` and `.deleted`.
- Screens' `/api/x/...` paths are served versioned (`/api/v1/x/...`), as installed plugins' blocks are.
- An installed `ManifestPlugin` refuses `records`; an installed plugin keeps its records in its own `tables` and `dataTypes`.

**Rules (SDK 1.51).** A record type may declare `rules`, and a field `default`, `computed` and `lockedWhen`, all in the [expression language](./Manifest-Expressions.md). They hold on every write through `RecordRules`:

1. `default` fills a field a create left out.
2. Values are checked against the declaration.
3. `lockedWhen` is evaluated on the record as it is; while it holds, the field can't change.
4. `computed` is recalculated on the record as it will be, and can't be written by the caller.
5. Each rule's `require` must hold on the record as it will be, `when` its condition does. A move is checked with the record in its new state.

A refusal is a `422` issue with the rule's English `message`, its Arabic `messageAr`, and its `field` as the column. An expression that can't be evaluated on the values refuses the write. Rules see the record, its state, the actor's role names and the date (UTC for now).

Not yet: MCP operations, undo, a per-workspace OpenAPI view, filters on fields, record limits as entitlements, the schema-change classifier, trash and restore, and record types as document field providers. Each is a follow-up task.

## Automations (SDK 1.53, migration 175)

A workspace plugin declares `automations`: what it does by itself when its records change. Each one has a label, a trigger, an optional `when` [expression](./Manifest-Expressions.md) over the record, and a list of steps.

```json
"automations": {
  "confirmation": {
    "label": { "en": "Tell the bakers", "ar": "إبلاغ الخبازين" },
    "on": "order.moved.confirmed",
    "when": { ">": [{ "field": "weight_kg" }, 0] },
    "steps": [
      { "set_fields": { "values": { "confirmed_on": { "today": [] } } } },
      { "create_record": { "type": "task", "values": { "title": { "field": "customer" } }, "link": "order" } },
      { "notify": { "role": "baker", "message": { "en": "New order for {{pickup_on}}", "ar": "طلب جديد ليوم {{pickup_on}}" } } }
    ]
  }
}
```

**Triggers:**

- `<type>.created`
- `<type>.changed`
- `<type>.changed.<field>` (that field's value changed)
- `<type>.moved`
- `<type>.moved.<state>` (moved into that state)

**Steps:**

| Step | What it does |
| --- | --- |
| `set_fields` | Writes fields of the triggering record. Values are expressions over it. |
| `create_record` | Creates a record of this plugin. `link` names a field of the new record that points back. |
| `move` | Makes one of the record's declared moves. |
| `notify` | Notifies a role the manifest declares, or the person in a person field, with an English and Arabic message. `{{field}}` in the message is replaced by the field's value. Only people who can read the record type are told, up to 50 of them. |

`issue_document`, `send_webhook`, `ask_approval`, `wait` and `call_action` are refused by name until the host runs them. Schedules, dates, forms, webhooks and buttons as triggers come later too.

**How a run works:**

- **Never in the request.** The write that matches a trigger records a queued run in `plugin_automation_runs` and enqueues `core.plugin_automation.run`, in the write's own transaction. The queue worker runs the steps.
- **Who acts.** The plugin, for the person who caused the event. A step may use a permission only if the plugin declares it and that person still holds it at run time. Every run today has such a person. A run without one is refused its writes until scheduled triggers bring a policy for "the plugin acting alone".
- **Notify is the plugin's to send.** A person whose write starts a run doesn't need any permission for the notify step. The message goes only to holders of the role who can read the record type.
- **One write path.** Steps write through `RecordWriter`, the path the record routes use, so rules, the lifecycle and the audit trail all apply. Audit entries add `actor_kind: automation`, the automation, the run id and `on_behalf_of`.
- **Exactly once per step.** Each step commits with its trace entry. Before a step runs, the trace is re-read under a row lock, so a retried run skips what is done, and two workers holding the same run take turns. A run whose automation was republished with different content since it was queued stops with `automation_changed`.
- **No lost updates.** An update or a move reads the record locked in its transaction, so an automation and a person writing at the same moment can't overwrite each other unseen. The runs a write starts commit with it: if they can't be recorded, the write fails too.
- **Failure.** A refused step fails the run with the reason in its trace, and the run isn't retried. An unexpected error rolls the step back and the queue retries it.

**Guards:** a run stopped by a guard is recorded with its reason.

- **Depth.** A write made by a run starts runs one level deeper, and a run deeper than 3 is stopped with `depth_limit`.
- **Budget.** Every run in a chain counts against the chain's first run, at most 100 in all (`run_budget`). Depth alone would let one write multiply into thousands of runs before it stopped them.
- **Fan-out.** At most 10 runs per write (`fan_out_limit`).
- **One record per stop.** A guard records one stopped run per write, not one for each automation it held back.
- **Sizes.** At most 50 automations per manifest and 20 steps per automation.
- **Conditions.** A `when` that can't be evaluated is stopped as `condition_error`.

**The off switch.** A plugin's automations can be switched off without switching off the plugin. While they are off, writes start no runs, and a run already queued stops with `automations_off`.

| Route (served under `/api/v1`) | Permission |
| --- | --- |
| `GET /api/workspace-plugins/{plugin}/automation-runs?before=&limit=` | `plugins:read` |
| `GET /api/workspace-plugins/{plugin}/automation-runs/{id}` | `plugins:read` |
| `PUT /api/workspace-plugins/{plugin}/automations` `{"enabled": bool}` | `plugins:enable` to switch on, `plugins:disable` to switch off |

`plugins:read` alone doesn't show a run. Its trace carries record values, so it is shown only to someone who can also read its record type and the types its steps create.

A run reads as its trigger (event, record, depth, the person), its status (`queued`, `running`, `done`, `failed`, `stopped`) and reason, and its trace. The trace has one entry per step it reached: `step`, `kind`, `status`, `reason`, `input`, `outcome` and `at`.

Not yet: per-workspace run quotas as entitlements, the four actor columns on audit rows, traces over MCP, undo windows on steps, and marking a run failed when its job is dead-lettered (it stays `running`). These are in WHIT-795 and WHIT-792.

## Template slots (SDK 1.55, WHIT-742)

A slot is a named place the plugin needs a document template, such as its order confirmation. The plugin names the slot; each workspace decides which of its templates fills it, and can swap it without touching the plugin.

```json
"documents": {
  "slots": {
    "order_confirmation": {
      "label": { "en": "Order confirmation", "ar": "تأكيد الطلب" },
      "record": "order",
      "kinds": ["template"]
    }
  }
}
```

- **Key.** The host addresses the slot as `<plugin name>.<slot>`, for example `CakeOrders.order_confirmation`, the same as a code plugin's slots.
- **`record`** must be one of the manifest's own record types. The slot's documents are for that record type, which the document field registry knows as `<plugin name>.<record>`.
- **`kinds`** says which document kinds can fill it: `template` (the default) or `label`.
- **No starter yet.** Starters come with WHIT-687; until then an unbound slot has no template, and issuing from it is a 409 `slot_unbound`.
- At most 50 slots per manifest.

**Templates for its records.** In a workspace where the plugin is on, each of its record types is a document record type, `<plugin name>.<type>` (`CakeOrders.order`), so the editor offers it when a template is written. A record is addressed as `CakeOrders.order:12`. Its fields are `record.<field>`, read with the record type's `permissions.read`, and print as a reader sees them: money in major units with its currency (`18.000 JOD`), dates as `5 November 2026`, choices by their label in the reader's language, a person by name (members of the workspace only), a linked record by its title field, and Yes or No. An empty field is empty on the page.

**Binding.** Bindings are the tenant setting `documents.slot_bindings` (slot key to template id). They're written only through the slots route, which checks the template fits; the settings PATCH refuses the key. A template fits when it is published, shared with the workspace (not personal), of a kind the slot takes and for the slot's record type.

| Route (served under `/api/v1`) | Permission |
| --- | --- |
| `GET /api/document-template-slots` | `documents:read` |
| `PUT /api/document-template-slots/{key}` `{"template_id": 42}`, or `null` to unbind | `documents:publish`, with If-Match |

The list shows only the slots of switched-on plugins, each with its binding and, when the bound template no longer fits, the `problem`. Its ETag names the whole binding set: a PUT without If-Match is 428, and one made against an older set is 409 `stale`.

**Issuing.** `POST /api/documents` takes `slot` in place of `document_source_id` and issues the bound template's published version, with the same record, values, origin key and permission checks. It's a 409 when the slot can't be issued from:

| `code` | Why |
| --- | --- |
| `slot_unavailable` | No such slot here, or its plugin is off (`plugin` names it) |
| `slot_unbound` | Nothing is bound to it |
| `slot_misfit` | The bound template no longer fits; `reason` says why |

Not yet: code plugins declaring slots through the SDK (`PluginDocumentsInterface`), the `Documents` service for plugins and automations, starters, and the `issue_document` automation step (WHIT-795).

## Tables (migration 164)

- `plugin_manifest_versions`: immutable published versions, with hash, publisher, the person acted for, and the approver.
- `tenant_plugins`: the live version for each plugin, and whether it is switched on.
- `tenant_plugin_permissions`: the slugs each workspace's plugins declare.

All three are tenant-owned, and every query binds `tenant_id`. `plugin_records` (migration 168) and `plugin_automation_runs` (migration 175) are tenant-owned too. Migration 175 also adds `tenant_plugins.automations_enabled`.
