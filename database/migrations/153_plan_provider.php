<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * PlanProvider — who a tier belongs to, so a vertical product can sell its own.
 *
 * ── What this makes possible ───────────────────────────────────────────────
 *
 * A plugin that turns this platform into a specific product — a school system,
 * a clinic system — does not want to sell Plus, Pro and Professional. It wants
 * to sell its own tiers, named for what its customers recognise, priced on the
 * limits it declares. It could always create plan rows from its own migration;
 * what it could not do is say which rows are ITS, or retire the platform's
 * defaults without an operator wondering later why they vanished.
 *
 * ── A column, not a new table ──────────────────────────────────────────────
 *
 * `provider` is null for a tier the operator or the platform owns, and the
 * plugin's name for one a plugin shipped. That is the whole mechanism. It is
 * deliberately NOT a foreign key to anything: plugins are files on disk, not
 * rows, and a tier must outlive the plugin being temporarily uninstalled —
 * otherwise removing a plugin would cascade away the pricing of every customer
 * on its tiers, along with their subscriptions.
 *
 * ── Deactivating the platform's own tiers is just data ─────────────────────
 *
 * A plugin that wants to replace the default catalogue sets `is_active = false`
 * on the rows where `provider IS NULL`. No API, no privileged call, no new
 * concept: it is the same flag an operator toggles on the pricing screen, and
 * an operator can turn them back on there. That reversibility is the point —
 * a plugin quietly and permanently deleting the platform's commercial catalogue
 * would be a far bigger power than it needs, and impossible to undo.
 *
 * ── Why a tier keeps selling after its plugin goes ─────────────────────────
 *
 * Nothing here deactivates a plugin's tiers when the plugin is uninstalled, and
 * that is deliberate. Customers are SUBSCRIBED to those tiers; switching them
 * off would stop renewals for people who are paying, as a side effect of an
 * operator removing a plugin. The tier stays, its plugin-declared limits stop
 * being enforced (see PluginEntitlementsInterface), and retiring it is a
 * decision somebody makes on the pricing screen.
 */
class PlanProvider
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('
            ALTER TABLE plans
                ADD COLUMN IF NOT EXISTS provider VARCHAR(64)
        ');

        // Read whenever the catalogue is listed — the pricing screen groups by
        // it, so an operator can see at a glance which tiers came from a plugin
        // rather than wondering why a tier they never created is on sale.
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_plans_provider
                ON plans(provider)
        ');
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('DROP INDEX IF EXISTS idx_plans_provider');
        $pdo->exec('ALTER TABLE plans DROP COLUMN IF EXISTS provider');
    }
}
