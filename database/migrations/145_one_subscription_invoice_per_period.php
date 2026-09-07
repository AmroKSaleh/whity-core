<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * OneSubscriptionInvoicePerPeriod — the constraint that makes a billing run
 * safe to re-run.
 *
 * THE PROBLEM A SCHEDULED BILLING RUN HAS. It is invoked by a clock, and clocks
 * fire twice. A worker restarted mid-run, a cron that overlaps its predecessor,
 * an operator triggering the run by hand after a failure, two workers claiming
 * the same tick — every one of those is ordinary, and every one of them would
 * bill a customer twice for the same month.
 *
 * The tempting fix is to have the run REMEMBER: a `last_billed_at` on the
 * tenant, or a check of whether an invoice already exists before creating one.
 * Both are read-then-write, and both have a window in which two runs both read
 * "not billed yet". It is the same shape as the counter that hands out two
 * invoice numbers, and it fails for the same reason.
 *
 * So the guarantee is a unique index, and the second run collides instead of
 * inserting. `(tenant_id, plan_id, period_start)` is the identity of a
 * subscription invoice: one tenant, on one plan, for one period. Nothing has to
 * be remembered, and no ordering between the two runs has to be arranged.
 *
 * WHY IT IS PARTIAL, AND WHAT IT DELIBERATELY DOES NOT CONSTRAIN. Only rows
 * that carry BOTH a plan and a period are subscription invoices. A one-off
 * charge — a migration fee, a support retainer, an adjustment somebody raised
 * by hand — has no plan or no period, falls outside the index, and can be
 * issued as many times as an operator likes. Constraining those too would mean
 * a tenant could be billed for extra work only once per period, which is not a
 * rule anybody asked for.
 *
 * A VOIDED INVOICE STILL HOLDS ITS PERIOD, and that is intentional rather than
 * an oversight. Voiding an invoice raised in error must NOT let the next run
 * silently raise it again — if a period needs re-billing after a void, that is
 * a person deciding to do it, not a scheduler doing it because the row it would
 * have collided with is now cancelled.
 */
final class OneSubscriptionInvoicePerPeriod
{
    public static function up(Database $db): void
    {
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_subscription_period
                 ON invoices(tenant_id, plan_id, period_start)
              WHERE plan_id IS NOT NULL AND period_start IS NOT NULL'
        );
    }

    public static function down(Database $db): void
    {
        // Dropping the index cannot fail and loses no data — but a deployment
        // running without it has no protection against a double billing run,
        // which is the whole reason it exists.
        $db->exec('DROP INDEX IF EXISTS uq_invoices_subscription_period');
    }
}
