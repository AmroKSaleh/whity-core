<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePromotions — early birds, offers and promo codes, which are ONE THING.
 *
 * THE UNIFICATION IS THE DESIGN DECISION HERE, so it is worth stating plainly.
 * An early bird, a seasonal offer and a promo code differ only in how they are
 * DISCOVERED:
 *
 *   - a promo code is entered by the customer, so it has a `code`;
 *   - an early bird applies to whoever arrives in time, so it has no code, a
 *     window, and usually a cap on how many may take it;
 *   - an offer is the same object with a different name in the marketing.
 *
 * Everything else about them is identical: an amount off, a period of validity,
 * a limit on redemptions, a set of plans it applies to, and a record of who took
 * it. Three tables would mean three places to get expiry wrong, three to get
 * currency matching wrong, and three implementations of "has this been used up"
 * — and the one that drifts is the one nobody looks at because it is the least
 * used. So `code` is nullable, and its absence is what makes a promotion
 * automatic.
 *
 * MONEY IS AN INTEGER OF MINOR UNITS, as it is in `plan_prices`. A fixed
 * discount also carries its own CURRENCY, and it may only be applied to a price
 * in that currency — a discount cannot be converted, because a conversion needs
 * a rate that nobody stored and that changed since.
 *
 * A PERCENTAGE HAS NO CURRENCY, which is exactly why both kinds exist: "20% off"
 * works on every price a plan carries, while "50 SAR off" is an offer about
 * Saudi riyals and is meaningless against a dollar price. The CHECK below makes
 * a row be one or the other rather than an ambiguous both.
 *
 * NOTHING HERE COMPUTES A DISCOUNT. This records what is offered and to whom;
 * the arithmetic — rounding, currency matching, the final amount — follows, and
 * is where a Money type earns its place rather than being speculative.
 */
final class CreatePromotions
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS promotions (
                id             BIGSERIAL     NOT NULL PRIMARY KEY,

                -- What an operator calls it. Always present, including for a
                -- coded promotion: a report listing 'SUMMER24' tells nobody what
                -- it was for six months later.
                name           VARCHAR(255)  NOT NULL,

                -- NULL = automatic (an early bird, an offer). Present = the
                -- customer must type it. Stored upper case so 'summer24' and
                -- 'SUMMER24' cannot become two promotions.
                code           VARCHAR(64),

                -- Exactly one of these is set; the CHECK below enforces it.
                percent_off    INTEGER,
                amount_off     BIGINT,
                -- Required with amount_off, forbidden without: a fixed discount
                -- is an amount of a particular currency or it is nothing.
                currency       VARCHAR(3),

                -- Open-ended at either end. A promotion with neither is simply
                -- always valid, which is a legitimate thing to want.
                starts_at      TIMESTAMP,
                ends_at        TIMESTAMP,

                -- NULL = unlimited. The early bird's defining feature is
                -- usually this rather than the window.
                max_redemptions            INTEGER,
                -- How many times ONE tenant may take it. Defaults to 1, because
                -- the alternative default lets a single tenant consume a whole
                -- early-bird allocation.
                max_redemptions_per_tenant INTEGER  NOT NULL DEFAULT 1,

                -- Switched off rather than deleted: a redeemed promotion is
                -- evidence of why a tenant is paying what they are paying.
                is_active      BOOLEAN       NOT NULL DEFAULT TRUE,

                created_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP     NOT NULL DEFAULT NOW(),

                -- One kind or the other, never both and never neither. Without
                -- this a row could carry 20% AND 50 SAR and every caller would
                -- have to invent a precedence rule of its own.
                CONSTRAINT promotions_one_kind CHECK (
                    (percent_off IS NOT NULL AND amount_off IS NULL AND currency IS NULL)
                    OR
                    (percent_off IS NULL AND amount_off IS NOT NULL AND currency IS NOT NULL)
                ),
                CONSTRAINT promotions_percent_range CHECK (
                    percent_off IS NULL OR (percent_off > 0 AND percent_off <= 100)
                ),
                CONSTRAINT promotions_amount_positive CHECK (
                    amount_off IS NULL OR amount_off > 0
                )
            )
        ");

        /*
         * A code identifies a promotion, so two live promotions cannot share
         * one. PARTIAL, so a retired promotion keeps its code in the record
         * without blocking a new campaign from reusing it — which operators do,
         * every year, with the same seasonal name.
         */
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_promotions_active_code
                 ON promotions(code)
              WHERE code IS NOT NULL AND is_active'
        );

        /*
         * Which plans a promotion applies to.
         *
         * NO ROWS MEANS EVERY PLAN. The alternative — a row per plan for a
         * blanket promotion — has to be re-synchronised every time a plan is
         * added, and the failure is silent: a new tier quietly not covered by
         * the campaign that was supposed to cover everything.
         */
        $db->exec("
            CREATE TABLE IF NOT EXISTS promotion_plans (
                promotion_id BIGINT NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
                plan_id      BIGINT NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
                PRIMARY KEY (promotion_id, plan_id)
            )
        ");

        /*
         * Who took what, and when.
         *
         * This is the ledger the caps are counted from, and it is also the
         * answer to "why is this tenant paying less than the list price" — a
         * question that outlives the promotion, which is why nothing here is
         * deleted when a promotion is retired.
         */
        $db->exec("
            CREATE TABLE IF NOT EXISTS promotion_redemptions (
                id           BIGSERIAL  NOT NULL PRIMARY KEY,
                promotion_id BIGINT     NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
                tenant_id    INTEGER    NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                redeemed_at  TIMESTAMP  NOT NULL DEFAULT NOW(),
                -- What it was worth at the moment it was taken, in minor units.
                -- Recomputing it later would give a different answer once the
                -- price moved, and the question people ask is what they were
                -- actually charged.
                amount_discounted BIGINT,
                currency          VARCHAR(3)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_promotion_redemptions_promotion ON promotion_redemptions(promotion_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_promotion_redemptions_tenant ON promotion_redemptions(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS promotion_redemptions CASCADE');
        $db->exec('DROP TABLE IF EXISTS promotion_plans CASCADE');
        $db->exec('DROP TABLE IF EXISTS promotions CASCADE');
    }
}
