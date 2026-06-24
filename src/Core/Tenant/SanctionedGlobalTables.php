<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Single source of truth for the platform's SANCTIONED GLOBAL tables.
 *
 * Whity Core is multi-tenant: isolation is enforced logically by a `tenant_id`
 * column on tenant-scoped tables plus a request-scoped {@see TenantContext} (see
 * docs/wiki/TENANT_ISOLATION.md). The invariant is "every query on a
 * tenant-owned table binds a tenant_id predicate". A small set of tables are,
 * BY DESIGN, NOT tenant-scoped — they have no `tenant_id` column and their rows
 * are platform-unique, so a tenant predicate would be meaningless. Those tables
 * are enumerated here so the exception is explicit, reviewable and testable
 * rather than implicit per query.
 *
 * This list is the allowlist the upcoming tenant-predicate static guard
 * consumes: a query touching a table NOT in this list must carry a tenant_id
 * predicate; a query touching a table IN this list is exempt. Adding a table
 * here is a deliberate, reviewed decision — it removes that table from tenant
 * isolation enforcement, so each entry must document WHY it is global.
 */
final class SanctionedGlobalTables
{
    /**
     * Tables that intentionally hold platform-global (non-tenant-scoped) rows.
     *
     * Each entry is keyed by table name with a short rationale. Keep this list
     * MINIMAL — a table belongs here only when its rows are genuinely
     * platform-unique and a tenant_id predicate would be incorrect, not merely
     * inconvenient.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        // A JWT jti is unique platform-wide and a revoked token must be rejected
        // for whatever tenant presents it, so the revocation table carries no
        // tenant_id. Read on every token validation; pruned daily by the
        // revoked-tokens:cleanup cron (see docs/wiki/Cron-Operations.md).
        'revoked_tokens' => 'JWT jti is platform-unique; a revocation applies regardless of tenant.',

        // The migration ledger tracks which schema migrations have run against
        // the single shared database — a process/deploy-level concern, not a
        // tenant concern.
        'core_schema_migrations' => 'Schema migration ledger for the shared database; not tenant data.',

        // Platform-wide website-settings defaults (site_name, timezone, locale,
        // support_email). These are the GLOBAL fallbacks every tenant inherits
        // until it stores a per-tenant override (in the tenant-owned
        // `tenant_settings` table); a row here is unique platform-wide and
        // carries no tenant_id, so a tenant predicate would be meaningless.
        'app_settings' => 'Platform-wide website-settings defaults; no tenant column (per-tenant overrides live in tenant_settings).',

        // ADR 0005 (Phase B) — global identity anchor (migration 028). A profile
        // holds a person's credentials and 2FA state once, regardless of how many
        // tenants they belong to. No tenant_id column; membership is tracked via
        // the tenant-scoped `memberships` table (migration 029).
        'profiles' => 'Global identity anchor (ADR 0005); credentials belong to a person, not an org — no tenant_id column.',

        // ADR 0005 (Phase B) — globally-unique email registry (migration 029).
        // A UNIQUE(email) constraint across this table is the structural fix for
        // issue #181: the same email cannot belong to two profiles, so login-by-email
        // is always unambiguous. No tenant_id column; rows join only to profiles.
        'profile_emails' => 'Globally-unique email addresses per profile (ADR 0005); UNIQUE(email) fixes #181 — no tenant_id column.',
    ];

    /**
     * The sanctioned global table names.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Whether the given table is a sanctioned global (non-tenant-scoped) table.
     */
    public static function isGlobal(string $table): bool
    {
        return array_key_exists(strtolower($table), self::TABLES);
    }

    /**
     * The documented rationale for a sanctioned global table, or null if the
     * table is not on the allowlist (i.e. is expected to be tenant-scoped).
     */
    public static function reasonFor(string $table): ?string
    {
        return self::TABLES[strtolower($table)] ?? null;
    }
}
