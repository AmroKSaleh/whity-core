<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * SDK identity (v1.27).
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
 * a plugin ignoring it is unaffected).
 * Breaking changes require a new major version.
 */
final class Sdk
{
    /** The SDK contract version shipped by this package. */
    public const VERSION = '1.27.0';

    /**
     * Static identity only — never instantiated.
     */
    private function __construct()
    {
    }
}
