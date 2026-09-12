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
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('DROP INDEX IF EXISTS idx_plans_addon_active');
        $pdo->exec('ALTER TABLE plans DROP COLUMN IF EXISTS is_addon');
    }
}
