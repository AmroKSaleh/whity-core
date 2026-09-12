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

        // WHICH NOTIFICATIONS WE HAVE ALREADY ACTED ON.
        //
        // Deliveries repeat — that is designed behaviour, not a fault. A
        // notification whose response timed out is re-sent with the SAME event
        // id, so the only way to apply it exactly once is to remember the id.
        //
        // The PRIMARY KEY is the mechanism, not a lookup convenience: two
        // workers can receive the same retry simultaneously, and a
        // check-then-insert has a window in which both see "not seen yet". The
        // insert either succeeds or collides, and the collision IS the answer.
        //
        // NO tenant_id, deliberately: this is an idempotency ledger for messages
        // arriving from outside, deduplicated before anything is known about who
        // they concern. Registered in SanctionedGlobalTables for that reason.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS billing_event_receipts (
                event_id    VARCHAR(64)  NOT NULL PRIMARY KEY,
                event_type  VARCHAR(64)  NOT NULL,
                received_at TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');

        // Only ever read to answer "have I seen this id", but swept by age when
        // the ledger is pruned — a table that grows forever is its own outage.
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_billing_event_receipts_received_at
                ON billing_event_receipts(received_at)
        ');
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('DROP INDEX IF EXISTS idx_billing_event_receipts_received_at');
        $pdo->exec('DROP TABLE IF EXISTS billing_event_receipts');
        $pdo->exec('ALTER TABLE plan_prices DROP COLUMN IF EXISTS external_ref');
    }
}
