<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add tenants.branding_host (Tenant Branding).
 *
 * An optional custom hostname (e.g. "app.acmecorp.com") that maps a request
 * host to this tenant for PRE-AUTH branding only (login/favicon/title). It is
 * display-only — never the authoritative auth tenant. Slug-subdomains use the
 * existing tenants.slug; this column covers full custom domains.
 *
 * Additive + idempotent (ADD COLUMN IF NOT EXISTS); down() drops it. UNIQUE so
 * a host maps to at most one tenant.
 */
class AddBrandingHostToTenants
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE tenants ADD COLUMN IF NOT EXISTS branding_host VARCHAR(255)');
        // Partial-safe unique index (NULLs are allowed to repeat in both PG and SQLite).
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tenants_branding_host ON tenants (branding_host)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_tenants_branding_host');
        $db->exec('ALTER TABLE tenants DROP COLUMN IF EXISTS branding_host');
    }
}
