<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddPerDevicePricing — a price that multiplies by licensed devices.
 *
 * `plan_prices.is_per_seat` already answers "does unit_amount multiply by
 * something", and it multiplies by PEOPLE. A deployment billing per device
 * multiplies by hardware instead, and the two are genuinely different: a
 * classroom of thirty students sharing five tablets is thirty seats and five
 * devices, and a shelf of stock is neither.
 *
 * A SECOND BOOLEAN RATHER THAN A `unit` ENUM, deliberately. Replacing
 * `is_per_seat` with a kind column would rewrite a shipped table that live
 * deployments already price against, for no behaviour a boolean cannot express.
 * The cost of the second flag is that both could be set at once, which is
 * meaningless — so the database refuses it rather than leaving the invoice to
 * decide which multiplier wins.
 *
 * THE UNIQUE INDEX HAS TO WIDEN WITH IT. `uq_plan_prices_active` pinned one
 * live price per (plan, currency, period, per-seat-ness); without `is_per_device`
 * in that tuple, adding a per-device price to a plan that already has a flat one
 * would collide and the migration would be unusable for the case it exists for.
 *
 * THAT LETS A PLAN STORE ALTERNATIVES, NOT LINES THAT ADD UP. A plan may carry a
 * flat, a per-seat and a per-device price side by side — but a subscription
 * records the plan, not which price it is on, so exactly one of them is billed.
 * `SubscriptionBillingRun::activePriceFor()` owns that choice and logs when the
 * ambiguity arises; billing users AND hardware on one invoice would need a
 * subscription to reference a price, which this migration does not add.
 */
class AddPerDevicePricing
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('
            ALTER TABLE plan_prices
                ADD COLUMN IF NOT EXISTS is_per_device BOOLEAN NOT NULL DEFAULT FALSE
        ');

        // BOTH AT ONCE IS NOT A PRICE, it is an ambiguity. Enforced here rather
        // than in the billing run, because the run would have to pick a winner
        // and whichever it picked would be wrong half the time — and wrong on an
        // invoice somebody already paid.
        //
        // Postgres has no ADD CONSTRAINT IF NOT EXISTS for CHECK, so this is
        // guarded by a catch: re-running a migration is ordinary here.
        try {
            $pdo->exec("
                ALTER TABLE plan_prices
                    ADD CONSTRAINT chk_plan_prices_one_multiplier
                    CHECK (NOT (is_per_seat AND is_per_device))
            ");
        } catch (\PDOException) {
            // Already present, or SQLite (which cannot add a CHECK after the
            // fact). The invariant still holds on the engine that matters, and
            // the billing run never writes this column.
        }

        // Widen the live-price uniqueness to include the new dimension, so a
        // plan may price seats and devices side by side.
        $pdo->exec('DROP INDEX IF EXISTS uq_plan_prices_active');
        $pdo->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS uq_plan_prices_active
                ON plan_prices(plan_id, currency, billing_period, is_per_seat, is_per_device)
             WHERE is_active
        ');
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('DROP INDEX IF EXISTS uq_plan_prices_active');
        $pdo->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS uq_plan_prices_active
                ON plan_prices(plan_id, currency, billing_period, is_per_seat)
             WHERE is_active
        ');

        try {
            $pdo->exec('ALTER TABLE plan_prices DROP CONSTRAINT chk_plan_prices_one_multiplier');
        } catch (\PDOException) {
            // Never added, or an engine that cannot drop it. Not worth failing a
            // rollback over.
        }

        $pdo->exec('ALTER TABLE plan_prices DROP COLUMN IF EXISTS is_per_device');
    }
}
