# Whity Builder: a paid, no-code plugin builder (vision and design)

Status: **design approved in principle; the owner answered questions 1-9 on 31 Oct 2026** (section 7). Questions 10 and 11 are open. Nothing here is built, and no implementation PR opens until the owner approves the plan. Written 31 Oct 2026 against `origin/develop` at `0b3e2d4e`.

The owner's ask: "creating plugins and changing how whity works now is technical and hard... i want a future with a paid whity-builder a no-code plugin building tool with mcp to let users and theirs ai customize their company logic on the fly".

This document surveys how plugins are built today (section 1), defines Whity Builder (section 2), draws the open-core line (section 3), lists what core needs first and how it maps onto work already in flight (section 4), phases the work (section 5), and ends with risks and questions for the owner (sections 6 and 7).

Sibling designs this one builds on and doesn't repeat:

- **Document themes** (`DOCUMENT-THEMES.md`, WHIT-731) and **document automation** (`DOCUMENT-AUTOMATION.md`, WHIT-740), branch `design/document-themes`. Themes, template slots and starters are JSON validated by JSON Schema; issuing a document is a call to one core `DocumentIssuing` service.
- **Permission-aware MCP tool surface** (the "Design permission-aware MCP tool surface in the SDK" session): permission-filtered tool lists, toolsets, the `agentPolicy` key, plugin namespacing and the MCP splitter into per-slice endpoints.

## Summary

- **Today a plugin is a PHP package** dropped into `plugins/` on the server by an operator, loaded once per worker at boot, install-wide, running in-process with full host privileges. Much of what it declares is already data (data types, permissions, UI block trees, events, jobs, entitlements), but the behaviour behind every route and hook is PHP, and nothing changes without a deploy.
- **Whity Builder lets a company, or its AI, describe its own logic as data**: record types and fields, forms and views, rules, automations, document templates and themes, roles, and webhooks. The result is a **manifest plugin**: one JSON document, validated by core and run by core.
- **One runtime, not two.** Core gains a declarative plugin host that turns a manifest into the same registrations a PHP plugin makes (data types, block screens, permissions, events, jobs, MCP tools). A hand-written plugin can ship the same manifest beside its PHP. A builder plugin exports to a code plugin without changing how it runs.
- **Live, per tenant, without a deploy.** A published manifest version is data in the tenant's database. Core resolves it per request through fixed entry points (one generic route family, one listener per event, one tool family), so nothing registers late in a worker and nothing needs a restart. Publishing takes effect on the next request; rollback is moving a pointer back.
- **The AI proposes, a person publishes.** Over MCP an agent can read the tenant's logic, draft a change, see its impact, preview it on real data without side effects and run its tests. Publishing is a proposal a person with the right permission accepts, as everywhere else in Whity.
- **A no-code plugin can't run code.** No PHP, JavaScript or SQL, no network calls except signed webhooks to allowed hosts, no data outside its own records and what it was granted to read, no permissions outside its namespace, and hard limits on size and runs.
- **Open-core line.** Core (AGPL, free) holds the manifest format, its validator and the runtime, and keeps running published logic with no builder attached. The paid service (closed, hosted, like Whity ID and Whity Pay) holds the visual builder, AI authoring, drafts and history, tests and previews, the template gallery and export. The paid service checks the subscription; core only mirrors it as an ordinary entitlement that decides whether the builder's MCP toolset is shown.
- **WHIT-667 allows it.** The owner decided on 31 Oct 2026 that the declarative host (P1-P7), the record store included, is an extension of the plugin host and SDK, which the freeze names as Whity's job, and not a new generic subsystem.

## 1. How plugins are built today

### 1.1 What a plugin is

A plugin is a PHP class implementing `Whity\Sdk\PluginInterface` from `whity/plugin-sdk`, a separate MIT package (`sdk/`). It depends only on the SDK, never on whity-core; ADR 0014's plugin exception lets it carry any licence. The six required methods are `getName`, `getVersion`, `getRoutes`, `getPermissions`, `getHooks` and `getMigrations` (`docs/wiki/Plugin-Development.md`). Everything else is an optional capability interface:

| Capability | Interface (SDK) | Already data? |
| --- | --- | --- |
| Routes and handlers | `getRoutes()` | Route table is data; each handler is a PHP callable |
| Permissions | `getPermissions()` | Yes: `resource:action` slugs |
| Hooks | `getHooks()` | Event names are data; callbacks are PHP |
| Migrations | `getMigrations()` | No: PHP classes with `up()`/`down()` |
| Version gates | `PluginRequirementsInterface` | Yes: SDK, core and plugin constraints |
| Owned tables | `PluginTablesInterface` | Yes |
| Data types (lifecycle, delete guards, cascades) | `PluginDataTypesInterface` | Yes, with generated endpoints |
| Admin screens | `getFrontendFeatures()` with `screen: 'blocks'` | Yes: a validated block tree (SP1-SP3) |
| MCP tools | derived from routes (`ToolDeriver`); `PluginMcpToolsInterface` by hand; prompts via `PluginMcpInterface` | Derived from route schemas; authored tools are PHP |
| Background jobs | `PluginJobsInterface` (SDK 1.28) | Names are data; handlers are PHP |
| Audited events | `PluginEventsInterface` (SDK 1.29) | Yes |
| Settings, roles, OU types, resource types, time windows, routing rules, health probes | `PluginSettingsInterface`, `PluginRolesInterface`, `PluginOuTypesInterface`, … | Yes |
| Plan limits | `PluginEntitlementsInterface` | Yes |
| Documents | `DocumentRenderer` (SDK 1.41) as a host service; themes, slots and starters as JSON once WHIT-738 and WHIT-742 land | Partly |

So the declarations are largely data already. **What can't be data today is behaviour**: route handlers, hook callbacks, job handlers and migrations. The SDK is `whity/plugin-sdk` 1.46.0; core is 0.2.19.

### 1.2 Lifecycle and loading

- **Discovery.** `PluginLoader::discover` (`src/Core/PluginLoader.php:864`) scans `plugins/`, maps each folder to a PSR-4 namespace of the same name, `require`s the files and keeps classes that implement `PluginInterface`. A lexical pre-scan refuses class redeclarations. There is **no manifest file**: the PHP class is the manifest.
- **Boot order** (`gateAndOrder`, `:2475`): duplicate names, then the SDK and core version gates, then plugin dependencies, then a topological sort. Failures are quarantined. Plugins register after core, so core wins any collision.
- **Validation is per piece.** Each registry checks its own part (permission slugs `resource:action` at `:80`, feature ids at `:85`, routes in `registerCapabilities`) and drops a bad piece with a logged warning. There's no schema for a plugin as a whole.
- **Enable, disable, uninstall** (`src/Api/PluginsApiHandler.php`) are **install-wide**; the state is a `.disabled` sentinel file on disk. Enabling runs the plugin's migrations and seeds its roles. `EntitlementRegistry.php:83-91` notes that selling a plugin per tenant "needs per-tenant plugin enablement first", which doesn't exist.
- **Per-worker state.** Each FrankenPHP worker holds its own loader. Other workers catch up on their next reload. A changed plugin class can't be redefined in a live worker, so `reload()` recycles the worker; outside development a changed plugin needs a deploy or restart (`Plugin-Operations.md`).
- **Sealed catalogues.** Registries such as plugin entitlements are filled at boot and then sealed, because a late registration in one of eight workers makes access depend on which worker answered (`PluginEntitlements.php`). Any per-tenant, live mechanism has to respect this.

### 1.3 Extension points

- **Hooks:** `getHooks()` maps an event to a PHP callback and priority. 37 core events are in `sdk/src/Hooks/Events.php`; `HookManager` runs synchronous filter chains and `dispatchAsync` writes to the durable outbox (ADR 0013, `DomainEventStore`, `EventRelay`). Data type transitions fire a vetoable `datatype.lifecycle.changing`.
- **Routes:** a route table with a PHP handler each, wrapped in a per-plugin error boundary, auto-versioned to `/api/v1/…`, with an inline OpenAPI `schema` that also drives MCP tool derivation.
- **Migrations:** PHP classes with hand-written SQL that must work on Postgres and SQLite, with a hand-added `tenant_id` column, tracked as `plugin:<Name>:<Class>`. Granting a plugin's permissions to admins is its own migration.
- **Data access:** handlers write their own SQL through the host's PDO (`\Whity\app(Database::class)`), each statement carrying its own tenant predicate. SDK linters (`MigrationTenantColumnLinter`, `TenantPredicateScanner`) help but don't enforce at runtime.
- **Data types** (`PluginDataTypesInterface`) already give a declared table generated lifecycle endpoints, delete guards, cascades and per-action permissions with no handler code. This is the closest thing to a no-code record type today.
- **Jobs and schedules:** `getJobs()` handlers are PHP; schedules are rows in `scheduled_jobs` pointing at job names; there's no plugin interface for schedules.
- **Not found anywhere:** custom fields on core entities, saved views, outbound webhook subscriptions, automation or workflow rules.
- **Existing no-code-ish engines:** the Forms engine (`src/Core/Form`: ten field types, public links, uploads, prefill), relationship types, taxonomy, notification templates, document route templates.

### 1.4 UI

`getFrontendFeatures()` supports five screen kinds (`PluginLoader::validateFrontendFeature`, `:3723`):

- `crud` and `action`: generated screens over the plugin's own routes;
- **`blocks`**: a validated, platform-neutral block tree (53 types in `BlockContract.php`: layout, `dataTable`, `dataStat`, `dataRecord`, `chart`, forms and inputs, `actionButton`, modals, `visibleWhen`, `accessGate`; depth 32, 500 nodes), rendered natively on web and meant for mobile and desktop;
- `embed`: an iframe of a plugin route with `sandbox="allow-scripts allow-same-origin allow-forms"`, which together give the frame the page's origin back, so it isn't a real sandbox (`web/components/plugin/embed-screen.tsx:49`);
- `custom`: needs a component added to the host app.

There is no way for a plugin to ship a JavaScript bundle. **`blocks` is the right, and only safe, target for no-code UI.** The `embed` sandbox finding is filed as WHIT-770; it matters for M7's sandboxed blocks.

### 1.5 Trust and sandboxing

PHP plugin code is **not sandboxed**: once enabled it runs in-process with the host's full access. The protections are the per-plugin error boundary and automatic quarantine after three consecutive errors (`PluginLifecycle.php`). The installer calls itself "dev/self-hosted convenience ... not marketplace-grade" (`PluginInstaller.php:30`); it checks zip-slip and zip-bomb limits and introspects uploads in a child process, and uploads land disabled. There are no trust tiers, no signing and no capability declarations. WHIT-284 (write the trust boundary down) and WHIT-278 (integrity) are open.

This is why a no-code plugin must not be code: data the host interprets is the only kind of tenant-authored extension that can be safe without a sandbox core doesn't have.

### 1.6 Distribution, and Elmak

- **Real plugins live in their own repositories** and are deploy-copied into `plugins/` (`Plugin-Distribution.md`); `plugins/.gitignore` keeps them out of core. Other routes: a zip upload (`POST /api/plugins/upload`, 32 MiB), an allow-listed store install trusted by host and TLS only, and obfuscated desktop releases (`src/Core/DesktopPlugins`). There's no plugin update flow.
- **Elmak** is the in-house product built as plugins outside this repository. WHIT-683 records its experience in one sentence: "Today each plugin builds the PDF layout itself". Each Elmak feature is its own PHP, migrations, permission-grant migration, screens and deploy, even where it only needs records, a form, a list and a document. That is the shape most company logic takes, and it's what the builder makes declarative.

### 1.7 Why it is technical and hard

1. **PHP for everything**, even pure metadata, because the class is the manifest.
2. **Hand-written, dual-dialect SQL** for every table, with manual tenant columns and predicates; a mistake is a cross-tenant leak.
3. **Ceremony:** permission-grant migrations, OpenAPI schemas for MCP tools, versioned paths, test scaffolding.
4. **Operator-only installs:** a deploy-copy or zip upload by someone with server access, install-wide, then a restart for changes.
5. **No per-tenant plugins:** one company can't have its own logic without everyone on the instance getting it.
6. **No safe tier:** because plugins are fully trusted code, only trusted authors may write them, so a customer can never be allowed to.
7. **No automations, webhooks or rules engine** to declare behaviour with; every "when this, do that" is a PHP hook.

## 2. What Whity Builder is

Whity Builder is where a company shapes Whity to how it works, without a developer: what it keeps track of, the forms people fill in, the screens they work from, the rules that stop mistakes, what happens automatically, the documents that go out, who may do what, and what other systems hear about it. The user can do this by hand in a visual builder, or by telling their AI what they want and approving what it proposes.

Example (Example Bakery takes custom cake orders):

> "Track custom cake orders: customer, cake type, weight, price, deposit and pickup date. A deposit of at least 30% is needed before an order is confirmed. When an order is confirmed, send the customer an order confirmation from our template and tell the baker on duty. Bakers see today's orders; only the shop manager can change prices."

That is one record type, one form, two views, one rule, one automation, one document template and two roles. Today it is a PHP plugin, a migration, a deploy and a developer. With the builder it is a manifest the bakery's own AI drafts in a minute and the manager publishes after looking at the preview.

### 2.1 The building blocks

Each block maps onto something core already has, or onto a sibling design. The builder adds no second version of any of them.

| Block | What the user defines | Runs on (core) |
| --- | --- | --- |
| **Record types and fields** | Name, fields (text, long text, number, money, date, date-time, yes/no, choice, multi-choice, file, link to a person, OU or another record type), required, unique, default, computed, label in English and Arabic | Record store (2.3) plus a data type declaration (`PluginDataTypesInterface`): lifecycle states, trash and restore, delete guards and cascades come for free |
| **Lifecycle** | States (Draft, Confirmed, Ready, Collected) and allowed moves, who may make each move | Data type lifecycle (states are already declared), extended with named moves between them and a permission per move; `datatype.lifecycle.changing` veto |
| **Forms** | Which fields, in what order, sections, help text, conditional visibility | Block trees: `form` and its inputs (SP3), `visibleWhen`. Public forms reuse the existing Forms engine (`src/Core/Form`) and its public links |
| **Views** | Lists with columns and filters, record pages, stat tiles, a dashboard | Block trees: `dataTable`, `dataStat`, `dataList`, `dataRecord`, record pages (`/admin/x/{feature}/{id}`) |
| **Rules** | Validations ("deposit at least 30% of price"), computed fields ("balance = price - deposit"), defaults, field locks by state | Expressions (2.4) evaluated on the record save path, synchronously, with a budget |
| **Automations** | When (record created or changed, state moved, form submitted, a date reached, a schedule, an inbound webhook, a button), if (an expression), then steps | Event spine and outbox (ADR 0013), job runner, scheduler; step catalogue in 2.5 |
| **Documents** | Templates for a record type, a theme | The editor and template slots (WHIT-740, WHIT-742); themes (WHIT-731). A record type is a field provider automatically |
| **Roles and permissions** | Who may read, create, edit, move state, delete, see a field | Permissions in the plugin's namespace, registered like any plugin's, bundled into suggested roles; people get roles through core RBAC as today |
| **Integrations** | Outbound webhooks on events, inbound webhooks that start an automation | Outbox relay, signed webhook routes (#1320), WHIT-302 and WHIT-430 |
| **Agent tools** | Which actions agents may take on these records, and which need a person's approval | Tools derived like any plugin route, with `agentPolicy` and a toolset from the MCP design |
| **Tests** | "Given this order, saving it should fail with this message"; "confirming it should issue one document and one notification" | Test runner in core's preview channel (2.7) |

What the builder deliberately leaves out at first: custom editor blocks with their own code (M7's sandboxed frame), new chart kinds, money movement (Whity Pay), identity (Whity ID), and anything that needs a loop or a network call outside a webhook step.

### 2.2 The manifest

Everything above is one JSON document, the **plugin manifest**, versioned and validated by JSON Schema published with the SDK (the same way WHIT-701 ships the block catalogue). Abridged:

```json
{
  "manifest": "whity.plugin/1",
  "plugin": { "key": "cake-orders", "name": { "en": "Cake orders", "ar": "طلبات الكيك" }, "version": 7 },
  "requires": { "sdk": "^1.45" },
  "records": {
    "order": {
      "label": { "en": "Cake order", "ar": "طلب كيك" },
      "fields": {
        "customer":  { "type": "person", "required": true },
        "cake_type": { "type": "choice", "options": ["birthday", "wedding", "custom"] },
        "weight_kg": { "type": "number", "min": 0.5 },
        "price":     { "type": "money", "currency": "JOD" },
        "deposit":   { "type": "money", "currency": "JOD", "default": 0 },
        "balance":   { "type": "money", "computed": { "-": [{ "field": "price" }, { "field": "deposit" }] } },
        "pickup_on": { "type": "date", "required": true }
      },
      "lifecycle": { "states": ["draft", "confirmed", "ready", "collected"], "initial": "draft",
                     "moves": { "confirm": { "from": "draft", "to": "confirmed", "permission": "edit" } } },
      "rules": [
        { "when": { "state.moving_to": "confirmed" },
          "require": { ">=": [{ "field": "deposit" }, { "*": [{ "field": "price" }, 0.3] }] },
          "message": { "en": "A deposit of at least 30% is needed to confirm.", "ar": "يلزم عربون لا يقل عن 30% لتأكيد الطلب." } }
      ]
    }
  },
  "automations": [
    { "key": "confirmation", "on": "order.moved.confirmed",
      "steps": [
        { "issue_document": { "slot": "order_confirmation", "record": "$record" } },
        { "notify": { "role": "baker", "message": { "en": "New order for {{pickup_on}}" } } }
      ] }
  ],
  "views": { "today": { "blocks": [ { "type": "dataTable", "source": "records:order?pickup_on=today", "columns": ["customer", "cake_type", "weight_kg"] } ] } },
  "permissions": { "read": ["baker", "manager"], "edit": ["manager"], "field:price:edit": ["manager"] },
  "documents": { "slots": { "order_confirmation": { "record": "order", "starter": "starters/confirmation.json" } } },
  "tests": [ { "name": "low deposit blocks confirm", "given": { "price": 50, "deposit": 10 }, "move": "confirm", "expect": { "refused": true } } ]
}
```

Rules for the format:

- **Data, not code.** Every value is a literal, a reference (`field`, `$record`, `$actor`, `$now`), or an expression in the closed vocabulary of 2.4. No string is ever evaluated as code.
- **Everything has an English and an Arabic label**, as the register requires for visible strings.
- **References are checked at validation**: a view's `source` names a record type in this manifest; a slot names a starter the manifest ships; a role names a permission set it declares. A manifest that fails validation can't be published; it never half-loads.
- **The same file a developer writes.** A code plugin may ship `plugin.json` next to its PHP, and the loader treats the declarative parts identically. **Decided** (owner, 31 Oct 2026): M7's plugin manifest and block registry (E.17-E.20) are this manifest, not a second format, and code plugins are encouraged to declare their data types, screens and permissions in `plugin.json` (the plugin docs and the HelloWorld example say so once P1 lands).

### 2.3 One runtime: the declarative plugin host

The rule: **a builder plugin is a plugin**. It goes through the same registries, permission checks, tenant predicates, audit trail, MCP derivation and block validator as a PHP plugin. Core adds one adapter and a small set of fixed entry points:

```
                    ┌─────────────────────────── whity-core (AGPL, free) ───────────────────────────┐
 PHP plugin ──────► │ PluginLoader ──► registries (routes, permissions, data types, features,       │
 (disk, install-    │                  events, jobs, entitlements, MCP tools)                        │
  wide, at boot)    │      ▲                                                                         │
                    │      │ same declarations                                                       │
 plugin.json ─────► │ ManifestPlugin adapter (install-wide manifests on disk)                        │
                    │                                                                                │
 published ───────► │ Tenant manifest host: per request, reads the tenant's active manifest version  │
 manifest version   │  (cached per worker by version hash) and serves it through fixed entry points: │
 (tenant DB row)    │   • /api/x/{plugin}/{record}… generic record routes                            │
                    │   • one listener per core event, dispatching to the tenant's rules/automations │
                    │   • /frontend/features merges the tenant's block screens                       │
                    │   • MCP tools/list merges the tenant's derived tools                           │
                    │   • permission registry overlay for the tenant's namespace                     │
                    └────────────────────────────────────────────────────────────────────────────────┘
```

Why fixed entry points rather than registering each tenant's plugin into the router and registries: those registries are per-worker static state built at boot and then **sealed** (`PluginEntitlements::seal()` says why: a late registration in one of eight workers makes access depend on which worker answered). A tenant's manifest is therefore never registered; it is **read**. The entry points are registered once, by core, at boot, and look up the tenant's active version on each call. A cache keyed by `(tenant, version hash)` makes that a memory read after the first request, and a publish changes the hash, so every worker converges on the next request with no reload and no restart.

**Where the records live.** Two options:

| | A. Record store (recommended) | B. Real tables per tenant |
| --- | --- | --- |
| Shape | One core table, `plugin_records (tenant_id, plugin_key, record_type, id, state, data JSON, schema_version, timestamps)`, plus declared indexes (Postgres expression indexes / a side index table on SQLite) | `CREATE TABLE` per record type per tenant at publish |
| Live changes | Adding a field is instant; nothing locks | DDL at publish, per tenant; long locks on big tables |
| Rollback | Pointer move; old fields kept until a confirmed purge | Needs `down()` per change, data loss risk |
| Tenant isolation | One table, one predicate, covered by `CrossTenantRejectionRealEngineTest` once | A table per tenant, each needs the guard |
| Speed | Fine for thousands to low millions of rows per type with declared indexes; reports read through the same store | Native speed |
| Export | "Store-backed" export keeps it; "table-backed" export generates migrations and a copy job | Direct |

Option A keeps live changes safe and keeps the runtime small. **Decided: option A** (owner, 31 Oct 2026), and it counts as part of the plugin host under WHIT-667.

**Schema changes.** Every publish is diffed against the active version and classified:

- **Safe** (add a field, add a state, relabel, add a view): published as is.
- **Needs a data plan** (make a field required, change a type, split a state): the impact shows counts ("41 orders have no pickup date") and the plan the person approves (default value, convert, or leave old records alone with `schema_version`).
- **Destructive** (remove a field or record type): the data is hidden, not deleted, and is purged only by a separate, explicit action after a retention period. Rolling back within that period loses nothing.

### 2.4 Expressions

Rules, computed fields, conditions and visibility share one expression language. It is a **JSON tree with a closed set of operators**, in the style of JSON Logic: comparisons, arithmetic, money arithmetic in minor units, dates (`today`, add days, between), text (contains, starts with, length), lists (any, all, count over a record's own lines), `if`, and references to fields, the record's state, the actor's roles and `now`. No loops, no recursion, no user-defined functions, no access to anything outside the record, its declared links and the actor.

- **Evaluated identically in PHP and TypeScript** (forms check rules as people type), from one conformance fixture set, like the block contract's renderer rules.
- **Bounded**: a size cap, a depth cap and an evaluation budget per call; a rule that hits a cap is a validation error at publish, never a runtime surprise.
- **Ours, in the SDK** (MIT), not a dependency, so there is no licensing or supply-chain review and the operator set is what we say it is. **Decided** (owner, 31 Oct 2026): our own evaluator, no new dependencies.
- **AI-friendly**: a model writes JSON reliably, every expression has a readable English rendering for the person approving it ("deposit is at least 30% of price"), and a diff is a tree diff.

### 2.5 Automations

An automation is a trigger, an optional condition and a list of steps. It runs in the background on the outbox relay or the job runner, never inside the request that caused it, except for rules (2.4), which are synchronous by design.

**Triggers:** record created, changed, a field changed, moved to a state; form submitted; a date field reached (pickup tomorrow), a schedule (WHIT-586 temporal spine); an inbound signed webhook; a button on a record page; another plugin's or core's published event the plugin has permission to read (`document.issued`, `user.created`).

**Steps (closed catalogue):**

| Step | Notes |
| --- | --- |
| Set fields on this record | Through the same save path, so rules run |
| Create or update a record of this plugin | Same save path |
| Move state | Same lifecycle gate and veto |
| Issue a document from a slot | `DocumentIssuing` (WHIT-741) with an `originKey` from run and step, so a retry never issues twice; background issuing needs the workspace allow-list (WHIT-745) |
| Notify people, a role or the record's person | Core notifications and inbox |
| Send a webhook | Signed, through the outbox, to a host on the tenant's allow-list; never to private or internal addresses |
| Ask for approval | Creates a proposal or an inbox task and pauses the run until someone decides |
| Wait | Until a date or for a duration, as a scheduled job |
| Call another plugin's action | Only an action that plugin publishes for automations, under the permission it names |

**Who acts.** An automation runs as **the plugin acting for the person who caused the event**, with the intersection of that person's permissions and the plugin's grants; a scheduled run with no person acts as the plugin alone, limited to its own records and the grants the workspace gave it. The audit trail records both, as the agent rules do for agents.

**Guards:** a depth counter stops automations from triggering each other forever (the event carries how many automation hops produced it); per-run step and fan-out caps; per-tenant run quotas as entitlements; every run keeps a trace (trigger, each step, input, outcome) that the builder shows and the AI can read.

### 2.6 How a person and their AI change the logic live

The loop is the same in the visual builder and over MCP:

1. **Describe.** The agent reads what exists: the active manifest, its record types and their counts, recent automation traces, the building-block catalogue and the limits. (`builder_describe`)
2. **Draft.** It proposes a change as a list of operations on the manifest (add field, add rule, change step), not a whole new file, so the person sees exactly what moves. Each draft is validated on every change. (`builder_draft_change`)
3. **See the impact.** Core diffs the draft against the active version: safe or needs a data plan, records affected, permissions added or removed, automations that would now fire, agent tools that would appear. (`builder_impact`)
4. **Preview.** The draft runs in the **preview channel** (2.7) against the tenant's real records, read-only: forms render, rules evaluate, an automation is replayed on a chosen past event and shows what it would have done, with every side effect captured instead of performed. (`builder_preview`, `builder_run_tests`)
5. **Propose.** The agent submits the draft for publishing. This creates a proposal (section 12 item 10, `agent_proposals`) shown with the ActionProposal card: the change in plain words, the impact, the test results, the agent and the person it acts for. (`builder_propose_publish`)
6. **Publish.** A person with `plugins:publish` for this plugin accepts it. Core records the new version, moves the pointer, and it is live on the next request.
7. **Roll back** at any time: a proposal to move the pointer to an earlier version, with the impact shown the same way.

The agent never publishes or rolls back by itself, matching the rule that agents only suggest. The person can also build in the UI with the same steps; the UI is a client of the same API. **Decided** (owner, 31 Oct 2026): a workspace may let agents run previews and tests freely (`builder_preview`, `builder_run_tests` and the read tools can be Allowed). Publishing anywhere, including to a sandbox, is always a proposal a person approves, at least until a sandbox tenant exists.

**Which MCP endpoint.** In the vocabulary of `MCP-TOOL-SURFACE.md` (WHIT-746), as the owner decided it on 31 Oct 2026 (its section 9). The bare `/mcp` lists about five always-on, read-only core tools plus `search_operations` and `call_operation(operationId, arguments)`; everything else is found by search, which filters by RBAC, entitlement and the token's toolsets.

- **The resulting plugin's own operations** (create an order, confirm it) are derived from its generic record routes like any plugin's, in toolset `<pluginKey>`, visibility `discoverable`, and reached through `search_operations` and `call_operation`, not listed directly. A manifest may mark **at most one** operation `always-on`; it must be read-only, and it is listed only after a tenant admin enables it. The validator refuses anything else (visibility values are `always-on`, `discoverable` and `off`). Each action carries an `agentPolicy` from the manifest (`allow`, `refuse`, `suggest-only` or `propose`, WHIT-753), so "only a person may confirm an order" is one line. An agent write with no declared policy defaults to `propose` once `agent_proposals` exists.
- **Core's manifest-version routes** (validate, diff, preview, test, publish, activate; P7) derive into the core toolset `core.plugins`, `discoverable`, with an explicit `agentPolicy: propose` on publish and activate. Any MCP client can use them; this is the free, open side.
- **The builder's own tools** (`builder_describe`, `builder_draft_change`, and the rest of the loop above) form the toolset `whity-builder`, marked `requiresEntitlement`. Search finds them like any other toolset, and only for a workspace that holds the builder entitlement. The split endpoint `/mcp/whity-builder` is optional and runs in the same search-and-call mode. Plugin splits never appear in the public AI catalogue. The handlers forward to the hosted service, which adds drafts, history, change operations and plain-language impact on top of core's routes. Proposed shape: a small closed connector plugin under the ADR 0014 plugin exception contributes the toolset; core itself gains no builder code (question 10).

Tool names keep to `[A-Za-z0-9_-]{1,64}`, as that design requires. That design (commit 554c9f26) also treats the tool catalogue as per tenant: budgets are evaluated for the caller's tenant, the catalogue signature includes a hash of that tenant's manifest-plugin tools so a publish notifies only that tenant's clients, and a manifest plugin's split endpoint `/mcp/<pluginKey>` exists only for its own tenant (404 elsewhere).

**Agents act, people approve, in builder plugins too.** The register's agent model (scope grant, 7.5a proposal, 7.5c undo) applies to a builder plugin's operations exactly as to core's:

- **Every agent is an `ai-agent` principal** acting for the person who granted it, never above that person's RBAC (MCP-TOOL-SURFACE.md 5.11, WHIT-753, WHIT-784). A builder action gets its mode from the grant on the permission it declares: the person sets each of the plugin's permissions to **Off**, **Ask first** or **Allowed** in the scope grant, like any other permission.
- **A manifest may set a ceiling per action** (`agentPolicy`): `propose` means always Ask first even if the grant says Allowed; `refuse` means never for agents. Without one, the grant decides. Core's manifest `publish` and `activate` routes carry `propose` as a fixed ceiling.
- **Ask first is enforced on the server.** The call becomes a stored proposal holding the operation, its arguments, the diff, the permissions used and the undo window. A person approves, edits or declines, and approval executes it for the granting person in the proposal's tenant.
- **Every write records its actor** in the same four actor columns core uses: a person, an agent for a person, or the system. An automation run (2.5, WHIT-763) writes `actor_kind` system, or agent for person when an agent's call started it, with the person who caused the event recorded beside it.
- **Undo.** Each write operation declares an undo window in the manifest (default proposed: 30 minutes for record changes, none for sent webhooks and issued documents, which are compensated, not undone). Undo shows on the toast and stays on the activity item for that window. Publishing a manifest version is undone by rollback (2.7), which has no time limit.
- **Building is always Ask first for agents.** `builder_propose_publish` and `builder_propose_rollback` can never be set to Allowed: a person approves every change to company logic.

Anything here the register doesn't already decide (the default undo windows per kind of builder write) is proposed for the owner to confirm, not decided (question 11).

### 2.7 Versions, drafts, preview and tests

- **Versions.** Each publish is an immutable row `plugin_manifest_versions (tenant_id, plugin_key, version, manifest, hash, published_by, acting_for, approved_by, published_at, notes)`. One row per plugin holds the active version. Core keeps all published versions, so history and rollback survive without the builder.
- **Drafts** live in the builder service (history, comments, branches, the AI's working copies). Core sees a draft only when asked to validate, diff, preview or test it; it doesn't store drafts.
- **Preview channel.** Core runs a draft manifest for one request with the live data read-only: writes go into a transaction that is rolled back, and side-effect steps (documents, notifications, webhooks) are recorded in a trace and not performed. That is enough to preview forms and views, evaluate rules, replay an automation on a past event and run tests. It never touches another tenant and never leaves anything behind.
- **Sandbox** (later): a sandbox tenant seeded from a sanitised copy of the record types (no personal data), for trying a large change end to end. This depends on a tenant clone that core doesn't have; until then the preview channel is the sandbox.
- **Tests** are part of the manifest (2.2). They run in the preview channel, on every draft change in the builder and again in core at publish, as the publish check (like the editor's E.9 publish check). A manifest whose tests fail can't be published. The AI writes tests along with changes; a person can add them by example ("this should have been refused").

### 2.8 What a no-code plugin can never do

Enforced by core's validator and runtime, so it holds whether a manifest comes from the builder, a script or a person's hand:

1. **Run code.** No PHP, JavaScript, SQL, templates that execute, or expressions outside the closed vocabulary.
2. **Ship front-end code.** UI is block trees only; no sandboxed frames, no scripts, no styles, no raw colours (semantic props only, as the block contract already requires).
3. **Reach outside its tenant.** Every record carries the tenant predicate; there is no system-tenant mode for a manifest plugin; it can't be installed install-wide by a tenant.
4. **Read or write what it wasn't given.** Its own records in full; people and OUs only through declared link fields and core's own read permission; other plugins' records only through actions and events they publish. Never core tables directly.
5. **Grant itself power.** Permissions only in its own namespace. It can suggest roles; assigning roles to people stays in core RBAC. It can't change settings, authentication, billing, tenancy or another plugin.
6. **Open public doors silently.** No unauthenticated routes except public form links (existing Forms engine) and signed inbound webhooks, both shown in the impact and both switchable off by the workspace (WHIT-704's operator approval applies).
7. **Call the network freely.** Only the webhook step, only to allow-listed hosts, signed, through the outbox, rate-limited, never to private, loopback or metadata addresses.
8. **Run without limits.** Caps per tenant, as entitlements with defaults: plugins, record types, fields, records, expression size, automation runs per day, steps per run, webhook calls, document issues. Over a cap is a clear refusal, never a silent drop.
9. **Change anything without a trace.** Every publish, rollback, run and refused rule is in the audit trail with the actor, who they acted for, and the version.
10. **Delete data by publishing.** Removing a field or record type hides it; purging is a separate, confirmed action after a retention period.
11. **Be published by an agent.** Agents draft and propose; people publish.

### 2.9 Export to a code plugin

Export turns a builder plugin into a repository a developer owns, with no change in behaviour:

- **What's generated:** `plugin.json` (the manifest), a thin `CakeOrdersPlugin.php` extending the SDK's `ManifestPlugin`, the document starters and themes as JSON (shapes from WHIT-738 and WHIT-742), the tests as PHPUnit cases calling the same runner, `composer.json` requiring `whity/plugin-sdk` only, and a README.
- **Two modes.** *Store-backed* keeps records in `plugin_records`, so nothing moves. *Table-backed* also generates migrations for real tables and a copy job, for plugins that outgrow the store.
- **Where it goes after.** An exported plugin is a code plugin: install-wide, on disk, deployed by an operator. Moving a tenant's live plugin to it is a deliberate step (install, migrate, switch off the tenant manifest), not automatic.
- **Hybrid from then on.** A developer can override any part in PHP (a hook callback, a custom route, a custom block) while the rest stays declarative, because the adapter and the PHP class are the same plugin.
- **Always available.** Export is how a customer leaves with their logic. It stays available when a subscription lapses (decided, 31 Oct 2026).

### 2.10 Who uses it

The first market is MENA SMEs, starting in Jordan. Everything the builder produces has English and Arabic labels (the validator refuses a missing one), mirrors in RTL, shows Western digits, and treats money as JOD with 3 decimals by default (`"currency": "JOD"` stores minor units of 1/1000).

- **A company's admin or owner**, non-technical, building their own logic, mostly by talking to their AI.
- **Whitiers** (teams building products on Whity) building logic for their own clients and reusing it: a builder plugin can be saved as a template and installed in other workspaces they manage (phase 4).
- **Developers**, who start in the builder for speed and export when they need code.

## 3. Open-core boundary

| | **Whity core (AGPL, free)** | **Whity Builder (closed, paid, hosted)** |
| --- | --- | --- |
| Format | Manifest JSON Schema, published with the SDK (MIT) | — |
| Validation | Validator, reference checks, limits | Live validation in the editor (calls core) |
| Runtime | Manifest adapter, tenant manifest host, record store, generic routes, expression evaluator (PHP and TS), rules, automation runner, step catalogue, preview channel, test runner | — |
| Versions | Published versions, active pointer, rollback, publish check, audit | Drafts, branches, history of drafts, comments, diffs in plain words |
| API | Manifest versions API (validate, diff, preview, test, publish, activate), with OpenAPI and derived MCP tools for the resulting plugins | Builder API and the `builder_*` MCP toolset |
| UI | Renders block screens; a plain "Plugins" admin listing versions and rollback | The visual builder: record types, forms, views, rules, automations, documents, roles, tests |
| AI | — (any MCP client can call the core API) | AI authoring: change operations, plain-language impact, test generation, explanations of traces; a built-in assistant and a builder MCP endpoint for the customer's own AI |
| Distribution | Install a manifest by API or by file | Template gallery, install across workspaces, export to a code plugin |
| Hosting | Runs in the customer's Whity | builder.whity.dev |

Whity Builder is one of the closed, paid services that plug into the free core natively. Each is optional configuration, never a hard dependency:

| Paid service | What it is | Core's open, standard side |
| --- | --- | --- |
| **Whity ID** | OIDC and identity, rebuilt from Nuqta | OAuth resource server (ADR 0015) |
| **Whity Pay** | Payments and subscriptions | Payment-webhook consumer, entitlements |
| **Whity Render** | PDF and document output | `DocumentIssuing` and the render protocol (WHIT-740, WHIT-741) |
| **Whity Builder** | No-code plugin building with AI | Manifest format, validator and runtime (this document) |

First-party apps such as Front Office (leads, quotes, JoFotara-compliant invoices) sit beside them. The builder uses the others rather than repeating them: Whity ID for signing in and connecting to a workspace, Whity Pay for its subscription, Whity Render behind the issue-document step. A builder plugin can link to Front Office records through actions and events Front Office publishes, but it doesn't issue tax invoices itself; e-invoicing stays with Front Office.

Principles:

- **Core runs logic with no builder attached.** Anything published keeps working if the builder is disconnected, down or no longer paid for. The builder is never a hard dependency, the same rule as Whity ID and Whity Pay.
- **Core owns the open, standard side.** A person or a script can write a manifest by hand and publish it through core's API without paying. That is the same freedom a PHP plugin already has. What's paid is making it easy and safe for people who can't write JSON: the UI, the AI, history, previews, tests, the gallery and export.
- **The builder holds logic, not business data.** It reads the tenant's schema and counts; previews and tests run inside core, which returns results and traces. Records don't leave the customer's Whity.
- **The service is the authority; core only gates with what it already has.** Core has no licence check for the builder: it would sit in AGPL code, an operator could remove it, and WHIT-667 freezes licensing in core. Instead:
  - The workspace connects the builder like any OAuth client: the builder signs in through Whity ID and receives a token for that workspace with the builder scope, granted by an admin who holds `plugins:build`.
  - The builder checks its own subscription (billed through Whity Pay) before serving any builder tool or screen. That check is authoritative.
  - Core reflects the subscription as an ordinary entitlement (proposed `whity-builder.access`), set by core's existing open side: the payment-webhook consumer when Whity Pay reports the subscription, or an operator. It only decides whether search shows the `whity-builder` toolset (`requiresEntitlement`). Removing it hides tools; it unlocks nothing, because the service still refuses an unpaid workspace.
  - Core enforces only what it always enforces: the token, its scope, the principal's permissions, and the runtime limits as entitlements with free defaults, which the hosted Whity cloud can raise by plan through the existing `EntitlementService`.
- **No hostage logic.** When a subscription ends, published plugins keep running, versions and rollback stay in core, and export stays available. Only editing in the builder stops.

### 3.1 Metering

The owner chose all four meters, each switchable per plan. They live in the builder service, because that's where the subscription is and what is being sold; core meters only its own runtime limits.

| Meter | Unit | Counted by | Source of truth |
| --- | --- | --- | --- |
| Workspace | a connected workspace per billing period | builder service, at connection | builder's connection records |
| Published plugin | a plugin with an active version in a workspace, per period | builder service, from core's versions API (WHIT-764) | core's `plugin_manifest_versions` |
| AI usage | model usage of the built-in assistant and AI-authored drafts, in tokens or credits | builder service, per call | builder's usage ledger |
| Seat | a person who builds (holds `plugins:build`) in a connected workspace, per period | builder service, from core's permission data over the connection | core RBAC |

Rules:

- **Per plan, each meter is off, included up to a quantity, or billed per unit.** The plan only says which; `[plan name]` and `[price]` stay open. Whity Pay holds plans, prices and invoices and receives usage as metered line items; the builder sends usage records, it doesn't price them.
- **Usage is read, never guessed.** Published plugins and seats are counted from core's own data over the workspace connection at the end of each period, so the bill matches what the workspace can see.
- **Meters stop building, never running.** Going over an included quantity can block new drafts or publishes, or bill the overage, as the plan says. It never switches off a published plugin (decision 3).
- **Bring your own AI isn't metered as AI usage.** A customer's own agent calling the `whity-builder` toolset costs us no model usage, so only the built-in assistant counts. It still counts toward published plugins and seats.
- **Self-hosted workspaces** (decision 5) are metered the same way over their paid connection.

## 4. Core prerequisites and how they map onto work in flight

| # | Prerequisite | Existing work it extends | New task | Order |
| --- | --- | --- | --- | --- |
| P1 | Manifest format and JSON Schema in the SDK; `ManifestPlugin` adapter for `plugin.json` on disk | WHIT-701 (block catalogue as JSON), WHIT-700 (publish the SDK), M7 plugin manifest, WHIT-272 (conformance kit) | WHIT-758 | 1 |
| P2 | Expression language: JSON tree, PHP and TS evaluators, shared fixtures | Block contract `visibleWhen` (#909) | WHIT-759 | 1 |
| P3 | Tenant manifest host: per-request resolution, version-hash cache, fixed entry points for routes, features, events, permissions and MCP tools | MCP tool-surface design (namespacing, filtered lists, splitter); `PluginEntitlements::seal()` rule | WHIT-760 | 2 |
| P4 | Record store, generic record routes, declared indexes, data type lifecycle with declared states | `PluginDataTypesInterface`, WHIT-585 (data-layer ergonomics), WHIT-421 (cursor pagination) | WHIT-761 | 2 |
| P5 | Rules on the save path (validations, computed fields, defaults, field locks) | Hook filters, `datatype.lifecycle.changing` | WHIT-762 | 3 |
| P6 | Automation runner and step catalogue, run traces, depth guard, acting-for principal | ADR 0013 outbox, job runner, WHIT-167 (event registry), WHIT-430 (automation contribution point), WHIT-586 (schedules), WHIT-302 (outbound webhooks), WHIT-741 and WHIT-745 (issuing) | WHIT-763 | 3 |
| P7 | Manifest versions API: validate, diff and impact, preview channel, test runner, publish check, activate, rollback, audit | Editor publish check (E.9) | WHIT-764 | 3 |
| P8 | Agent proposals and AI principals | Section 12 items 10 and 11 (editor M6) | already planned with M6; this design needs them before agents can propose publishing | 2 |
| P9 | Plugin-namespaced and three-segment permissions for tenant plugins | WHIT-739, MCP namespacing | covered by WHIT-739 and the MCP design | 2 |
| P10 | Trust boundary written down, including the manifest tier | WHIT-284, WHIT-278 (integrity; the builder signs what it publishes) | add to WHIT-284 | 1 |
| P11 | Runtime limits as entitlements with free defaults | `EntitlementRegistry`, `PluginEntitlements` | inside WHIT-760 and WHIT-763 | 3 |
| P12 | Public form links and inbound webhooks for manifest plugins | Forms engine, signed webhook routes (#1320), WHIT-704 | inside WHIT-763 | 4 |

Suggested order, so nothing waits on the builder and each step is useful on its own:

1. **Format first, on disk** (P1, P2, P10). Code plugins can already ship their declarative parts as `plugin.json`, which helps Elmak and M7 regardless of the builder.
2. **Make it per tenant** (P3, P4, P8, P9), after the MCP tool-surface design lands, since P3 extends its tool lists and namespaces.
3. **Make it behave** (P5, P6, P7, P11), after the document automation work (WHIT-741, WHIT-745) so the issue step has one service to call.
4. **Then the paid service** (section 5, phase B onward).

M4c and the editor milestones don't wait on any of this. The builder needs from them only what's already planned: template slots and issuing (WHIT-740's split), agent proposals (M6) and the plugin manifest at M7, which should be this manifest.

## 5. Phases

| Phase | Where | What | Done when |
| --- | --- | --- | --- |
| **A. Declarative core** | whity-core | P1-P11 (WHIT-758..764, plus WHIT-739, WHIT-746..753 and agent proposals) | A hand-written manifest for the cake-orders example publishes through the API, its form and views render, its rule refuses a low deposit, its automation issues a document in preview and live, it rolls back, and an MCP client sees its tools filtered by permission |
| **B. Builder MVP** (WHIT-766) | new private repo `whity-org/whity-builder` | Service skeleton, sign-in through Whity ID, subscription through Whity Pay, workspace connection (OAuth), drafts and history, the `builder_*` MCP toolset over core's API, plain-language impact, propose and publish with ActionProposal | A person's own AI builds the cake-orders plugin from the paragraph in section 2 and a manager publishes it |
| **C. Visual builder** (WHIT-767) | whity-builder | UI for record types, forms, views, rules, roles; built on `@whity-org/ui` and the design register; Arabic and RTL | A non-technical admin builds and changes the example without an AI |
| **D. Automations, documents, integrations** (WHIT-768) | whity-builder (and P12 in core) | Automation editor with trace viewer, document templates through the editor, webhooks, public forms, tests by example | The example's full automation is built, previewed, tested and published from the UI |
| **E. Export and templates** (WHIT-769) | whity-builder | Export to a code plugin (both modes), save as template, install across workspaces | An exported plugin passes the conformance kit and behaves the same as before |
| **F. Gallery** | after the WHIT-667 checkpoint | Shared templates between whitiers | Owner decision |

Phases A and B are the minimum that delivers the owner's sentence: users and their AI changing company logic on the fly over MCP.

## 6. Risks

- **Scope.** Phase A is several engineer-months of core work on a framework with one maintainer, under a freeze meant to stop exactly this growth. Mitigation: each prerequisite is useful to code plugins first (P1, P2, P4 reduce boilerplate in Elmak-style plugins), and the owner has ruled (WHIT-667, 31 Oct 2026) that it extends the plugin host, so it is in scope.
- **Two ways to do everything.** If manifest plugins grow features PHP plugins lack, or the other way round, the "one runtime" promise breaks. Mitigation: every manifest feature is a declaration a PHP plugin can make too, and the conformance kit runs both.
- **Performance of the record store.** JSON rows with declared indexes are slower than tables. Mitigation: indexes are declared and enforced, limits are entitlements, and table-backed export exists for the plugins that outgrow it.
- **Per-tenant MCP and OpenAPI.** Tools and the OpenAPI document are derived once per worker today. Tenant plugins make both per tenant. Mitigation: designed with the MCP tool-surface session (P3); the published `openapi.json` stays core-only.
- **AI changes that look right and aren't.** Mitigation: impact in plain words, previews on real data, tests required to publish, a person approves, rollback is one step.
- **Automation storms.** Mitigation: depth guard, caps, quotas, traces, and a per-plugin off switch.
- **Lock-in perception.** Mitigation: core runs everything, export is always available, a lapsed subscription changes nothing at runtime.

## 7. Owner's answers (31 Oct 2026) and open questions

| # | Question | Answer |
| --- | --- | --- |
| 1 | WHIT-667: is the declarative host a new generic subsystem? | **No, allowed.** P1-P7, the record store included, extend the plugin host and SDK. Recorded on WHIT-667. |
| 2 | Storage | **Shared record store** (option A, 2.3). |
| 3 | Lapsed subscriptions | Published plugins keep running and export stays open; only building and editing stop. |
| 4 | Agent autonomy | Agents may run previews and tests freely if the workspace allows. Publishing, including to a sandbox, is always a proposal a person approves until a sandbox tenant exists. |
| 5 | Self-hosted customers | **Yes**, as a paid connection to the hosted builder. |
| 6 | Expressions | Our own JSON-tree evaluator in the SDK, no new dependencies. |
| 7 | Name, repository, host | **Whity Builder** (no hyphen, like Whity ID, Whity Pay and Whity Render). Private repo `whity-org/whity-builder`, at builder.whity.dev. |
| 8 | One manifest | Yes: M7's plugin manifest and block registry are this manifest; code plugins are encouraged to use `plugin.json`. |
| 9 | Pricing meters | **All four**: per workspace, per published plugin, per AI usage, per seat, each switchable per plan (3.1). `[plan name]` and `[price]` stay open. |

Still open:

10. **How the `whity-builder` toolset reaches core's MCP catalogue.** Proposed: a small closed connector plugin (ADR 0014 exception) that contributes the toolset and forwards calls to the hosted service, installed when a workspace connects the builder. The alternative is a generic "remote toolset" mechanism in core, which is new core surface.
11. **Default undo windows** for builder writes (2.6): proposed 30 minutes for record changes, none for sent webhooks and issued documents.
