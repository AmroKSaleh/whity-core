<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use Whity\Core\Payment\PaymentLedger;
use Whity\Core\Subscription\SubscriptionService;

/**
 * What to do about an invoice that has not been paid.
 *
 * DECIDING AND DOING ARE SEPARATE, on purpose. {@see self::assess()} is pure —
 * it reads state and returns a decision, and a test can walk a tenant through a
 * whole month of overdue-ness without anything happening to them.
 * {@see self::lock()} and {@see self::restore()} are the only methods that
 * change anything. The reason is that this is the code path that takes away
 * somebody's access, and a function that both decides and acts cannot be
 * examined without committing to its conclusion.
 *
 * IT DOES NOT TAKE PAYMENTS. Given a decision to attempt one, the caller is the
 * thing that talks to a provider — because whether an unattended attempt is even
 * possible is a property of the rail
 * ({@see \Whity\Core\Payment\PaymentCapabilities}), and on a push rail the
 * answer is no. Dunning that assumed it could charge would, on CliQ, generate a
 * failure per cycle for a customer who has done nothing wrong and lock them out
 * on schedule.
 *
 * LOCKING GOES THROUGH THE EXISTING PAYMENT WALL. There is no second mechanism:
 * the tenant's subscription status becomes `past_due`, and
 * {@see SubscriptionService::decide()} — which already returns 402 and already
 * exempts the system tenant — does the rest. A parallel lock flag would be a
 * second source of truth about whether somebody may use the product.
 */
final class DunningService
{
    /** Nothing is owed yet, or nothing more can be done automatically. */
    public const ACTION_NONE = 'none';

    /** An automatic attempt is due; the number says which. */
    public const ACTION_ATTEMPT = 'attempt';

    /** Past the lock day and still unpaid. */
    public const ACTION_LOCK = 'lock';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentLedger $ledger,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * What this invoice warrants right now. Changes nothing.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array{action: string, attempt: ?int, balance_minor: int}
     */
    public function assess(array $invoice, DunningSchedule $schedule, DateTimeImmutable $now): array
    {
        $tenantId = (int) $invoice['tenant_id'];
        $invoiceId = (int) $invoice['id'];
        $balance = (int) $invoice['total_minor'] - $this->ledger->amountSettledMinor($tenantId, $invoiceId);

        $none = ['action' => self::ACTION_NONE, 'attempt' => null, 'balance_minor' => $balance];

        if ($invoice['status'] !== InvoiceRepository::STATUS_OPEN || $balance <= 0) {
            // Paid, void, or covered. Nothing is owed, whatever the dates say.
            return $none;
        }

        $dueAt = $this->dueAt($invoice);
        if ($dueAt === null || $now < $dueAt) {
            return $none;
        }

        // LOCKING IS CHECKED FIRST. Once the lock day has passed, the answer is
        // to lock — even if an attempt also happens to be due, because
        // attempting again on a tenant we are about to lock out just adds a
        // failure to the record of why they were locked out.
        if ($schedule->shouldLock($dueAt, $now)) {
            return ['action' => self::ACTION_LOCK, 'attempt' => null, 'balance_minor' => $balance];
        }

        // FAILURES are what the schedule counts — not pending attempts, which
        // are money in flight, and not successes, which would not leave a
        // balance.
        $attempt = $schedule->nextAttemptDue(
            $dueAt,
            $this->ledger->failedAttempts($tenantId, $invoiceId),
            $now
        );

        return $attempt === null
            ? $none
            : ['action' => self::ACTION_ATTEMPT, 'attempt' => $attempt, 'balance_minor' => $balance];
    }

    /**
     * Withdraw access, through the payment wall that already exists.
     *
     * SETTING THE STATUS TO past_due IS NOT ENOUGH, and the way it is not
     * enough is worth spelling out because the first version of this method got
     * it exactly backwards.
     *
     * {@see SubscriptionService::isCurrent()} treats a `past_due` tenant with
     * NO grace deadline as still current — deliberately lenient, so that
     * marking somebody past due does not instantly cut them off. So clearing
     * `grace_until`, which is the obvious way to express "no grace left",
     * grants unlimited grace instead. The lock then does nothing at all, and
     * nothing looks wrong: the tenant keeps working, which from the outside is
     * indistinguishable from a tenant who paid.
     *
     * What actually withdraws access is a grace deadline in the PAST. `$at` is
     * the moment access is withdrawn, and every later request finds the
     * deadline behind it.
     */
    public function lock(int $tenantId, DateTimeImmutable $at): void
    {
        if ($tenantId === SubscriptionService::SYSTEM_TENANT_ID) {
            // The operator's own tenant is never locked out of its own
            // platform. The wall already refuses to enforce it; this refuses to
            // ask, so the intent is recorded in two places rather than one.
            return;
        }

        $this->subscriptions->setSubscription($tenantId, [
            'status' => SubscriptionService::STATUS_PAST_DUE,
            'grace_until' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Give access back once nothing is overdue.
     *
     * DELIBERATELY NOT "this invoice was paid". A tenant with two overdue
     * invoices who pays one is still overdue, and restoring them on the first
     * payment would let a customer stay unlocked indefinitely by always paying
     * the oldest. So this asks the general question, and the caller can call it
     * after any payment without needing to know.
     *
     * @return bool Whether access was restored by this call.
     */
    public function restoreIfNothingOverdue(int $tenantId, DateTimeImmutable $now): bool
    {
        $subscription = $this->subscriptions->getSubscription($tenantId);

        if (($subscription['status'] ?? null) !== SubscriptionService::STATUS_PAST_DUE) {
            return false;
        }

        foreach ($this->invoices->overdueOpen($now) as $invoice) {
            if ((int) $invoice['tenant_id'] !== $tenantId) {
                continue;
            }

            $balance = (int) $invoice['total_minor']
                - $this->ledger->amountSettledMinor($tenantId, (int) $invoice['id']);

            if ($balance > 0) {
                return false;
            }
        }

        // The grace deadline goes with the status: leaving a past one behind
        // would make a tenant who later falls past due again look instantly
        // locked, before dunning had said so.
        $this->subscriptions->setSubscription($tenantId, [
            'status' => SubscriptionService::STATUS_ACTIVE,
            'grace_until' => null,
        ]);

        return true;
    }

    /** @param array<string, mixed> $invoice */
    private function dueAt(array $invoice): ?DateTimeImmutable
    {
        $raw = $invoice['due_at'] ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
