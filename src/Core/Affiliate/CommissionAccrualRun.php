<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Turn what referred customers have paid into what affiliates have earned.
 *
 * ── Why a sweep, and why it re-reads everything ────────────────────────────
 *
 * Accruing at the moment of payment is the obvious design and the wrong one
 * here. Most of the money arrives at the billing service, not at us: we learn
 * about it from a notification that can be missed or from a reconciliation
 * sweep, so "the moment of payment" is not an event this deployment reliably
 * has. A sweep that re-reads a workspace's whole payment history converges
 * regardless of which notifications arrived.
 *
 * That only works because accruing twice is impossible rather than merely
 * unlikely: `(referral_id, source, source_ref)` is UNIQUE, so the second run
 * collides instead of paying again. The collision is caught and counted, not
 * raised — an already-accrued payment is the sweep working.
 *
 * ── Money out fails differently ────────────────────────────────────────────
 *
 * Every other ledger here records what a customer owes us. This records what we
 * owe somebody outside the company, and the two failures are not symmetric: an
 * over-accrual is money paid out that cannot be recalled, while an
 * under-accrual is an affiliate who stops promoting the product and tells people
 * why. So nothing is ever edited into a different amount — a clawback is a NEW
 * row with a negative amount, which keeps a balance a SUM and leaves the history
 * of what happened intact.
 */
final class CommissionAccrualRun
{
    /** How many referrals one sweep will process, so a backlog cannot run forever. */
    public const DEFAULT_BATCH = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ReferredPaymentSource $payments,
        /**
         * Whether a refunded payment claws its commission back.
         *
         * A SETTING BECAUSE IT IS A COMMERCIAL DECISION, not a technical one.
         * Reversing is correct — the revenue did not happen — but it is also the
         * thing affiliates dislike most, and a company that can afford to absorb
         * it may choose to. Default is to claw back; absorbing it is a decision
         * somebody makes deliberately.
         */
        private readonly bool $clawbackOnRefund = true,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{accrued: int, reversed: int, skipped: int, already: int, outside_window: int}
     */
    public function run(?DateTimeImmutable $now = null, int $limit = self::DEFAULT_BATCH): array
    {
        $now ??= new DateTimeImmutable();
        $result = ['accrued' => 0, 'reversed' => 0, 'skipped' => 0, 'already' => 0, 'outside_window' => 0];

        foreach ($this->referrals($limit) as $referral) {
            $payments = $this->payments->paymentsFor((int) $referral['tenant_id']);
            if ($payments === []) {
                continue;
            }

            // OLDEST FIRST, because the earliest one opens the window and every
            // later decision depends on where that boundary fell.
            usort($payments, static fn (ReferredPayment $a, ReferredPayment $b): int
                => $a->paidAt <=> $b->paidAt);

            $referral = $this->openWindowIfNeeded($referral, $payments[0]);

            $windowEnd = $referral['window_ends_at'] === null
                ? null
                : new DateTimeImmutable((string) $referral['window_ends_at']);

            foreach ($payments as $payment) {
                if ($windowEnd !== null && !CommissionCalculator::isWithinWindow($payment->paidAt, $windowEnd)) {
                    // Past the window. Counted rather than silent: a referral
                    // whose window closed is the ordinary end of an arrangement,
                    // and the number is how somebody sees it happening.
                    $result['outside_window']++;
                    continue;
                }

                if ($payment->refunded) {
                    $this->reverse($referral, $payment, $result);
                    continue;
                }

                $this->accrue($referral, $payment, $result);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $referral
     * @param array{accrued: int, reversed: int, skipped: int, already: int, outside_window: int} $result
     */
    private function accrue(array $referral, ReferredPayment $payment, array &$result): void
    {
        $amount = CommissionCalculator::amountFor($payment->baseMinor, (int) $referral['commission_bp']);
        if ($amount <= 0) {
            // A fully discounted invoice earns nothing. Not an error — and not a
            // row either, because a zero-amount commission on somebody's
            // statement invites the question of why it is there.
            $result['skipped']++;

            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO affiliate_commissions
                    (referral_id, affiliate_id, source, source_ref, base_minor, rate_bp,
                     amount_minor, currency, status, occurred_at)
                 VALUES (:referral, :affiliate, :source, :ref, :base, :rate, :amount, :currency, :status, :occurred)'
            );
            $statement->execute([
                ':referral' => (int) $referral['id'],
                ':affiliate' => (int) $referral['affiliate_id'],
                ':source' => $payment->source,
                ':ref' => $payment->reference,
                ':base' => $payment->baseMinor,
                // THE RATE IS COPIED, not joined for later. The affiliate's rate
                // today is not the rate this was earned at, and an earnings
                // report that silently rewrites itself when somebody
                // renegotiates is not a report.
                ':rate' => (int) $referral['commission_bp'],
                ':amount' => $amount,
                ':currency' => $payment->currency,
                ':status' => 'accrued',
                ':occurred' => $payment->paidAt->format('Y-m-d H:i:s'),
            ]);
            $result['accrued']++;
        } catch (\PDOException $e) {
            if (!self::isDuplicate($e)) {
                throw $e;
            }

            // The unique index did its job: this payment is already accrued.
            // Counted, not raised — a second sweep over the same history is the
            // normal case, not an error.
            $result['already']++;
        }
    }

    /**
     * Claw back a commission whose payment was refunded.
     *
     * A NEW NEGATIVE ROW, never an edit. The original earning happened and the
     * affiliate may have seen it on a statement; erasing it would make their
     * records and ours disagree with no way to tell why. The reversal carries
     * its own source reference so it, too, can only happen once.
     *
     * @param array<string, mixed> $referral
     * @param array{accrued: int, reversed: int, skipped: int, already: int, outside_window: int} $result
     */
    private function reverse(array $referral, ReferredPayment $payment, array &$result): void
    {
        if (!$this->clawbackOnRefund) {
            // Absorbed by decision. The commission stands.
            $result['skipped']++;

            return;
        }

        $original = $this->accruedAmountFor((int) $referral['id'], $payment);
        if ($original === null) {
            // Nothing was ever accrued for this payment — a refund of something
            // outside the window, or of a fully discounted invoice. There is
            // nothing to take back.
            $result['skipped']++;

            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO affiliate_commissions
                    (referral_id, affiliate_id, source, source_ref, base_minor, rate_bp,
                     amount_minor, currency, status, occurred_at)
                 VALUES (:referral, :affiliate, :source, :ref, :base, :rate, :amount, :currency, :status, :occurred)'
            );
            $statement->execute([
                ':referral' => (int) $referral['id'],
                ':affiliate' => (int) $referral['affiliate_id'],
                // A DISTINCT SOURCE, so the reversal has its own place in the
                // unique index and cannot be written twice by a later sweep.
                ':source' => $payment->source . ':reversal',
                ':ref' => $payment->reference,
                ':base' => -$payment->baseMinor,
                ':rate' => (int) $referral['commission_bp'],
                ':amount' => -$original,
                ':currency' => $payment->currency,
                ':status' => 'reversed',
                ':occurred' => $payment->paidAt->format('Y-m-d H:i:s'),
            ]);
            $result['reversed']++;
        } catch (\PDOException $e) {
            if (!self::isDuplicate($e)) {
                throw $e;
            }

            $result['already']++;
        }
    }

    /**
     * Whether a write failed because the row is already there.
     *
     * ── The bug this exists to stop, which shipped once ────────────────────
     *
     * Both writes above used to catch EVERY PDOException and count it as
     * "already accrued". That reads as tidy idempotency and is a trap: any
     * database error at all — a column too narrow, a type mismatch, a lost
     * connection — became a silent no-op that the run reported as normal.
     *
     * It was not hypothetical. `source` was VARCHAR(16) and the reversal marker
     * `external:reversal` is seventeen characters: PostgreSQL rejected every
     * clawback, SQLite accepted them, and the blanket catch turned a hard schema
     * error into a quiet nothing. Refunds would never have reversed in
     * production while the local suite stayed green, and affiliates would have
     * been overpaid indefinitely.
     *
     * So only a UNIQUE violation is idempotency. Everything else is a fault and
     * has to be heard.
     */
    private static function isDuplicate(\PDOException $e): bool
    {
        // 23505 is PostgreSQL's unique_violation; SQLite reports the broader
        // 23000 integrity-constraint class for the same collision. Both engines
        // run this suite, so both are recognised.
        $sqlState = $e->getCode();

        return $sqlState === '23505' || $sqlState === '23000';
    }

    /**
     * What was accrued for this payment, or null if nothing was.
     *
     * Read rather than recalculated, because the rate may have changed since:
     * a clawback must return exactly what was paid out, not what the same
     * payment would earn today.
     */
    private function accruedAmountFor(int $referralId, ReferredPayment $payment): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT amount_minor FROM affiliate_commissions
              WHERE referral_id = :referral AND source = :source AND source_ref = :ref'
        );
        $statement->execute([
            ':referral' => $referralId,
            ':source' => $payment->source,
            ':ref' => $payment->reference,
        ]);
        $amount = $statement->fetchColumn();

        return $amount === false ? null : (int) $amount;
    }

    /**
     * Open the earning window on the first payment, once.
     *
     * FROZEN ONCE SET. A later change to the affiliate's `window_months` must not
     * reopen a window both parties already agreed, nor close one early — so the
     * end date is stored rather than derived on every read.
     *
     * @param array<string, mixed> $referral
     *
     * @return array<string, mixed>
     */
    private function openWindowIfNeeded(array $referral, ReferredPayment $first): array
    {
        if ($referral['first_paid_at'] !== null) {
            return $referral;
        }

        $windowEnd = CommissionCalculator::windowEnd($first->paidAt, (int) $referral['window_months']);

        // @tenant-guard-ignore: addressed by the referral's own id, which this sweep resolved a moment ago; a referral row IS the tenant link and re-binding tenant_id here would add nothing.
        $statement = $this->pdo->prepare(
            'UPDATE affiliate_referrals
                SET first_paid_at = :first, window_ends_at = :ends, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND first_paid_at IS NULL'
        );
        $statement->execute([
            ':first' => $first->paidAt->format('Y-m-d H:i:s'),
            ':ends' => $windowEnd->format('Y-m-d H:i:s'),
            ':id' => (int) $referral['id'],
        ]);

        $referral['first_paid_at'] = $first->paidAt->format('Y-m-d H:i:s');
        $referral['window_ends_at'] = $windowEnd->format('Y-m-d H:i:s');

        $this->logger->info('An affiliate referral started earning', [
            'referral_id' => (int) $referral['id'],
            'window_ends_at' => $referral['window_ends_at'],
        ]);

        return $referral;
    }

    /**
     * Referrals worth looking at: those whose affiliate is still active.
     *
     * AN INACTIVE AFFILIATE STOPS EARNING, which is what deactivating one means.
     * Their existing commissions are untouched — money already earned is owed
     * whatever happens next.
     *
     * @return list<array<string, mixed>>
     *
     * @tenant-guard-ignore: this sweep has no tenant context by design — it is
     * the job that finds which referred workspaces to read payments for, exactly
     * as the billing run's dueSubscriptions() does. Every read that follows binds
     * the tenant id this returns.
     */
    private function referrals(int $limit): array
    {
        // @tenant-guard-ignore: this sweep has no tenant context by design — it is the job that FINDS which referred workspaces to read payments for, exactly as the billing run's dueSubscriptions() does; every read that follows binds the tenant id this returns.
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.tenant_id, r.affiliate_id, r.first_paid_at, r.window_ends_at,
                    a.commission_bp, a.window_months
               FROM affiliate_referrals r
               JOIN affiliates a ON a.id = r.affiliate_id
              WHERE a.is_active = :on
              ORDER BY r.id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
