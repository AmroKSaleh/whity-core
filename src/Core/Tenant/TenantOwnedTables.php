<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Single source of truth for the platform's TENANT-OWNED tables (WC-192).
 *
 * A tenant-owned table carries a `tenant_id` column and holds rows that belong
 * to exactly one tenant. The platform's #1 isolation invariant is "every
 * SELECT/UPDATE/DELETE on a tenant-owned table binds a `tenant_id` predicate"
 * (see docs/wiki/TENANT_ISOLATION.md). The CI tenant-predicate guard
 * ({@see \Whity\Core\Tenant\TenantPredicateGuard}, wired through
 * scripts/ci-tenant-predicate-guard.php) consumes THIS list to know which
 * tables it must police, and {@see SanctionedGlobalTables} to know which tables
 * are exempt.
 *
 * The set is DERIVED from database/migrations/ — every table whose CREATE TABLE
 * declares a `tenant_id` column. It is pinned here (and by
 * TenantOwnedTablesTest, which re-derives it from the migrations) so the guard
 * cannot silently drift from the schema: add a tenant-owned table in a migration
 * and the test fails until this list is updated.
 *
 * NOT in this list (deliberately):
 *  - `tenants` — the tenant registry itself; its primary key IS the tenant id,
 *    so a `tenant_id` predicate would be meaningless.
 *  - `permissions`, `relationship_types` — platform-global catalogues/vocabulary
 *    with no `tenant_id` column.
 *  - `role_permissions`, `backup_codes` — they carry NO `tenant_id` column and
 *    scope TRANSITIVELY via a parent (`role_permissions` via `roles`,
 *    `backup_codes` via `profiles.profile_id` after migration 038). They are not
 *    directly scannable for a `tenant_id` predicate; isolation for them is enforced
 *    at the parent join / by the owning profile id, not by a column on the row.
 *    Listing them as tenant-owned would force false positives on every correct
 *    `WHERE role_id = ?` / `` access, so they are intentionally excluded and
 *    covered by their parent's scoping instead.
 *  - `revoked_tokens`, `core_schema_migrations` — sanctioned global tables
 *    enumerated in {@see SanctionedGlobalTables}.
 */
final class TenantOwnedTables
{
    /**
     * Tables that carry a `tenant_id` column and are therefore tenant-owned.
     *
     * Keyed by table name with the migration that introduces its `tenant_id`
     * column, so the provenance of every entry is auditable. Re-derived from the
     * migrations by TenantOwnedTablesTest.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'roles' => '001_create_users_roles.php',
        'deployments' => '004_create_deployment_tables.php',
        'migration_rollbacks' => '004_create_deployment_tables.php',
        'organizational_units' => '005_create_organizational_units.php',
        'ou_role_assignments' => '008_create_ou_role_assignments.php',
        'resource_role_assignments' => '088_create_resource_role_assignments.php',
        'permission_delegations' => '014_create_permission_delegations.php',
        'audit_log' => '016_create_audit_log.php',
        'persons' => '018_create_persons.php',
        'relations' => '020_create_relations.php',
        'tenant_settings' => '025_create_tenant_settings.php',

        // ADR 0005 (Phase B) — explicit profile-to-tenant binding (migration 030).
        // Replaces the implicit `users.tenant_id` FK with a lifecycle-managed row
        // (status: active | invited | suspended). Tenant-scoped so the predicate
        // guard must police every SELECT/UPDATE/DELETE against it.
        'memberships' => '030_create_memberships.php',

        // ADR 0005 (Phase B) — tenant email-domain ownership registry (migration 031).
        // When a profile verifies an email on a registered domain the policy service
        // auto-provisions or auto-approves the corresponding membership.
        'tenant_email_domains' => '031_create_tenant_email_domains.php',

        // WC-2686308f (Phase C) — MCP AI-principal token registry (migration 033).
        // Long-lived machine/service tokens scoped to a tenant; tenant isolation
        // is enforced on listing and revocation by binding profile_id + tenant_id
        // (re-keyed from user_id by migration 040).
        'mcp_tokens' => '033_create_mcp_tokens.php',

        // WC-b-device-tokens (Phase B) — registered-device credential registry
        // (migration 044). Native-client enrollments scoped to profile_id +
        // tenant_id; list/revoke bind both, so the predicate guard must police it.
        'devices' => '044_create_devices.php',

        // WC-f-sessions-table (Phase F) — interactive login-session registry
        // (migration 045). Scoped to profile_id + tenant_id; list/revoke bind
        // both, so the predicate guard must police every query against it.
        'sessions' => '045_create_sessions.php',

        // WC-e6287 (Phase F) — per-tenant identity-provider (SSO/OIDC) registry
        // (migration 048). Each tenant configures its own providers; every query
        // binds tenant_id so a tenant can only see/manage its own configs.
        'identity_providers' => '048_create_identity_providers.php',

        // WC-ent — operator-granted per-tenant entitlements (migration 051):
        // capabilities/limits the platform owner sets per tenant to sell tiers.
        // The operator API writes cross-tenant (system gated) but still binds the
        // target tenant_id on every statement, so the predicate guard polices it.
        'tenant_entitlements' => '051_create_tenant_entitlements.php',

        // WC-storage — per-tenant storage backend config (migration 053): an
        // S3-compatible bucket a tenant owns, used only when it also holds the
        // storage.custom_backend entitlement. Every query binds tenant_id.
        'tenant_storage_config' => '053_create_tenant_storage_config.php',

        // WC-plans — which subscription plan a tenant is on (migration 055).
        // tenant_id is the PK; applying a plan materialises its entitlement
        // bundle into tenant_entitlements (ADR 0010). Every query binds tenant_id.
        // (plans / plan_entitlements are global catalogs with no tenant_id,
        // unregistered like `permissions`.)
        'tenant_plan' => '055_create_plans.php',

        // WC-docdesigner — document/label designer persistence (migration 059).
        // Saved templates and reusable blocks; the client object is stored as JSON
        // in `data`. Tenant-scoped + RBAC-gated visibility; every query binds
        // tenant_id (list/get additionally RBAC-filter server-side).
        'document_templates' => '059_create_document_designer_tables.php',
        'document_blocks'    => '059_create_document_designer_tables.php',

        // #947 item 1 — issued documents and their stored render output
        // (migration 108). `documents` is the record; `document_artifacts` is
        // the append-only set of immutable rendered files, with the tenant
        // DENORMALISED from the parent document so the predicate guard polices
        // an artifact read directly instead of trusting a join — the same trade
        // `notification_deliveries`, `event_outbox` and `entity_tags` above
        // already make. Every read and write on both binds tenant_id, and the
        // handler additionally row-filters by
        // {@see \Whity\Core\Document\DocumentVisibilityPolicy} (creator, or the
        // tenant-wide documents:read:all grant).
        'documents'          => '108_create_documents.php',
        'document_artifacts' => '108_create_documents.php',

        // #947 item 3 — the document ROUTING engine (migration 112). Four
        // tables, all tenant-owned with an explicit `tenant_id` rather than one
        // inferred through the document: the predicate guard has to be able to
        // police an inbox read and a trail read DIRECTLY, and the inbox query
        // ("my open rows") never joins `documents` at all in its count form.
        // Same trade `notification_deliveries`, `entity_tags` and `event_outbox`
        // above already make.
        //
        // `document_route_events` is the APPEND-ONLY trail
        // ({@see \Whity\Core\Document\Routing\RouteEventRepository} has no
        // update and no delete path), and `document_route_recipients` is the
        // inbox — registered as an #881 source rather than owning a surface of
        // its own.
        'document_routes'            => '112_create_document_routing.php',
        'document_route_steps'       => '112_create_document_routing.php',
        'document_route_events'      => '112_create_document_routing.php',
        'document_route_recipients'  => '112_create_document_routing.php',

        // #1014 — where a VERDICT sends a document (migration 119). The
        // branching seam migration 112 named and deliberately left unbuilt, keyed
        // by the verdict because that is the condition an editor can draw.
        // Tenant-owned with an explicit `tenant_id` like its four siblings, so
        // the predicate guard polices an edge read directly rather than through
        // a join to the route.
        'document_route_edges'       => '119_add_route_verdicts_and_branching.php',

        // #1027 — reusable, BRANCHING route TEMPLATES (migration 118): the record
        // a node-based flow editor edits, and the two tables that hang off it.
        //
        // The design and the circulation are different records with different
        // lifetimes, exactly as `document_templates` is a different record from
        // `documents` — so these are three new tables rather than a nullable
        // column on the four above.
        //
        // A template step carries `rule_kind` + `rule_config` and, like
        // `document_route_steps`, has NOWHERE TO PUT A PERSON. That is what makes
        // "one node for a thousand instructors" a property of the schema rather
        // than a convention the editor is trusted to keep: a design authored in
        // March and instantiated in November reaches whoever holds the role in
        // November, because there is no roster to go stale.
        //
        // All three carry `tenant_id` NOT NULL and denormalise it onto the
        // children rather than reaching it through `template_id`, so the
        // predicate guard polices a step and an edge read DIRECTLY instead of
        // trusting a join it cannot see.
        'document_route_templates'      => '120_create_document_route_templates.php',
        'document_route_template_steps' => '120_create_document_route_templates.php',
        'document_route_template_edges' => '120_create_document_route_templates.php',

        // #999 — named USER GROUPS (migration 116). One row per group: a name
        // plus the `rule_kind` + `rule_config` pair that says which people it
        // contains. There is deliberately NO `user_group_members` table beside
        // it: a stored membership list omits the instructor hired last week,
        // still renders, and still reports success, which is the same argument
        // `document_route_steps` above relies on for having nowhere to put a
        // person.
        //
        // Tenant-owned with an explicit `tenant_id` rather than one inferred
        // through anything, so the predicate guard polices a group read
        // DIRECTLY. Every read and write binds it, and a group id from another
        // tenant is reported as absent rather than forbidden — group ids are
        // enumerable integers.
        'user_groups'                => '116_create_user_groups.php',

        // WC-525 — admin-enforced 2FA policy registry (migration 061): tenant/OU/
        // user-scoped rows an admin sets to require 2FA enrollment. Every query
        // binds tenant_id so a policy can never leak across tenants.
        'two_factor_policies' => '061_create_two_factor_policies.php',

        // WC-621 — native taxonomy/tagging subsystem (migration 063): tag groups,
        // tags, and polymorphic entity<->tag associations. All three carry
        // tenant_id NOT NULL; every query binds tenant_id so tags never leak
        // across tenants. `entity_tags.tenant_id` is denormalised from the tag's
        // tenant to keep the predicate + reverse-lookup index on one row.
        'tag_groups'  => '063_create_taxonomy_tables.php',
        'tags'        => '063_create_taxonomy_tables.php',
        'entity_tags' => '063_create_taxonomy_tables.php',

        // WC-queue — durable async job queue (migration 065). Each job carries
        // the tenant_id that enqueued it, restored into TenantContext before its
        // handler runs. The QUEUE mechanics (reserve/complete/fail/reclaim) run
        // as system infra across tenants and are annotated @tenant-guard-ignore
        // in JobRepository; the tenant_id column is what makes that restore, and
        // an eventual tenant-scoped jobs API, correct.
        'jobs' => '065_create_jobs.php',

        // WC-event-spine (#154) — durable event spine (migration 066). Both
        // tables carry tenant_id NOT NULL: `domain_events` is the append-only
        // per-tenant log; `event_outbox` denormalises the event's tenant so the
        // relay can scope/keep tenant on one row. The RELAY runs as system infra
        // ACROSS tenants (reserve/mark/reclaim annotated @tenant-guard-ignore in
        // DomainEventStore); append stamps tenant_id from the trusted caller, and
        // each relayed event's origin tenant is restored into TenantContext
        // before any tenant-scoped handler runs — the same model as `jobs`.
        'domain_events' => '066_create_domain_events.php',
        'event_outbox'  => '066_create_domain_events.php',

        // WC-scheduler (#a934420e) — the cron-tick scheduled_jobs registry
        // (migration 069). Tenant-scoped CRUD binds tenant_id; the schedule:run
        // tick claims due rows ACROSS tenants (system infra, @tenant-guard-ignore
        // in ScheduledJobRepository) and stamps each enqueue with the row's
        // origin tenant — the same model as `jobs`.
        'scheduled_jobs' => '069_create_scheduled_jobs.php',

        // WC-notifications (#d89dcc2c) — the notification persistence spine
        // (migration 070). `notifications` is the tenant-scoped message/inbox row;
        // `notification_deliveries` is its per-channel attempt log, with the
        // tenant DENORMALISED from the parent notification so the sweep index and
        // predicate guard sit on one row (the same pattern as `event_outbox` /
        // `entity_tags`). Tenant-scoped reads/writes bind tenant_id in
        // NotificationRepository; the eventual relay sweep runs as system infra
        // across tenants (annotated @tenant-guard-ignore in its own slice).
        'notifications'           => '070_create_notifications.php',
        'notification_deliveries' => '070_create_notifications.php',

        // WC-notifications (#c56a6455) — per-user notification preferences
        // (migration 071). One (tenant, profile, type, channel) toggle; every
        // read/write binds tenant_id (+ profile_id for self-scoping). The
        // dispatcher's NotificationPreferenceResolver consults it to filter a
        // recipient's channels (transactional types always bypass).
        'user_notification_preferences' => '071_create_user_notification_preferences.php',

        // WC-notifications (#2aa3411a) — notification templates (migration 072).
        // tenant_id 0 = the global default core set; > 0 = a tenant override.
        // Resolution binds tenant_id (a caller reads its own overrides + the
        // global 0 set); a regular tenant writes only its own rows — the same
        // global-vs-tenant asymmetry as base roles.
        'notification_templates' => '072_create_notification_templates.php',

        // WC-notifications (#d70c6083) — per-tenant sender configuration
        // (migration 073). from/reply-to, transport selection, non-secret config,
        // and the ENCRYPTED provider credentials (write-only over the API). Every
        // read/write binds tenant_id so a tenant only sees/edits its own sender.
        'tenant_notification_settings' => '073_create_tenant_notification_settings.php',

        // WC-723 Door 2 — where a trashed record's PRIOR lifecycle state waits
        // for its restore (migration 089), keyed (tenant_id, data_type,
        // record_id). Core-owned on purpose: a restore that returns a record to
        // the state it actually held must not require every plugin to add a
        // column. `record_id` can carry no FK (the target table varies by data
        // type), so LifecycleStateMemory forgets the row on a hard delete
        // itself. Every read/write binds tenant_id — the key includes a foreign
        // tenant's record ids, so an unscoped lookup would read across tenants.
        'data_type_restore_states' => '089_create_data_type_restore_states.php',

        // The host-owned counters behind Whity\Sdk\Sql\SequenceAllocator
        // (migration 092), keyed (tenant_id, name). Core-owned so a plugin that
        // needs uniquely numbered records ships no table and writes no SQL, and
        // so the read-then-write allocation race is impossible in ONE place
        // rather than avoidable in N. tenant_id is a real column with a real
        // cascade precisely so this guard polices it: one tenant's `invoice`
        // counter must not be advanceable by naming it from another tenant.
        // A platform-wide counter is the system tenant's (id 0) counter, which
        // keeps one storage shape rather than a second, unpoliceable global one.
        'sequence_counters' => '092_create_sequence_counters.php',

        // WC-i18n — per-tenant translation overrides (migration 081).
        // Translations are global-scoped (tenant_id NULL = system defaults) but
        // also support tenant-specific overrides (tenant_id > 0). Every query
        // accessing tenant overrides binds tenant_id so a tenant only sees/edits
        // its own customizations; system defaults are accessible globally.
        'translations' => '081_create_language_tables.php',

        // WHIT-417 — pending invitations into a tenant (migration 096). Unlike
        // `password_resets`, which is global because a credential belongs to a
        // person, an invitation is the ISSUING TENANT'S decision to extend
        // access and carries that tenant's role and OU — so it is tenant-owned
        // and every administrator-facing read/write binds tenant_id. The two
        // token-driven statements run on the public accept endpoint, where
        // there is no tenant context, and carry an explicit guard annotation.
        'invitations' => '096_create_invitations.php',

        // #822 — the tenant's own organizational-unit TYPE vocabulary
        // (migration 102): the campus/faculty/department (or region/branch/team)
        // levels its tree is made of. Tenant-owned precisely because that
        // vocabulary is not universal — a university tenant and a hospital
        // tenant hold different levels in one install — so every read and write
        // binds tenant_id, and the LEFT JOIN the OU list uses to expose a unit's
        // type joins on `ou_types.tenant_id = organizational_units.tenant_id`
        // rather than on the id alone.
        'ou_types' => '102_create_ou_types.php',

        // #978 (#947 item 5) — the document organizer's per-user collections
        // (migration 114). Tenant-owned AND profile-owned: a collection is one
        // person's filing of documents inside ONE tenant, so every read binds
        // tenant_id AND profile_id and the guard polices the first of those.
        // The item table carries its own tenant_id for the same reason
        // `document_artifacts` does — the membership join must be verifiable
        // here, not inferred from the collection it hangs off.
        'document_collections' => '114_create_document_collections.php',
        'document_collection_items' => '114_create_document_collections.php',

        // #1036 — the QR code printed on a document, and the append-only record
        // of it being scanned (migration 120). Tenant-owned like everything else
        // that hangs off `documents`, with ONE statement in the subsystem that
        // arrives without a tenant to bind: the PUBLIC verification lookup, which
        // is entered by an anonymous stranger holding a piece of paper and whose
        // 256-bit token IS the tenant selector. It carries an explicit guard
        // annotation, exactly as `invitations` does above and for the same
        // reason — and every read the handler makes AFTER it binds the tenant_id
        // that lookup returned.
        'document_qr_tokens' => '120_create_document_qr_tracking.php',
        'document_qr_scans' => '120_create_document_qr_tracking.php',
    ];

    /**
     * The tenant-owned table names.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Whether the given table carries a `tenant_id` column and must therefore
     * bind a tenant predicate on every SELECT/UPDATE/DELETE.
     */
    public static function isTenantOwned(string $table): bool
    {
        return array_key_exists(strtolower($table), self::TABLES);
    }
}
