<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePaymentTransactions — the money ledger, and the payment methods it is
 * charged against. Both are deliberately ignorant of any particular provider.
 *
 * WHY "TRANSACTIONS" AND NOT "PAYMENTS"
 * -------------------------------------
 * A table called `payments` invites rows that are payments. What the system
 * actually needs to remember is every ATTEMPT — the declined card, the transfer
 * that arrived for the wrong amount, the charge still pending at the bank —
 * because dunning is driven entirely by failures, and a ledger that records
 * only successes cannot tell you why a tenant is about to be locked out.
 *
 * So a row here is one movement or attempted movement of money, and `status`
 * says which it turned out to be. Only settled rows count toward what an
 * invoice has been paid.
 *
 * ONE LEDGER FOR EVERY RAIL
 * -------------------------
 * A CliQ bank push and a future card charge produce the same row shape:
 * an amount, a currency, a status, which provider it came from, and that
 * provider's own identifier for it. Nothing here is specific to either, and
 * that is the point — the reconciliation query, the invoice balance, the
 * payment history screen and the dunning state machine are all written once,
 * against this table, and adding the card provider adds no column.
 *
 * `provider` IS A STRING, NOT AN ENUM TYPE. A CHECK constraint listing today's
 * providers would have to be altered by migration to add tomorrow's, on a
 * deployment that may already have rows referencing it, and the card PSP is
 * explicitly undecided. The application owns the vocabulary; the database
 * stores what it is told and indexes it.
 *
 * REPLAYED WEBHOOKS ARE REFUSED BY THE SCHEMA
 * -------------------------------------------
 * `(provider, external_reference)` is unique among rows that carry a reference.
 * Every processor redelivers — on timeout, on a 500, on its own retry schedule
 * — and the second delivery of one charge must not credit the account twice.
 *
 * Idempotency lives here rather than in a handler for two reasons. A handler is
 * only one of several ways a movement arrives (webhook, reconciliation poll,
 * an operator keying it in), so the deduplication has to hold across all of
 * them. And a check-then-insert in application code does not hold when two
 * workers process the same redelivery at the same instant, which is exactly
 * when a processor retries.
 *
 * A REFUND IS A NEGATIVE AMOUNT rather than a row with a direction flag, so a
 * balance is a SUM with nothing to remember and no report can get the sign
 * wrong by forgetting to branch. Zero is forbidden: it is a record of nothing.
 *
 * PAYMENT METHODS ASSUME NOTHING ABOUT TOKENS
 * -------------------------------------------
 * `payment_methods` is `(tenant_id, provider, external_id)` plus an opaque
 * metadata blob. The card provider has not been chosen, so anything this table
 * assumed about token shape — a `card_last4`, an `expiry_month`, a
 * `stripe_customer_id` — would be a guess baked into a schema, and wrong for
 * one of the two candidates. What every provider does have is an identifier it
 * gave us and some display detail we should not parse. That is all this stores.
 *
 * NOTHING HERE HOLDS A CARD NUMBER, and nothing here ever should. The whole
 * reason to keep a provider's token is that the provider holds the instrument
 * and we do not.
 */
final class CreatePaymentTransactions
{
    public static function up(Database $db): void
    {
        /*
         * A payment method a tenant has on file with some provider.
         *
         * Created before the ledger because a transaction may reference one.
         */
        $db->exec("
            CREATE TABLE IF NOT EXISTS payment_methods (
                id             BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id      INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,

                -- Optional subuser scope, mirroring `invoices`: a member paying
                -- for their own seat has their own instrument on file.
                profile_id     INTEGER       REFERENCES profiles(id) ON DELETE CASCADE,

                -- Which rail this belongs to ('cliq', 'mock', and whichever
                -- card provider is chosen). A string, not a CHECK — see the
                -- class docblock.
                provider       VARCHAR(32)   NOT NULL,

                -- THE PROVIDER'S identifier, opaque to us. Not parsed, not
                -- validated beyond being present, never assumed to have a
                -- shape.
                external_id    VARCHAR(255)  NOT NULL,

                -- Enough to show a person which method they picked — a masked
                -- tail, a bank name, an alias. JSON because what is available
                -- differs per provider, and columns for one provider's fields
                -- would be null for every other.
                display_meta   TEXT,

                -- Exactly one default per (tenant, provider) is enforced by a
                -- partial unique index below, rather than by whichever write
                -- path remembers to clear the others.
                is_default     BOOLEAN       NOT NULL DEFAULT FALSE,

                -- Detached rather than deleted: a transaction refers to the
                -- method that produced it, and the history must survive the
                -- customer removing the card.
                is_active      BOOLEAN       NOT NULL DEFAULT TRUE,

                created_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");

        /*
         * One provider's identifier means one method. A provider redelivering
         * a tokenisation callback must not produce a second row that then
         * competes to be the default.
         */
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_methods_provider_external
                 ON payment_methods(provider, external_id)'
        );

        /*
         * At most one default per tenant per provider, in the schema. The
         * alternative — every write path remembering to clear the previous
         * default first — fails silently in exactly one direction: two
         * defaults, and a charge that goes to whichever the ORDER BY happened
         * to return.
         */
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_methods_one_default
                 ON payment_methods(tenant_id, provider)
              WHERE is_default AND is_active'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_payment_methods_tenant ON payment_methods(tenant_id)');

        /*
         * The ledger.
         */
        $db->exec("
            CREATE TABLE IF NOT EXISTS payment_transactions (
                id             BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id      INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                -- Nullable: money can arrive before anyone decides which
                -- invoice it settles, and forcing that decision at the moment
                -- of arrival is how a payment gets applied to the wrong one.
                -- RESTRICT because deleting an invoice out from under its
                -- transaction would leave money in the ledger belonging to
                -- nothing.
                invoice_id     BIGINT        REFERENCES invoices(id) ON DELETE RESTRICT,

                -- Which instrument, when there was one. A CliQ push has none:
                -- the payer chose it in their own bank's app.
                payment_method_id BIGINT     REFERENCES payment_methods(id) ON DELETE SET NULL,

                provider       VARCHAR(32)   NOT NULL,

                -- The provider's own identifier for this movement. Unique per
                -- provider among rows that have one — the schema-level
                -- idempotency described in the class docblock.
                external_reference VARCHAR(255),

                -- pending  : initiated, outcome unknown (a transfer in flight)
                -- succeeded: money moved
                -- failed   : it did not, and dunning cares about this one
                -- refunded : reversed; the reversal is its own negative row,
                --            and this marks the original
                status         VARCHAR(16)   NOT NULL DEFAULT 'pending',

                -- Minor units. NEGATIVE IS A REFUND.
                amount_minor   BIGINT        NOT NULL,
                currency       VARCHAR(3)    NOT NULL,

                -- Why it failed, in the provider's words, kept for the
                -- operator rather than shown to the customer.
                failure_reason TEXT,

                -- The raw payload that produced this row, for reconciling a
                -- dispute months later against what the provider actually
                -- sent. Never parsed for business logic — the normalised
                -- columns above are what anything reads.
                raw_payload    TEXT,

                -- When the money moved, which is not when we heard about it.
                -- A webhook arriving late must not date the payment to its own
                -- arrival, or a payment made inside a grace period looks like
                -- one made after it expired.
                occurred_at    TIMESTAMP     NOT NULL DEFAULT NOW(),

                -- Who keyed it in, for a manual reconciliation. SET NULL
                -- because a departed employee's record must survive them.
                recorded_by_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL,

                note           TEXT,
                created_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP     NOT NULL DEFAULT NOW(),

                CONSTRAINT payment_transactions_status CHECK (
                    status IN ('pending', 'succeeded', 'failed', 'refunded')
                ),

                -- Zero is a record of nothing.
                CONSTRAINT payment_transactions_amount_non_zero CHECK (amount_minor <> 0)
            )
        ");

        /*
         * The idempotency the whole webhook story rests on. PARTIAL, so a
         * manually keyed movement is not forced to invent a reference.
         */
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_transactions_provider_reference
                 ON payment_transactions(provider, external_reference)
              WHERE external_reference IS NOT NULL'
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_payment_transactions_invoice
                 ON payment_transactions(invoice_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_payment_transactions_tenant_occurred
                 ON payment_transactions(tenant_id, occurred_at)'
        );
        /*
         * Dunning asks "how many times has this invoice failed lately", and
         * the reconciliation poll asks "what is still pending". Both are
         * status-first.
         */
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_payment_transactions_status
                 ON payment_transactions(status, occurred_at)'
        );
    }

    public static function down(Database $db): void
    {
        // Destroys the payment history outright. Reversible in the schema sense
        // only, exactly as `invoices` (142) and `sequence_counters` (092) are.
        $db->exec('DROP TABLE IF EXISTS payment_transactions CASCADE');
        $db->exec('DROP TABLE IF EXISTS payment_methods CASCADE');
    }
}
