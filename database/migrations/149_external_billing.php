<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * ExternalBilling — the two columns that let someone else take the money.
 *
 * whity-core does not process payments. A separate service does, and it knows
 * nothing about tenants, features or permissions — only an opaque id we hand it.
 * The relationship is two questions: *where do I send this tenant to pay* and
 * *may this tenant use paid features*. That needs remarkably little schema.
 *
 * WHAT IS NOT HERE IS THE POINT. No transactions, no settlements, no payment
 * methods, no refunds, no provider names, no card anything. Every one of those
 * is the billing service's business, and a column here would be a second copy of
 * a fact we do not own — one that goes stale the moment it is written and that
 * somebody would eventually read instead of asking.
 *
 * `tenant_plan` already carries `status`, `current_period_end`, `grace_until` and
 * `external_ref` from migration 057, so the local cache of "may they use it"
 * needs nothing new at all. It was designed for this.
 */
class ExternalBilling
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        // WHICH THING TO BUY, in the billing service's own vocabulary.
        //
        // A plan here is a bundle of ENTITLEMENTS — what the tenant may do. A
        // price there is an amount, a currency and an interval. Neither side can
        // name the other's concept, so one opaque handle joins them, and it is
        // stored per PRICE rather than per plan because that is the grain the
        // other side sells at: the same plan priced monthly and yearly is two
        // handles, and a checkout has to name exactly one.
        //
        // Deliberately untyped beyond a length: it is an identifier we never
        // parse, compare or generate — only echo back. Validating its shape here
        // would encode the other side's id format into our schema and break the
        // day they change it.
        $pdo->exec('
            ALTER TABLE plan_prices
                ADD COLUMN IF NOT EXISTS external_ref VARCHAR(64)
        ');

        // NO TABLE FOR NOTIFICATION RECEIPTS, deliberately.
        //
        // Deliveries repeat by design, so an event id has to be remembered to
        // apply it exactly once — but that memory is a counter with a lifetime
        // measured in hours, not a record worth keeping. A dedicated table would
        // have needed a pruning job to stop it growing for the life of the
        // deployment, and one more entry on the sanctioned-global allowlist,
        // whose whole purpose is to stay short.
        //
        // {@see \Whity\Core\Billing\External\EventLedger} uses the existing
        // atomic counter store instead, which expires its own keys.
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('ALTER TABLE plan_prices DROP COLUMN IF EXISTS external_ref');
    }
}
