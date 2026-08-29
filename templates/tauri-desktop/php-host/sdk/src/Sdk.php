<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * SDK identity (v1.40).
 *
 * {@see self::VERSION} is the version a host application evaluates plugin
 * SDK-constraints against ({@see PluginRequirementsInterface::getSdkConstraint()}).
 * It MUST match the `version` field in the package's composer.json — the host
 * test suite pins the two together so they cannot drift.
 *
 * Versioning policy (additive): new capabilities land in minor versions —
 * 1.0 (contract extraction) → 1.1 (requirements declaration, this class) →
 * 1.2 (frontend feature descriptor, {@see PluginFrontendInterface}, plus
 * host-enforced route-level `requiredPermission`) → 1.3 (tenant-isolation
 * conformance kit: {@see \Whity\Sdk\Tenant\TenantPredicateScanner},
 * {@see \Whity\Sdk\Tenant\MigrationTenantColumnLinter}, and the shared
 * {@see \Whity\Sdk\Testing\TenantIsolationConformanceTestCase} a plugin
 * extends to prove its tenant tables and queries are scoped) →
 * 1.4 (host CORE-version constraint declaration,
 * {@see PluginRequirementsInterface::getCoreConstraint()}, gated against the
 * host's core version independently of the SDK gate) →
 * 1.5 (multipart upload shapes: {@see \Whity\Sdk\Http\UploadedFile} and the
 * additive {@see \Whity\Sdk\Http\Request::getUploadedFiles()} upload bag, plus
 * the host-side {@see \Whity\Sdk\Http\MultipartParser}, WC-217) →
 * 1.6 (server-driven plugin-UI block contract: the platform-neutral
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract} whitelist and the
 * {@see \Whity\Sdk\Frontend\Blocks\BlockValidator}, plus the new
 * `screen: 'blocks'` frontend-feature value, WC-225) →
 * 1.7 (data-bound block types: `dataTable`, `dataStat`, `dataList` leaves with
 * the new `apiPath` prop-rule kind — strict relative API path validation, WC-229) →
 * 1.8 (interactive block types: `form` container, 9 input leaves — `textInput`,
 * `textArea`, `numberInput`, `select`, `checkbox`, `slider`, `dateInput`,
 * `fileInput`, `colorInput` — plus `submitButton` and `actionButton`; new
 * `inputName`/`selectOptions`/`submitSpec` prop-rule kinds; form-ancestor
 * enforcement and per-form duplicate-name detection in the validator, WC-233) →
 * 1.9 (MCP prompt contribution point: {@see PluginMcpInterface}, WC-7abb732f) →
 * 1.10 (`chart` data-bound block type — bar/line/area/pie backed by the same
 * `apiPath`-owned `source` trust boundary as `dataTable`/`dataStat`/`dataList`,
 * plus the new `chartSeriesList` prop-rule kind for its semantic
 * `{key, label, color: 1..5}` series set, WC-240) →
 * 1.11 (`dataTable`/`dataList` inline client-side sort/filter/pagination:
 * `dataTable.columns` upgraded to the new `dataColumnList` prop-rule kind
 * (adds optional per-column `sortable`/`filterable` booleans), plus a shared
 * optional `pageSize` on both leaves — purely additive, applies only to rows
 * already fetched from the block's single verified `source`, WC-241) →
 * 1.12 (optional theme-override contribution point:
 * {@see PluginThemeInterface}, letting a plugin contribute design-token CSS
 * variable overrides the host applies at render time. Same ownership model
 * as data-bound block sources — the declared route must be one this plugin
 * actually registered — and the host independently revalidates every
 * returned key/value before it ever reaches a `<style>` tag, WC-242) →
 * 1.13 (`screen: 'embed'` frontend-feature value — the host iframes a
 * plugin's own RBAC-protected GET route with zero host-application edits,
 * WC-246 — plus real multipart file uploads for `screen: 'action'` fields,
 * WC-247) →
 * 1.14 (notification transport contract: the pluggable
 * {@see \Whity\Sdk\Notification\NotificationTransport} channel contract plus
 * the {@see \Whity\Sdk\Notification\NotificationMessage} and
 * {@see \Whity\Sdk\Notification\SendResult} value objects — one contract for
 * email/SMS/in-app/push delivery. Core ships the transport registry + a
 * null/log transport; plugins contribute real ones, WC-notifications) →
 * 1.15 (hook VETO contract: {@see \Whity\Sdk\Hooks\HookVetoException}, the one
 * Throwable the host's per-plugin error boundary lets through. Paired with the
 * host now running `*.deleting` → DELETE → `*.deleted` inside one transaction
 * for tenants/OUs/roles, this lets a plugin refuse a deletion — or fail its own
 * cleanup — and have the deletion rolled back rather than silently committed,
 * WC-713) →
 * 1.16 (read-only permission resolution:
 * {@see \Whity\Sdk\Rbac\PermissionResolver}, the host-registered contract a
 * plugin resolves from the service container to ask the SAME authorization
 * question the host's RBAC middleware answers — membership gating, OU-ancestor
 * inheritance, role hierarchy, live delegations, catalogue validation — instead
 * of re-deriving it in hand-written SQL and drifting from what is actually
 * enforced, WC-712) →
 * 1.17 (resource-scoped resolution: `$resourceType`/`$resourceId` on
 * {@see \Whity\Sdk\Rbac\PermissionResolver}, so a plugin can ask "may this
 * caller act on THIS record?" — additive, omitting them preserves the previous
 * tenant-wide answer exactly, WC-712 §2) →
 * 1.18 (plugin-declared resource types:
 * {@see \Whity\Sdk\Rbac\PluginResourceTypesInterface}, the optional declaration
 * that lets a plugin address a role grant at one of its own records instead of
 * keeping a private grant table, WC-712 §2) →
 * 1.19 (status-page health probes: {@see \Whity\Sdk\Health\PluginHealthProbesInterface},
 * {@see \Whity\Sdk\Health\HealthProbeDefinition} and
 * {@see \Whity\Sdk\Health\ProbeResult} — a plugin contributes a probe for a
 * dependency it owns and the host samples and publishes it beside its own
 * database/queue/scheduler/render probes, instead of the plugin inventing a
 * second status surface nobody watches. Host-namespaced under the plugin name,
 * so probes cannot collide or shadow a core one) →
 * 1.20 (plugin-owned data types — Door 2 of the schema-driven admin surface:
 * {@see \Whity\Sdk\Tenant\PluginTablesInterface} declares WHICH tables a plugin
 * owns (the host stamps WHO owns them, from the plugin name the loader holds),
 * {@see \Whity\Sdk\DataType\PluginDataTypesInterface} declares a record's
 * lifecycle and its reference graph as DATA, and
 * {@see \Whity\Sdk\DataType\DataTypeGuard} exposes the host's own guard
 * evaluation so a plugin's custom delete route enforces through the same path
 * the generated one does. Trashed and retired are kept distinct: reversible-and
 * -pending-removal versus permanent-and-closed-to-new-references, WC-723) ->
 * 1.21 (plugin-declared SETTINGS: {@see \Whity\Sdk\Settings\PluginSettingsInterface},
 * by which a plugin contributes typed, validated, defaulted configuration keys to
 * the HOST'S own settings store instead of rebuilding one as a private table with
 * no declared keys and no validation. Host-namespaced under the plugin name the
 * loader supplies, resolved through the same per-tenant ?? global ?? default chain
 * as a core key, and published on the host's own settings screens only on an
 * explicit `admin => true` opt-in — those screens are gated on core permissions,
 * which are not the plugin's. Secret-shaped declarations are REFUSED rather than
 * downgraded to a readable string, #713 item 1) ->
 * 1.22 (RESOURCE-SCOPED ROLE checks: the optional `$resourceType`/`$resourceId`
 * pair 1.17 gave {@see \Whity\Sdk\Rbac\PermissionResolver::hasPermission()} is
 * now on {@see \Whity\Sdk\Rbac\PermissionResolver::hasRole()} too. Host role
 * resolution has honoured a resource scope since 1.17, but `hasRole()` asked the
 * tenant-wide question regardless — so a role granted at ONE record through
 * `resource_role_assignments` was resolvable and not askable, and reads as
 * needing a schema change to `memberships` that it does not need. Additive:
 * omitting them preserves 1.21 behaviour exactly, WC-712 §2) ->
 * 1.23 (PORTABLE SCHEMA PREDICATES: {@see \Whity\Sdk\Schema\SchemaInspector}
 * and the {@see \Whity\Sdk\Schema\MigrationSchema} trait that puts it on
 * `$this` inside a migration. The SDK asks every migration to be idempotent and
 * the host runs them on PostgreSQL and SQLite, but `ALTER TABLE … ADD COLUMN IF
 * NOT EXISTS` is a PostgreSQL extension SQLite rejects — so the moment a plugin
 * adds a column to a table it already shipped, the author has to hand-write a
 * driver-branching `tableExists()`/`columnExists()` over `information_schema`
 * and `PRAGMA table_info`. That pair has been written four times in one session
 * in a single adopter's codebase, identically each time, and a wrong answer
 * gates DDL: it passes on the engine the author develops against and fails on
 * the other one, at enable time, on somebody else's deployment. Written once
 * here, proven on both engines, and correct in two places the hand-written copy
 * is not — lookups are confined to the connection's own search path rather than
 * matching a same-named table in any schema, and read from `pg_catalog` rather
 * than the privilege-filtered `information_schema`.
 * `addColumnIfMissing()`/`dropColumnIfExists()` go one step further and remove
 * the branch entirely: the migration states the shape it wants instead of
 * asking a question and acting on the answer. Additive; no SQL is hidden behind
 * a builder, so the tenant-isolation guards still read every statement) ->
 * 1.24 (the lifecycle WRITE contract: {@see \Whity\Sdk\DataType\DataTypeLifecycle}
 * and the {@see \Whity\Sdk\DataType\LifecycleOutcome} it answers with. The host
 * told adopters to route their lifecycle writes through it and then published
 * only {@see \Whity\Sdk\DataType\DataTypeGuard} — read-only, deliberately, since
 * its whole guarantee is that holding it confers no authority. So a plugin that
 * needed to actually trash a record duck-typed a host-internal service: no
 * contract, no compatibility promise, no obligation to keep its shape. Reads
 * keep their guarantee — the guard is untouched and gains no mutators — and
 * writes get a second contract instead of being smuggled into the first.
 * The host binds it to the SAME object its generated endpoints authorize
 * through, so an in-process call cannot skip a check the endpoint enforces:
 * a type the caller may not read is UNKNOWN rather than forbidden, an action
 * the type does not offer is refused, and the action's declared permission is
 * resolved through the host's own checker. `$actorProfileId` is required for
 * exactly that reason — it is the subject of the check, not a decoration.
 * The outcome is the vocabulary the HTTP layer already publishes (`reason` as
 * the stable key, `message` as the fallback sentence, `blockers`, and the
 * status), so one branch serves both call paths. Bulk work is a LOOP over these
 * calls; a bulk statement bypasses every guard, veto and hook at once) ->
 * 1.25 (PORTABLE WRITES: {@see \Whity\Sdk\Sql\Upsert}, and
 * {@see \Whity\Sdk\Sql\SequenceAllocator} — the host-provided contract for
 * "hand me the next number, and never hand the same one twice".
 * `INSERT … ON CONFLICT … DO UPDATE … RETURNING` is the most repeated statement
 * shape in an adopting plugin — one codebase carries 58 — and each one
 * hand-builds four lists that must stay in step. `Upsert::tenantScoped()` builds
 * them, and takes the tenant id as a REQUIRED separate argument that it writes
 * into the value list AND prepends to the conflict target: an
 * `ON CONFLICT (client_uuid)` where the intent was
 * `ON CONFLICT (tenant_id, client_uuid)` is cross-tenant data loss written as an
 * ordinary create, and it is now unrepresentable. `Upsert::unscoped()` serves
 * declared-global tables, and its name is a declaration a reviewer can grep for.
 * `buildSql()` is public, so nothing is hidden: the exact statement is
 * inspectable, loggable and assertable.
 * SequenceAllocator goes further than a helper and deletes the SQL entirely — a
 * plugin that needs uniquely numbered records now declares nothing, migrates
 * nothing and writes no SQL; it asks the host for a number, and the host
 * allocates it in one statement with no read-then-write window for two clients
 * to both observe. Gaps are possible and documented; duplicates are not) ->
 * 1.26 (UNDECLARED-REFERENCE LINTING:
 * {@see \Whity\Sdk\Schema\UndeclaredReferenceLinter} and
 * {@see \Whity\Sdk\Schema\ReferenceDeclarations}. With no foreign keys between
 * plugin tables — the convention here, and the reason `blocks_delete` /
 * `cascade_delete` are declared as data at all — nothing at the database level
 * stops a delete orphaning a record's children. An adopter's schema had zero
 * foreign keys and deleting a parent silently left its children pointing at an
 * id that no longer resolved; the delete answered 200.
 * The linter flags a `<something>_id` column that points at a table that really
 * exists, carries NO foreign key, AND appears in NEITHER declaration list —
 * "you have a relationship core cannot see". It deliberately does NOT flag an
 * `*_id` column merely for lacking a foreign key: that rule fires on the
 * intended design of nearly every plugin table, is muted within a day, and
 * takes the credibility of the tenant linters with it. A schema with zero
 * foreign keys passes completely, provided its relationships are declared.
 * `tenant_id` is never flagged — that is a different invariant with its own two
 * linters — and a column naming no known table is not treated as a reference at
 * all. The escape hatch mirrors `@tenant-guard-ignore`:
 * `-- @reference-lint-ignore: <reason>` on the column, with the REASON
 * required, because a decision nobody wrote down is indistinguishable from a
 * muted alarm) ->
 * 1.27 ({@see \Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase}, the
 * offline-host conformance kit. {@see \Whity\Sdk\Testing\TenantIsolationConformanceTestCase}
 * proves a plugin's queries stay tenant-scoped; it says nothing about whether
 * the plugin actually BOOTS under a real offline PHP host with no server
 * framework behind it — no JWT/memberships/OU hierarchy, a single fixed
 * device role, and a deliberately narrow SQLite dialect shim (the shape the
 * Tauri desktop template's bundled FrankenPHP host runs plugins under). Every
 * real gap that shim surfaced (a migration using `SERIAL` that SQLite
 * silently mis-parses, an un-seeded `admin` role that left existing grant
 * migrations silently inert, a route requiring a permission its plugin never
 * declared) was found only by manually exercising a running host and
 * watching it fail. This kit catches that class of defect in a plugin
 * author's own CI, with no FrankenPHP process required: migrations apply
 * cleanly against the same dialect shim; declared permissions are
 * well-formed and every route's `requiredPermission` is one of them; a role
 * granted one permission holds exactly that one and nothing else; and every
 * declared hook runs cleanly on a synthetic payload — a generic `Throwable`
 * fails the test loudly, since the real host's per-plugin error boundary
 * would otherwise swallow it silently and ship the bug invisibly. Additive;
 * a plugin ignoring it is unaffected) ->
 * 1.28 ({@see PluginJobsInterface}, the async-job contribution point.
 * {@see JobInterface} has been public since 1.0 and the host's job registry has
 * always taken a handler, but nothing DISCOVERED a plugin's — so the shipped
 * `queue:work` worker knew only the core handlers and dead-lettered anything a
 * plugin enqueued as "No handler registered for job". A plugin's only remaining
 * option was to ship a `queue:work` of its own that re-registered the core
 * handlers beside its own, which means the operator runs one worker per plugin
 * and every one of them is a place core's own queued work can be duplicated or
 * dropped. Declared names are BARE and the host stamps the plugin's prefix onto
 * them, exactly as it does for resource types, health probes and settings keys:
 * two plugins declaring `sync` get different canonical names, and no
 * declaration can produce a `core.`-prefixed one. Submittability is declared
 * separately and fails CLOSED, matching core — a handler the worker can run is
 * not thereby reachable from the public submission API. A declaration that
 * throws or is malformed costs that plugin its jobs and costs the worker
 * nothing: it is the process that also delivers core's notifications and error
 * alerts, so one bad plugin must not stop it. Additive) ->
 * 1.29 ({@see PluginEventsInterface}, the audited-event contribution point,
 * plus {@see \Whity\Sdk\Hooks\Events::forPlugin()} and the now-published
 * namespacing rule {@see PluginNamespace}. The host's audit writer subscribed
 * to a HARDCODED map of core event names, so a plugin's own domain events
 * reached the platform audit trail never — an operator opening the one screen
 * that answers "who did what" saw core's mutations and a silence where every
 * plugin-side action had been. Both workarounds are worse than the gap: writing
 * to `audit_log` directly puts a second writer on a table whose entire design
 * is that it has one (no metadata sanitising, no tenant resolution, and nothing
 * stopping a row that claims to be `user.deleted`), and a private activity table
 * is a second audit surface in a second place nobody is looking at. A plugin now
 * declares which of its events belong in the trail and the host records them
 * through the SAME path core's go through. Names are declared BARE and the host
 * stamps the plugin's prefix onto BOTH halves of the record — action
 * `acme:task.completed`, target type `acme:task` — because an attributable
 * action beside a target type of `user` still reads as something core did to a
 * core record. The trigger is namespaced too, and that is the load-bearing part:
 * the hook manager tells a listener nothing about who dispatched, so listening
 * on the bare name would have written a row for EVERY plugin declaring
 * `task.completed` whenever any one of them fired it, and an audit trail that
 * records an event which did not happen is worse than one that records nothing.
 * A declaration that throws or is malformed costs that plugin its subscriptions
 * and costs core's own auditing nothing; the refusal is whole-declaration,
 * because a plugin with half its events audited ships a trail that looks
 * complete. The subscriptions are registered with the plugin's other hooks, so
 * disabling a plugin removes them and re-enabling it restores them. Additive) →
 * 1.30 ({@see \Whity\Sdk\Ou\PluginOuTypesInterface}, the organizational-unit
 * TYPE contribution point. An OU carried no kind, so the only thing a plugin
 * could filter its tree on was DEPTH — which names a different kind of unit on
 * every installation (a single-campus institution has its faculties at depth 0,
 * a multi-campus one at depth 1) and shifts the moment somebody inserts a
 * parent above an existing unit. Slugs are declared BARE and the host stamps
 * the plugin's prefix, so two plugins may both declare `clinic` and neither can
 * mint a bare key — the unprefixed namespace belongs to core and to the
 * tenant's own vocabulary. A declaration is a CATALOGUE ENTRY, not a write:
 * it makes a key adoptable, and an administrator adopts it into their tenant
 * explicitly, inheriting the declared label and rank as overridable defaults.
 * Force-seeding instead would have been a cross-tenant write driven by an
 * install-wide plugin. A malformed declaration costs that plugin the one type.
 * Additive) ->
 * 1.31 (WORKFLOW BLOCK TYPES: `timeline` and `inbox` join
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}, plus the `itemActionList`
 * prop-rule kind behind `inbox.actions`.
 * `timeline` is the easy half and was overdue: an ordered, append-only event
 * list — actor, action, timestamp, optional note, optional from/to — data-bound
 * to one ownership-checked `source` exactly as `dataStat` is. Every product on
 * the platform with an audit trail was hand-rolling it, so the same history
 * rendered differently on every screen. It declares no endpoint and no verb, so
 * read-only is a property of the contract rather than a convention.
 * `inbox` is the half with a seam, and where the seam falls is the whole design.
 * Core has no notion of a task queue, so the ITEMS come from the plugin's own
 * `source`. What core owns is which of the declared `actions` the caller may
 * take on each item — and it resolves that from the ROUTE the action calls, with
 * the same RoleChecker calls RbacMiddleware makes, through
 * `POST /api/v1/me/permitted-actions`. An action therefore does NOT declare the
 * permission its endpoint is gated on: that is not the plugin's to restate. A
 * restated slug is a second answer to a question the route table already
 * answers authoritatively, and it drifts the day someone re-gates the route and
 * updates one of the two places. Resolving from the route makes "what the user
 * is shown" and "what the middleware admits" the same computation rather than
 * two computations that agree for now.
 * `scopedPermission` is the one authorization fact a plugin CAN contribute,
 * because it is the one the route table cannot express: the per-record predicate
 * a handler applies inside the request, resolved at (`resourceType`, the item's
 * id) through the resource-scoped grants of 1.17/1.22. It is an ADDITIONAL
 * conjunct — the route gate is evaluated regardless — so it can only ever remove
 * an action from the permitted set, never add one. That direction is chosen
 * deliberately: a wrong declaration costs a user an affordance they had, and
 * cannot cost a tenant an authorization it relied on. Declaring it requires
 * `resourceType`, since a per-record check with no record silently becomes the
 * tenant-wide check wearing the wrong name.
 * The rejected alternative was to have the plugin's queue endpoint return the
 * permitted actions per item. It is less code in core and it is exactly the
 * failure the block vocabulary exists to prevent: every product re-deriving
 * authorization beside the host's, each one drifting from the middleware in its
 * own direction, and the drift only visible as a 403 after a click. Additive) ->
 * 1.32 (the ORGANIZATIONAL-UNIT SCOPE PICKER: `ouScopePicker` joins
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}, with the `ouScopeList` and
 * `ouTypeKey` prop-rule kinds behind its `scopes` and `anchorType`/`memberType`.
 * A form input whose value is a RULE over the OU tree rather than a pinned list
 * of ids: `{unit, scope, type}`, where `scope` is one of `unit`/`subtree`/
 * `children` and is ALWAYS written. That is the whole shape decision — "this
 * unit" and "this unit's subtree" are different answers, and a consumer must
 * never have to infer which was meant from the presence of some other field.
 * `type` is an OU type key from 1.30, filtering the set the scope resolves to;
 * pinning the ids that set contains today is the parallel unit-id → kind map
 * #822 exists to delete, and it goes stale the first time a unit is reparented,
 * silently.
 * The type declares NO `source`, and that is structural rather than a
 * convenience. Every `source` in this contract is ownership-checked by the
 * loader against the routes the declaring plugin registered, so a plugin cannot
 * name `/api/ous` at all — a `referenceSelect` aimed there drops the whole
 * feature, and the only way to satisfy the gate is to republish core's hierarchy
 * through a plugin route, which is exactly the drift being prevented. So the
 * host renderer reads the units and the vocabulary from CORE's own endpoints
 * under the caller's own `ous:read` gate: a caller who may not read the org
 * chart cannot build a rule over it, and a plugin has no prop with which to
 * point the control anywhere else.
 * `anchorType` and the value's `type` are deliberately separate: the first
 * restricts which unit may ANCHOR the rule, the second restricts the set it
 * resolves to, and "every department under a faculty" needs both. A `memberType`
 * declared beside a `scopes` list of exactly ['unit'] is refused — a kind filter
 * over the single unit the user just picked can only ever remove it. Additive) ->
 * 1.33 (RECORD BLOCKS: `dataRecord` and `recordFields` join
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}, with the `recordPath` and
 * `recordFactList` prop-rule kinds, plus optional `textFrom`/`valueFrom`/
 * `labelFrom`/`hintFrom` twins on `heading`/`text`/`badge`/`stat`.
 * Every data-bound leaf in the contract assumed a COLLECTION at `source`, so a
 * record page — the platform's standard for editing a record since #882 — could
 * not be DESCRIBED at all, only hand-written in React. `keyValue` took literals
 * baked into the declaration; `dataStat` yielded one scalar per block, so a
 * twelve-field record header was twelve blocks and twelve fetches. `dataRecord`
 * is the missing primitive: it fetches ONE resource, publishes it into the
 * master-detail context under its `id`, and owns the loading and failure states
 * for its whole subtree.
 * It is a CONTAINER rather than a leaf so it can be all three at once, and
 * publishing through the EXISTING context is what collapses the second gap into
 * the first. `defaultFrom` and `params.from` already address a row as
 * `{targetId}.{field}`; a record published under a `dataRecord`'s id is addressed
 * the same way by the same resolver, so no second mechanism was invented for the
 * page-level record. Its `source` is a `recordPath` — an owned apiPath that may
 * carry `{token}` segments in that same addressing — which is how the block says
 * WHICH record: from a `selector`, from the row an overlay was opened with, or
 * from `{record}`, the one reserved name a host seeds with the record its ROUTE
 * is about. A `selector` may not claim that name. The block does not fetch until
 * every token resolves, because a half-substituted path requests a different
 * resource than the one meant and would render it as the record.
 * `fields` IS THE #895 GUARD, and it is the reason for this shape rather than a
 * simpler one. #895: the roles record page derived "is this a global role" from
 * `manageable` — the server's answer to "may YOU write this?" — and for a
 * tenant-0 caller that is true of every role, so the system tenant, the one
 * caller whose edit reaches every tenant, saw a deployment-wide role labelled
 * "Your tenant's role". #897 made it unwriteable in TypeScript by splitting the
 * payload into what the record IS and what the caller MAY DO, and refusing a
 * fields type that carries a caller flag. A block tree is runtime data rather
 * than a type, so the same split is enforced twice here. Structurally: a
 * `dataRecord` publishes ONLY the fields its declaration names, so a payload's
 * `manageable`, `canEdit` or `mayModify` is unreachable from the tree because it
 * was never published — whatever it is called, and whether or not the author
 * considered it. By name: a declaration that names one of #897's eleven
 * caller-decision words as a fact, or binds one through a `...From` twin, is
 * REFUSED and drops the feature, so the author who tries is told which word and
 * why instead of watching it silently vanish. Matching is on a case- and
 * separator-folded form, since the same flag arrives as `canEdit` from one
 * serializer and `can_edit` or `is_editable` from another.
 * `defaultFrom` and `params.from` are deliberately NOT guarded: seeding a control
 * the server re-validates, or narrowing a fetch, is plumbing rather than a
 * statement about the record, and #897 draws the line in exactly the same place.
 * A guard that refused those would be refusing correct programs, and a guard that
 * refuses correct programs gets removed.
 * `recordFields` is the data-bound `keyValue` — a description list read from a
 * published record. It carries no labels of its own: those were declared once
 * beside the facts, because a record page shows the same field in more than one
 * place and a label restated per placement drifts per placement. Additive; every
 * tree that validated under 1.32 still validates, and the literal on each
 * `...From` twin stays required so a record page has a title before its record
 * arrives) ->
 * 1.34 (CONTEXT-AWARE `visibleWhen` and the `accessGate` container: a condition
 * that can read the RECORD and the CALLER'S ACCESS, plus the `accessCheck`
 * prop-rule kind and the first second child slot in the contract, `otherwise`.
 * #883 made a record page describable and, in doing so, found the half it could
 * not describe. `RecordPageShell` (#897) takes `main: {editor, readOnly}` — two
 * renderings, and it picks — while `visibleWhen` matched a sibling FORM FIELD
 * and nothing else. So a described record page rendered its editor
 * unconditionally and a caller who may not write got a disabled Save button
 * beside fully editable inputs: precisely the greyed-out form #882 exists to
 * make unshippable. Three of the shell's properties followed from that one
 * missing primitive — the read-only rendering, "which gate refused", and
 * conditional notices.
 * `visibleWhen` now names one of THREE subjects: `field` (a sibling input,
 * unchanged), `from` (a master-detail reference — the record the page is
 * about), or `access` (an `accessGate` id). It is also declared ONCE as a
 * universal facet rather than type by type, so every block carries it. A facet
 * only some types may carry is one that granular gating has to route around,
 * and "some parts have permissions, not always everything is allowed" is the
 * requirement this has to compose to.
 * `accessGate` declares one question about the CALLER, publishes the host's
 * answer under its `id`, and renders `children` when the answer is yes and
 * `otherwise` when it is no. WHO ANSWERS IT is the whole design: `check` names a
 * concrete `{method, endpoint}` request and the host resolves it through
 * `POST /api/v1/me/permitted-actions` — the same route lookup feeding the same
 * RoleChecker calls RbacMiddleware makes. A plugin therefore never states which
 * permission gates a region, exactly as an `inbox` action never states the
 * permission of the endpoint it calls, and there is no second copy of that
 * answer to drift when someone re-gates the route. A permission SLUG was the
 * shorter spelling and is deliberately not offered: `'permission' => 'acme:write'`
 * beside a route gated on `acme:manage` hides a control the caller could have
 * used, and nothing compares the two.
 * TWO SLOTS RATHER THAN TWO NEGATED CONDITIONS. The pair could be written as
 * siblings with opposite `visibleWhen` polarity, and that is the shape to avoid:
 * conditions meant to be each other's negation drift, and when they drift it is
 * the editable half that ends up showing. Declared as one node they cannot
 * disagree — the same reason the shell takes both renderings as required props.
 * hidden / read-only / editable composes as two NESTED gates: the outer on the
 * read request with no `otherwise` (refused ⇒ absent), the inner on the write
 * request with both. Nesting also yields #897's "first refusal wins"
 * structurally, since an outer gate that refuses never renders the inner one.
 * READING ACCESS DOES NOT RE-OPEN #895. #908 established that FACT bindings are
 * guarded and CONTROL bindings are not; this is a control binding, and the seam
 * is kept by construction rather than by rule. A gate's answer is published into
 * a namespace of its own, which `resolveContextRef` — the single resolver behind
 * `textFrom`/`valueFrom`/`labelFrom`/`hintFrom` and behind `defaultFrom`/
 * `params.from`/source tokens — does not read. `visibleWhen.access` is the only
 * prop in the contract whose value names a gate, and all it can do is decide
 * whether a subtree renders. So a page can ACT on what the caller may do and
 * still cannot SAY it about the record.
 * GET joined the methods `permitted-actions` accepts, because "may I see this at
 * all?" is a read and is the only way the hidden state has an authority to ask.
 * The endpoint's identity — allowed implies the middleware would admit exactly
 * this request — is method-agnostic, so it promises no less than before.
 * `otherwise` is the contract's first second child slot, and the slots are now
 * DECLARED ({@see \Whity\Sdk\Frontend\Blocks\BlockContract::childSlots()})
 * rather than assumed, because several things walk a block tree and a walker
 * that hard-codes `children` silently skips a slot — for the host loader that
 * is a `source` that never got ownership-checked. Additive: every tree that
 * validated under 1.33 still validates) ->
 * 1.35 (THE GRAPH BLOCK: `flow` joins
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}, with a declared node ceiling
 * ({@see \Whity\Sdk\Frontend\Blocks\BlockContract::FLOW_MAX_NODES}) and no new
 * prop-rule kind.
 * The whitelist covered tables, lists, timelines, inboxes, charts, forms,
 * overlays and master-detail, and not one of its types could render a set of
 * nodes and the edges between them. So anything modelling an ordered or
 * branching process — a chain of steps, a state machine, a dependency graph —
 * could be EDITED natively (`form`, `fieldArray`) and LISTED natively (`table`),
 * while the DIAGRAM, which for that class of feature is usually the thing that
 * makes it legible to a non-technical reader, was the one part every product had
 * to ship as bespoke React. This is not a new renderer: core already runs
 * `@xyflow/react` behind two screens, and both are the same composition the
 * block reuses.
 * ONE FETCH, AND ROWS THAT ARE NODES. `source` is an ordinary ownership-checked
 * apiPath returning a collection, exactly as `dataTable`'s is, and a row IS a
 * node. Edges are references off the node rows to OTHER nodes' ids —
 * `edgeFromField` a predecessor pointer, `edgeToField` a successor pointer,
 * either optionally holding a LIST so a step can branch — and with neither
 * declared the nodes are a linear sequence in payload order, which costs an
 * ordered process no edge modelling at all. A second source for edges was the
 * obvious alternative and is deliberately not offered: it doubles the ownership
 * checks and the loading states, and puts a join in the renderer that the
 * plugin already made when it wrote the rows. A reference to an id no row
 * declared is dropped rather than materialised, since a box the plugin never
 * described, labelled with a raw id, is not information.
 * READ-ONLY BY CONSTRUCTION, like `timeline`: no endpoint, no verb, nothing to
 * submit. Editing is a form opened FROM a node, and the affordance is
 * `nodeActions` of the SAME `rowActionList` kind `dataTable.rowActions` already
 * uses — same validator, same renderer path — because a node's actions are a
 * row's actions and a second spelling of one shape is two shapes that drift.
 * Clicking a node runs its first `open` action; a diagram whose only affordance
 * is a dropdown is one nobody clicks.
 * THE NODE CEILING IS THE POINT OF DECLARING IT. #192 is open against the two
 * existing graph screens because a canvas of several hundred boxes, most with no
 * edges, has stopped conveying anything — and any block on the same renderer
 * inherits that the moment a tenant's data grows. Carrying it silently would
 * mean finding it in a plugin's production deployment, so the limit is in the
 * contract and the behaviour above it is defined: draw the first `maxNodes` in
 * payload order with the edges among them, and SAY on screen that the graph was
 * truncated, because a partial graph that looks complete is worse than a tangle.
 * `maxNodes` may LOWER the ceiling and not raise it: a plugin knows its labels
 * are long or its target is a phone, which is knowledge worth applying, but it
 * cannot know that 400 nodes are legible on every platform this tree may render
 * on. Additive; every tree that validated under 1.34 still validates) ->
 * 1.36 (THE DOCUMENT VIEWER: `documentViewer` joins
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}, declaring no `source` and no
 * new prop-rule kind.
 * #947 item 1 gave core an issued document — a record with an identity and one
 * immutable artifact per render — and nothing in this contract could show one.
 * A plugin whose record has an issued work order could link out to it at best,
 * and a link out of a record is where the reader stops reading the record.
 * COMPOSING WAS NOT AVAILABLE, and not for want of pieces. Every `source` and
 * `recordPath` in this contract is ownership-checked against the routes the
 * DECLARING plugin registered, so core's `/api/v1/documents/{id}` cannot be
 * named by any plugin; the only composition that would work is a plugin
 * republishing core's document reads through a route of its own, gated however
 * that plugin chose. `ouScopePicker` already refused that trade for the OU tree
 * and the refusal is worth more here, because the thing being republished is an
 * audit record. So this type follows the same shape: no `source` prop at all,
 * the host fetching core's own endpoints under the caller's session and the
 * `documents:read` gate they already carry, and no prop with which to point it
 * anywhere else.
 * WHICH DOCUMENT is a `contextPath` (`documentIdFrom`) and there is deliberately
 * NO literal twin. The `heading`/`text`/`badge`/`stat` rule — literal required,
 * `...From` additive — exists because an unresolved binding should still render
 * A title, a duller version of the right answer. Inverted here the fallback is
 * not duller, it is A DIFFERENT DOCUMENT rendered with full confidence, so
 * nothing renders until something has said which one and `emptyText` is what an
 * author says in the meantime.
 * WHICH ARTIFACT is the question a re-render creates, and the block answers it
 * two ways. `artifactIdFrom` PINS one — the binding an append-only trail needs,
 * so an event saying "this is what circulated on the 4th" can show what
 * circulated on the 4th rather than what the document says today. Absent, the
 * viewer shows the CURRENT artifact and says so on screen, with the count of the
 * others and the way to reach them. Showing the newest SILENTLY was the
 * alternative and is the one thing a viewer of an auditable record must not do:
 * a corrected document then reads as though it had always said that. The
 * renderer never falls back between the two — a pinned artifact that is not on
 * the record is an explicit failure state, because quietly substituting the
 * current one answers a question about the past with a fact about the present.
 * NOT OFFERED, deliberately: a prop to hide the version history (a plugin
 * deciding what a reader of an audit record may know is not a presentation
 * choice), a prop to suppress the download (the bytes were already served), and
 * any height/zoom prop (presentational, and the page's own aspect ratio is a
 * fact the renderer can read). Additive; every tree that validated under 1.35
 * still validates) ->
 * 1.37 (DOCUMENT ROUTING RULES: {@see \Whity\Sdk\Routing\PluginRoutingRulesInterface}
 * joins the optional capability interfaces, with
 * {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface},
 * {@see \Whity\Sdk\Routing\RoutingRuleContext} and
 * {@see \Whity\Sdk\Routing\ResolvedRecipient} as its contract.
 * #947 item 3 puts a document routing engine in core, and the load-bearing
 * decision is that a route STEP NAMES A RULE, NEVER A PERSON: the rule is
 * resolved at send time, against the organisation as it stands then, so a unit
 * created last week is included because it exists rather than because somebody
 * remembered to add it. A stored recipient list omits it, the document still
 * renders, every step still completes and the run reports SUCCESS — which is why
 * the rule is the contract and the list is not offered.
 * Core owns two kinds, `role` and `role_below_actor`, and both are generic in
 * the strong sense: every deployment has roles and every deployment has a unit
 * tree. Anything narrower is what one organisation happens to mean by "the next
 * people" — a `supervisor` means three different things in three deployments —
 * so it arrives through this interface instead of being guessed at in core.
 * A RESOLVER RETURNS SUGGESTIONS AND WRITES NOTHING. It answers "who, and via
 * which unit", and the host filters every profile against the ACTIVE MEMBERSHIPS
 * of the tenant owning the route before a single inbox row exists — so a
 * resolver, correct or buggy, cannot place a document in another tenant's inbox,
 * and that check is not a rule every plugin author has to remember.
 * `validate()` runs at authoring time and its message reaches the person
 * composing the route verbatim; a throw from `resolve()` is plugin code
 * misbehaving and its text is logged rather than shown.
 * BARE SLUGS ONLY: declare `committee`, get `acme:committee`. Two plugins may
 * both declare `committee` without colliding, and no plugin can mint a bare kind
 * — so a step already stored naming `role` cannot be re-pointed by installing a
 * plugin, which matters more here than in most catalogues because the step
 * decides who receives a document.
 * Additive; every tree that validated under 1.36 still validates) ->
 * 1.38 (THE RULE VOCABULARY, GENERALISED OUT OF ROUTING:
 * {@see \Whity\Sdk\Audience\AudienceRuleContext} and
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface}.
 * #999 adds NAMED USER GROUPS, and a group is not a membership table — it is
 * one of these same rule expressions given a name and stored once, so that
 * "the instructors" is ONE node referenced from many places rather than a
 * thousand rows re-listed per place. A stored list goes stale the first time
 * somebody is hired: it omits them, still renders, and still reports success.
 * A rule includes them because they exist.
 * The generalisation is a SUBTYPE, not a widening.
 * `RoutingRuleContext extends AudienceRuleContext`, so every property a 1.37
 * resolver reads is still present with the same type — nothing became nullable —
 * and every call site still compiles. A resolver that never touches the
 * document, the route or the step declares `resolve(AudienceRuleContext)`,
 * which satisfies BOTH interfaces from one body, and its kind then becomes
 * usable as a group definition.
 * The two alternatives were rejected for the same reason: making the routing
 * fields nullable changes the type under plugin code already shipped, and
 * passing zeros in them is a lie the type system would endorse.
 * ONE REGISTRY STILL. {@see \Whity\Sdk\Routing\PluginRoutingRulesInterface} is
 * unchanged and remains the only way to contribute a kind; there is no
 * audience-only declaration, because a rule that can name a set of people can
 * name the recipients of a step.
 * Additive; every tree that validated under 1.37 still validates) ->
 * 1.39 (TIME-WINDOW TYPES: {@see \Whity\Sdk\TimeWindow\PluginWindowTypesInterface},
 * the contribution point for the KINDS of named period a deployment slices time
 * into. #1070 puts a named, non-overlapping period that records can be scoped to
 * and rolled up by — and that can be CLOSED, the way a set of books is closed —
 * into core, because it is a primitive the platform did not have and everybody
 * who needed one either built their own or did without. Two implementations of a
 * period disagree the moment both exist, and nothing reports that they differ.
 * THE VOCABULARY IS THE PART THAT CANNOT BE CORE'S. Two deployments slice time
 * into words with nothing in common — one reasons in a crop year and the growing
 * seasons inside it, another in a kiln campaign and the firing runs inside it —
 * so a core enumeration would have to carry both and ship each deployment the
 * other's. A plugin therefore declares KEYS and the defaults a tenant starts
 * from, and an administrator ADOPTS one; declaring is a catalogue entry, never a
 * write into anybody's tenant.
 * BARE SLUGS ONLY, as with OU types: declare `growing_season`, get
 * `acme:growing_season`. Two plugins may declare the same slug without
 * colliding, and no plugin can mint a bare key, because the unprefixed namespace
 * belongs to core and to the tenant's own vocabulary.
 * NESTING IS DECLARABLE, BOUNDARIES ARE NOT. A declaration may name the type it
 * nests inside — a sub-period sitting inside a period is structural and knowable
 * — but it says nothing about when a period starts, how long it runs, or what
 * fraction of its parent it occupies, because none of that is knowable and
 * assuming it is the specific error this concept exists to avoid. Every boundary
 * is authored per instance, in dates, by somebody who knows. A plugin may only
 * nest inside its OWN declared types: it does not own another source's type and
 * cannot know whether a given tenant adopted it.
 * A malformed declaration costs that plugin its window vocabulary rather than one
 * type — the one place this differs from the OU-type catalogue, and the nesting
 * is why: the declarations are interdependent, so storing them one at a time
 * would either reject a legal forward reference or leave half a hierarchy whose
 * parents point at nothing.
 * NO CONTRACT IS PUBLISHED HERE FOR THE CLOSE REPORT. What a close should say is
 * still unfinished inside a period is contributed through the
 * `time_window.close_report` FILTER HOOK rather than a typed interface,
 * deliberately: the shape such an interface should take depends on an open
 * question this release does not answer — what happens to a record mid-flight
 * when its period closes — and publishing a vendored, version-pinned contract
 * before that is publishing one that then has to break.
 * Additive; every tree that validated under 1.38 still validates) ->
 * 1.40 (THE FORM PRELOAD JOINS THE CONTRACT: `form.dataSource`, a
 * `{method: 'GET', path}` spec the renderer has honoured since #949 and which
 * the contract never declared.
 *
 * NOT PURELY ADDITIVE, and the exception is the point. An undeclared prop is
 * neither validated nor stripped — `BlockValidator::validateProps()` walks the
 * DECLARED rules rather than the node's keys, and the loader's walk returns the
 * node it was handed — so `dataSource` reached the client exactly as written,
 * and its path was never checked against the routes the plugin registered.
 * Alone among every endpoint a block can name: `submit`, every `source`,
 * `inbox.actions` and every `rowActionList` were all ownership-checked.
 *
 * So a tree that validated under 1.39 can be REFUSED under 1.40 — but only if
 * its form preloaded a route the plugin does not own, or wrote a `dataSource`
 * of the wrong shape. A declaration naming the plugin's own GET is unaffected,
 * and now gets the version rewrite every other endpoint already got, which
 * fixes the class of preload failure #957 traced to an unversioned path.
 *
 * A minor rather than a major: nothing legitimate stops working, and the trees
 * this refuses are the ones the ownership rule always claimed to cover.)
 * Breaking changes require a new major version.
 */
final class Sdk
{
    /** The SDK contract version shipped by this package. */
    public const VERSION = '1.40.0';

    /**
     * Static identity only — never instantiated.
     */
    private function __construct()
    {
    }
}
