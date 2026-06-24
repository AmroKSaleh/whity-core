<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateTenantEmailDomains migration (WC-9b87 — Phase B, migration 031).
 *
 * Creates `tenant_email_domains` — the domain-ownership registry that drives the
 * email-domain membership policy (ADR 0005).
 *
 * When a profile verifies an email whose domain matches a row in this table the
 * TenantEmailDomainPolicyService:
 *   - auto_provision = TRUE  → inserts an 'active' membership with `default_role_id`
 *   - pending invite exists  → transitions the invite to 'active' (regardless of flag)
 *   - membership already active → no-op
 *
 * Design notes
 * ------------
 *  - tenant_id NOT NULL + ON DELETE CASCADE: the policy is tenant-scoped; removing
 *    a tenant removes all its domain registrations. MUST be in TenantOwnedTables so
 *    the predicate guard enforces tenant_id on every statement that is not the
 *    intentional cross-tenant policy-lookup (findTenantsByDomain).
 *  - UNIQUE(tenant_id, domain): a tenant can claim each domain only once.
 *    Two different tenants may register the same domain (split-domain orgs).
 *  - default_role_id ON DELETE CASCADE: if the referenced role is removed the
 *    domain policy loses its provisioning target and is removed with it; the admin
 *    must re-register the domain with a surviving role.
 *  - auto_provision BOOLEAN NOT NULL DEFAULT TRUE: when FALSE the service only
 *    accepts pending invites; it does not create new memberships.
 *  - idx_tenant_email_domains_tenant_id: backs the per-tenant admin list query.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateTenantEmailDomains
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_email_domains (
                id              SERIAL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                domain          VARCHAR(253) NOT NULL,
                default_role_id INTEGER      NOT NULL REFERENCES roles(id)   ON DELETE CASCADE,
                auto_provision  BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, domain)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_email_domains_tenant_id ON tenant_email_domains(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_email_domains CASCADE');
    }
}
