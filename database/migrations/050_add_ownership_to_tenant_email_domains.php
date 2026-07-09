<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add domain-OWNERSHIP verification to `tenant_email_domains` (WC-628738f5).
 *
 * Until now a tenant could register ANY domain (format check only) and, with
 * auto_provision on, harvest a membership for every user who verifies an email
 * on that domain — a cross-tenant harvesting hole (an adversarial review of PR
 * #432 confirmed it is reachable via the email-verification confirm flow). The
 * fix gates auto-provisioning on proven ownership:
 *
 *   - `verified_at`         — NULL until the tenant proves it controls the domain
 *                             (via the DNS TXT challenge). Auto-provision fires
 *                             ONLY when this is set; existing rows default NULL,
 *                             so the guard fails CLOSED (no auto-provision) until
 *                             a domain is verified.
 *   - `verification_token`  — the per-registration secret the tenant must publish
 *                             in a `_whity-verify.<domain>` TXT record. Opaque,
 *                             unguessable; a NULL token means "not yet challenged".
 *
 * A new FORWARD migration (not an edit to 031) so it also reaches long-lived
 * databases that already ran 031 (persistent-DB-drift rule). Additive +
 * idempotent (ADD COLUMN IF NOT EXISTS); down() drops the columns.
 */
class AddOwnershipToTenantEmailDomains
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE tenant_email_domains ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP');
        $db->exec('ALTER TABLE tenant_email_domains ADD COLUMN IF NOT EXISTS verification_token VARCHAR(64)');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE tenant_email_domains DROP COLUMN IF EXISTS verification_token');
        $db->exec('ALTER TABLE tenant_email_domains DROP COLUMN IF EXISTS verified_at');
    }
}
