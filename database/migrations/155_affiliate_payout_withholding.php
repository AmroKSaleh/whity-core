<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AffiliatePayoutWithholding — what a payout is worth, and what is kept back.
 *
 * ── Why this is not one number ─────────────────────────────────────────────
 *
 * A payout has three amounts and they are all different facts:
 *
 *   total_minor       what the affiliate earned
 *   withholding_minor what is kept back and remitted on their behalf
 *   net_minor         what actually leaves the bank
 *
 * The original table had only the first, which is the amount an affiliate's own
 * statement shows and NOT the amount anybody transfers. Recording one and
 * calling it the other is how a payout register stops reconciling with a bank
 * statement — and the gap is exactly the tax, so it looks like a rounding
 * problem for as long as nobody adds it up.
 *
 * ── The rate is SNAPSHOT, like every other tax rate here ───────────────────
 *
 * `withholding_bp` is copied onto the row at assembly, for the same reason
 * migration 142 copies `tax_rate_bp` onto an invoice: a rate that is joined for
 * at read time silently restates last year's payout when somebody changes a
 * setting. A payout is evidence of what was paid and what was kept; evidence
 * that rewrites itself is not evidence.
 *
 * It also means a payout assembled while the rate was zero stays a zero-rate
 * payout, which is the honest record of a decision nobody had made yet rather
 * than a hole to be backfilled later.
 *
 * ── The default is ZERO, deliberately, and that is a risk worth naming ──────
 *
 * Withholding money nobody instructed us to withhold would be worse than not
 * withholding: it takes cash from somebody that they then have to reclaim from
 * a tax authority. So the rate starts at zero and an operator sets it.
 *
 * The danger in that default is the familiar one — a zero nobody notices. It is
 * mitigated by being VISIBLE rather than implicit: the rate is on every payout
 * row, it is returned by the API, and the screen states it even when it is zero.
 * A payout that withheld nothing says so on its face.
 *
 * ── ROUNDED HALF UP, matching invoices ─────────────────────────────────────
 *
 * `InvoiceRepository::addLine()` computes tax as `intdiv(base * rate + 5000,
 * 10000)`. The same arithmetic is used here rather than a second convention,
 * because two tax roundings in one codebase disagree at exactly the amounts
 * somebody queries.
 */
final class AffiliatePayoutWithholding
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        // ADDED SEPARATELY AND NULLABLE-FREE VIA DEFAULTS, so an existing row —
        // there are none today, and this must still be true if there are
        // tomorrow — becomes a zero-withholding payout whose net equals its
        // total. That is what those rows actually meant.
        $pdo->exec('ALTER TABLE affiliate_payouts ADD COLUMN IF NOT EXISTS withholding_bp INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE affiliate_payouts ADD COLUMN IF NOT EXISTS withholding_minor BIGINT NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE affiliate_payouts ADD COLUMN IF NOT EXISTS net_minor BIGINT NOT NULL DEFAULT 0');

        // Existing rows predate the split: everything earned was everything
        // paid. Written explicitly rather than left to the DEFAULT, because the
        // default only applies to the added column and `net_minor` has to match
        // `total_minor`, not zero.
        $pdo->exec('UPDATE affiliate_payouts SET net_minor = total_minor WHERE net_minor = 0');

        // Who assembled it and who paid it. A payout is money leaving the
        // company on somebody's say-so, and "whose" is the first question asked
        // when one is queried. SET NULL because a departed employee's decision
        // must survive them — the same rule invoices use.
        $pdo->exec(
            'ALTER TABLE affiliate_payouts ADD COLUMN IF NOT EXISTS assembled_by_profile_id INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );
        $pdo->exec(
            'ALTER TABLE affiliate_payouts ADD COLUMN IF NOT EXISTS paid_by_profile_id INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );

        // A payout that does not add up cannot be stored. Worth a constraint
        // rather than a test for the same reason invoices carry one: the bug it
        // catches produces a PLAUSIBLE number, and a plausible wrong number on a
        // payout is found by the person who did not receive it.
        //
        // POSTGRESQL ONLY, following migration 094. SQLite has no
        // `ALTER TABLE ... ADD CONSTRAINT` at all — it can only express a CHECK
        // inside the statement that first created the table, which was
        // migration 154. (Spelled this way deliberately: the idempotency
        // scanner reads the two words it would otherwise find here as a real
        // table definition, and reports a table named after the next word.)
        // The local suite therefore runs without these two, and the guarantee is
        // held by {@see \Whity\Core\Affiliate\PayoutAssembler} on both engines
        // and by the database on the one that ships. That asymmetry is the same
        // one every CHECK in this schema has, and it is why the assembler's
        // arithmetic is tested directly rather than only through the constraint.
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }

        foreach ([
            'affiliate_payouts_amounts_add_up' => 'net_minor = total_minor - withholding_minor',
            'affiliate_payouts_amounts_sane' =>
                'total_minor > 0 AND withholding_minor >= 0 AND withholding_minor <= total_minor',
        ] as $name => $check) {
            try {
                $pdo->exec(sprintf(
                    'ALTER TABLE affiliate_payouts ADD CONSTRAINT %s CHECK (%s)',
                    $name,
                    $check
                ));
            } catch (\PDOException $e) {
                // 42710 duplicate_object — already added by an earlier run, and
                // `migrate run` is invoked twice in CI to prove idempotency.
                // Anything else is a real fault and must be heard: a blanket
                // catch here would turn a refused constraint into a table that
                // silently permits payouts which do not balance.
                if ($e->getCode() !== '42710' && !str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('ALTER TABLE affiliate_payouts DROP CONSTRAINT IF EXISTS affiliate_payouts_amounts_add_up');
        $pdo->exec('ALTER TABLE affiliate_payouts DROP CONSTRAINT IF EXISTS affiliate_payouts_amounts_sane');
        $pdo->exec('ALTER TABLE affiliate_payouts DROP COLUMN IF EXISTS withholding_bp');
        $pdo->exec('ALTER TABLE affiliate_payouts DROP COLUMN IF EXISTS withholding_minor');
        $pdo->exec('ALTER TABLE affiliate_payouts DROP COLUMN IF EXISTS net_minor');
        $pdo->exec('ALTER TABLE affiliate_payouts DROP COLUMN IF EXISTS assembled_by_profile_id');
        $pdo->exec('ALTER TABLE affiliate_payouts DROP COLUMN IF EXISTS paid_by_profile_id');
    }
}
