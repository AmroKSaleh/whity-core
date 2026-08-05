<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Adds `profiles.status` — the account-level active/inactive switch
 * (WC-user-status).
 *
 * Design notes
 * ------------
 *  - This is DELIBERATELY distinct from `memberships.status` (migration 030,
 *    one of 'active'|'invited'|'suspended'): that column is a PER-TENANT
 *    lifecycle state (does this person belong to THIS tenant right now).
 *    `profiles.status` is a GLOBAL identity-level switch (ADR 0005 §1) — an
 *    admin deactivating an account blocks that person's login everywhere,
 *    not just in one tenant, mirroring how `password_hash` and 2FA state
 *    already live once on the profile rather than being duplicated per
 *    membership.
 *  - VARCHAR(32) with a string enum ('active'/'inactive') rather than a
 *    boolean, mirroring the `memberships.status` convention in this schema
 *    (migration 030) so a future intermediate state (e.g. a self-service
 *    "pending deletion" or "locked") can be added without a type change.
 *  - NOT NULL DEFAULT 'active': every existing and newly-created profile is
 *    active unless an admin explicitly deactivates it — no behavior change
 *    for any profile that predates this column.
 *  - `profiles` is a sanctioned GLOBAL table (ADR 0005 §1); this column
 *    carries no tenant_id and needs none — see
 *    {@see \Whity\Core\Tenant\SanctionedGlobalTables}.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class AddStatusToProfiles
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'active'");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS status');
    }
}
