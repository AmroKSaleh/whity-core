<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * PlanAddons — the difference between a plan you subscribe to and one you add on.
 *
 * A TIER IS WHAT YOU ARE; AN ADD-ON IS WHAT YOU HAVE MORE OF. Plus, Pro and
 * Professional are alternatives: a tenant is on exactly one, and buying a second
 * would mean paying twice for the same month. Devices are neither — they are
 * bought BESIDE a tier, by somebody who already has one, and a tenant may want
 * more of them next month than this.
 *
 * WITHOUT THIS COLUMN THE TWO ARE INDISTINGUISHABLE, and the catalogue sold the
 * wrong thing in both directions. A tenant with no subscription could buy
 * devices and be let into the product by it — paying twenty dinars for an
 * account nobody had sold them a tier for. And a tenant who HAD a tier could buy
 * nothing at all, because the guard that stops a second tier stops everything.
 *
 * A BOOLEAN RATHER THAN A KIND ENUM, deliberately. The only question anything
 * asks is "does buying this require an existing subscription", and that is what
 * the column answers. A `plan_kind` with three values would invite a fourth that
 * nothing knows how to price.
 *
 * DEFAULTS TO FALSE, so every plan that exists today stays a tier and every
 * deployment behaves exactly as it did. Marking something an add-on is a
 * deliberate act by whoever priced it.
 */
class PlanAddons
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('
            ALTER TABLE plans
                ADD COLUMN IF NOT EXISTS is_addon BOOLEAN NOT NULL DEFAULT FALSE
        ');

        // Only ever read alongside `is_active` when listing what a tenant may
        // buy, which is a small table read often enough to be worth the index
        // and never worth a scan.
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_plans_addon_active
                ON plans(is_addon, is_active)
        ');

        // A PER-DEVICE PLAN IS AN ADD-ON, AND THE MIGRATION SAYS SO ITSELF.
        //
        // Defaulting every existing plan to FALSE and leaving somebody to flip
        // the right rows afterwards is a deploy step that works the first time
        // and is forgotten every time after. The failure it leaves behind is
        // silent and points the wrong way: the device plan keeps being offered
        // as a TIER, so a tenant with no subscription can buy devices and be let
        // into the product by them, while a tenant who already has a tier is
        // refused the add-on they actually want. Both are the exact bugs the
        // column was added to prevent.
        //
        // DERIVED FROM THE PRICE, NOT FROM A NAME. Matching `plan_key = 'devices'`
        // would encode one deployment's catalogue into every deployment's
        // schema. "Priced per device" is what actually makes something bought
        // beside a subscription rather than as one, and it is already recorded.
        //
        // Inactive prices count. A per-device plan withdrawn from sale is still
        // an add-on — its existing subscribers did not become tier customers
        // because the catalogue moved on.
        $pdo->exec('
            UPDATE plans SET is_addon = TRUE
             WHERE is_addon = FALSE
               AND EXISTS (
                     SELECT 1 FROM plan_prices pp
                      WHERE pp.plan_id = plans.id
                        AND pp.is_per_device = TRUE
                   )
        ');
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('DROP INDEX IF EXISTS idx_plans_addon_active');
        $pdo->exec('ALTER TABLE plans DROP COLUMN IF EXISTS is_addon');
    }
}
