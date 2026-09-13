<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * SeedTierEntitlements — give the tiers something to actually differ on.
 *
 * Plus, Pro and Professional were priced at three very different amounts and
 * granted identical access, because every bundle was empty. This writes a
 * defensible starting point so the product is sellable the moment it deploys.
 *
 * ── These are DEFAULTS, not policy ─────────────────────────────────────────
 *
 * Every value here is editable in the tier screen, by the people who price
 * things, without a deploy. That is the whole point of the feature, and it is
 * also why picking reasonable numbers in a migration is not presumptuous:
 * whoever owns pricing will change them, and nothing here is load-bearing.
 *
 * ── Why only tiers that already exist are touched ──────────────────────────
 *
 * Matched by `plan_key`, and silently skipped when a key is absent. A
 * deployment that named its tiers something else, or sells only one, must not
 * have this migration invent rows in its catalogue — and one that has already
 * priced its tiers must not have that work overwritten, which is why every
 * write is INSERT-if-absent rather than an upsert.
 *
 * ── The shape of the ladder ────────────────────────────────────────────────
 *
 * Three axes a customer feels, rising together:
 *
 *   Plus          a small team. Enough to work; not enough to run a company on.
 *   Pro           a growing team, and the first tier with an outside surface:
 *                 the plugin store and the AI/MCP endpoints.
 *   Professional  an organisation. Its own identity provider, its own storage,
 *                 and no counting.
 *
 * ADD-ONS ARE LEFT ALONE. `devices` is bought beside a tier, and the tier's own
 * `devices.max` is what caps how many of them may be in service — so a device
 * add-on and a device limit are two halves of one deal, not a contradiction.
 */
class SeedTierEntitlements
{
    /**
     * plan_key => entitlement_key => value.
     *
     * Unset keys fall through to the registry baseline, which is UNLIMITED for
     * every cap. A tier therefore only lists what it RESTRICTS or unlocks, and
     * anything added to the registry later is not silently revoked from tiers
     * that predate it.
     */
    private const TIERS = [
        'plus' => [
            'members.max'                     => '3',
            'devices.max'                     => '2',
            'documents.render.per_day'        => '5',
            'documents.render.per_month'      => '50',
            'storage.quota_bytes'             => '2147483648',    // 2 GiB
            'ratelimit.rpm'                   => '120',
            'plugins.store'                   => 'false',
            'mcp.access'                      => 'false',
            'sso.tenant_idp'                  => 'false',
            'storage.custom_backend'          => 'false',
        ],
        'pro' => [
            'members.max'                     => '15',
            'devices.max'                     => '10',
            'documents.render.per_day'        => '50',
            'documents.render.per_month'      => '1000',
            'storage.quota_bytes'             => '21474836480',   // 20 GiB
            'ratelimit.rpm'                   => '600',
            'plugins.store'                   => 'true',
            'mcp.access'                      => 'true',
            'sso.tenant_idp'                  => 'false',
            'storage.custom_backend'          => 'false',
        ],
        'professional' => [
            'members.max'                     => '-1',
            'devices.max'                     => '-1',
            'documents.render.per_day'        => '-1',
            'documents.render.per_month'      => '-1',
            'storage.quota_bytes'             => '536870912000',  // 500 GiB
            'ratelimit.rpm'                   => '3000',
            'plugins.store'                   => 'true',
            'mcp.access'                      => 'true',
            'sso.tenant_idp'                  => 'true',
            'storage.custom_backend'          => 'true',
        ],
    ];

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $find = $pdo->prepare('SELECT id FROM plans WHERE plan_key = :plan_key');
        $exists = $pdo->prepare(
            'SELECT 1 FROM plan_entitlements WHERE plan_id = :plan_id AND entitlement_key = :entitlement_key'
        );
        $insert = $pdo->prepare(
            'INSERT INTO plan_entitlements (plan_id, entitlement_key, value)
             VALUES (:plan_id, :entitlement_key, :value)'
        );

        foreach (self::TIERS as $planKey => $bundle) {
            $find->execute([':plan_key' => $planKey]);
            $planId = $find->fetchColumn();

            // A deployment that does not sell this tier is not given one.
            if ($planId === false) {
                continue;
            }

            foreach ($bundle as $entitlementKey => $value) {
                // INSERT-IF-ABSENT, never an upsert. An operator who has already
                // priced a tier has made a decision; a migration that overwrote
                // it would silently reprice their product on deploy — and would
                // do it again on every environment they rebuild.
                $exists->execute([':plan_id' => $planId, ':entitlement_key' => $entitlementKey]);
                if ($exists->fetchColumn() !== false) {
                    continue;
                }

                $insert->execute([
                    ':plan_id' => (int) $planId,
                    ':entitlement_key' => $entitlementKey,
                    ':value' => $value,
                ]);
            }
        }
    }

    /**
     * Removes exactly the rows this migration would have written, and only
     * where they still hold the seeded value.
     *
     * A row an operator has since edited is left alone: rolling back a seed
     * must not throw away pricing somebody changed on purpose.
     */
    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $delete = $pdo->prepare(
            'DELETE FROM plan_entitlements
              WHERE entitlement_key = :entitlement_key
                AND value = :value
                AND plan_id IN (SELECT id FROM plans WHERE plan_key = :plan_key)'
        );

        foreach (self::TIERS as $planKey => $bundle) {
            foreach ($bundle as $entitlementKey => $value) {
                $delete->execute([
                    ':entitlement_key' => $entitlementKey,
                    ':value' => $value,
                    ':plan_key' => $planKey,
                ]);
            }
        }
    }
}
