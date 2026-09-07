<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePlanPrices — what a plan COSTS.
 *
 * Plans have existed since migration 055 and have never had a price. `plans`
 * carries `plan_key`, `name`, `description`, `is_active`, `sort_order` and
 * nothing else, so an operator could describe a tier, attach entitlements to it,
 * assign a tenant to it and drive the payment wall from it — without the system
 * ever knowing what any of it was worth. Every commercial thing above this
 * (promotions, checkout, an invoice) needs a number to start from.
 *
 * MONEY IS AN INTEGER OF MINOR UNITS. `unit_amount` is fils, halalas, cents —
 * never a decimal, and never a float. A float cannot hold 0.10 exactly, and the
 * error compounds through exactly the operations a billing system performs:
 * multiply by a seat count, take a percentage off, sum a cart. Integers are also
 * what every payment provider's API accepts, so the alternative is converting at
 * the boundary and hoping the rounding matches theirs.
 *
 * NO CURRENCY EXPONENT IS STORED. Whether 100 means 1.00 or 100 is a property of
 * the CURRENCY (KWD has three decimal places, JPY has none), not of the row, and
 * a per-row exponent would let two prices in the same currency disagree about
 * what their own numbers mean.
 *
 * ONE PLAN, MANY PRICES. A plan is a tier — what you get. A price is an offer to
 * sell that tier on particular terms: a currency, a billing period, and whether
 * the amount is charged once or per seat. Monthly and yearly are two prices for
 * one plan, not two plans, because they grant identical entitlements and a tenant
 * switching between them has not changed what it bought.
 *
 * PER-SEAT PRICING IS A FLAG, NOT A SEPARATE TABLE. `is_per_seat` says whether
 * `unit_amount` multiplies by the seat count (`members.max`, enforced by
 * SeatService) or stands alone. The alternative — a `flat_prices` table and a
 * `seat_prices` table — would duplicate currency, period, activation and every
 * future column, and would make "the prices for this plan" two queries that a
 * caller could forget to union.
 *
 * NOTHING IS CHARGED BY THIS MIGRATION. It records what a thing costs. Taking
 * money needs a provider, and which provider — or none, for a sovereign
 * deployment reconciling payments out of band — is an operator decision this
 * table deliberately does not encode.
 */
final class CreatePlanPrices
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plan_prices (
                id           BIGSERIAL     NOT NULL PRIMARY KEY,
                plan_id      BIGINT        NOT NULL REFERENCES plans(id) ON DELETE CASCADE,

                -- ISO 4217, upper case. Three characters exactly; a CHECK would
                -- be tempting but the list changes (currencies are added and
                -- retired) and a migration is a bad place to freeze one.
                currency     VARCHAR(3)    NOT NULL,

                -- Minor units. See the class note: never a decimal, never a
                -- float, and no exponent — that belongs to the currency.
                unit_amount  BIGINT        NOT NULL,

                -- 'month' | 'year' | 'once'. A one-off price is how a perpetual
                -- licence or a setup fee is expressed, and leaving it out would
                -- push those into a second table for no gain.
                billing_period VARCHAR(16) NOT NULL,

                -- Does unit_amount multiply by the tenant's seat count?
                is_per_seat  BOOLEAN       NOT NULL DEFAULT FALSE,

                -- A price is switched off rather than deleted. Deleting one that
                -- a tenant is being billed against would leave a subscription
                -- pointing at nothing, and the row is also the evidence of what
                -- somebody was charged.
                is_active    BOOLEAN       NOT NULL DEFAULT TRUE,

                created_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_plan_prices_plan_id ON plan_prices(plan_id)');

        /*
         * One ACTIVE price per (plan, currency, period, per-seat-ness).
         *
         * Without it a plan could carry two active monthly SAR prices and every
         * caller would have to decide which one it meant — a choice that would
         * be made differently by the checkout, the invoice and the price list,
         * and would show up as a customer charged an amount no screen displayed.
         *
         * A PARTIAL index, so switching a price off frees the slot for its
         * replacement while the old row stays as the record of what was charged.
         * SQLite and PostgreSQL both support partial unique indexes with this
         * syntax.
         */
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_plan_prices_active
                 ON plan_prices(plan_id, currency, billing_period, is_per_seat)
              WHERE is_active'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plan_prices CASCADE');
    }
}
