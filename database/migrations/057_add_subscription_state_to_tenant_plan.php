<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-billing subscription state: add billing lifecycle to a tenant's plan.
 *
 * `tenant_plan` (migration 055) recorded WHICH plan a tenant is on; this adds the
 * billing lifecycle that the payment wall enforces (external-state model — an
 * operator/admin reflects an out-of-band payment into these columns; a future
 * provider webhook writes the same via `external_ref`):
 *
 *   - status             : NULL = no subscription configured (never enforced —
 *                          safe default for sovereign/single-customer deploys),
 *                          else one of trialing|active|past_due|canceled|expired.
 *   - current_period_end : when the paid period ends.
 *   - grace_until        : how long a past_due tenant keeps access.
 *   - enforcement_mode   : PER-TENANT operator policy — NULL = use the global
 *                          billing.enforcement_default; else off|warn|block_writes|
 *                          block_all. A tenant admin can never change this.
 *   - external_ref       : opaque provider subscription id (webhook seam).
 *
 * The SYSTEM tenant (0) is never assigned a subscription and is never enforced.
 *
 * Additive (ADD COLUMN IF NOT EXISTS) and reversible.
 */
class AddSubscriptionStateToTenantPlan
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE tenant_plan ADD COLUMN IF NOT EXISTS status VARCHAR(32)');
        $db->exec('ALTER TABLE tenant_plan ADD COLUMN IF NOT EXISTS current_period_end TIMESTAMP');
        $db->exec('ALTER TABLE tenant_plan ADD COLUMN IF NOT EXISTS grace_until TIMESTAMP');
        $db->exec('ALTER TABLE tenant_plan ADD COLUMN IF NOT EXISTS enforcement_mode VARCHAR(16)');
        $db->exec('ALTER TABLE tenant_plan ADD COLUMN IF NOT EXISTS external_ref VARCHAR(255)');
    }

    public static function down(Database $db): void
    {
        foreach (['external_ref', 'enforcement_mode', 'grace_until', 'current_period_end', 'status'] as $col) {
            $db->exec("ALTER TABLE tenant_plan DROP COLUMN IF EXISTS {$col}");
        }
    }
}
