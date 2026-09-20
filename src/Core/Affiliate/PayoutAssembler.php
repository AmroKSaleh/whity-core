<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Turning what an affiliate is owed into a payment somebody can actually make.
 *
 * ── The gap this closes ────────────────────────────────────────────────────
 *
 * The commission ledger accrues nightly and `affiliate_payouts` existed with
 * nothing writing to it, so a balance grew and there was no way to settle it.
 * Paying somebody out of band would have left the ledger claiming the money was
 * still owed — and the next person assembling a payout would have paid it again.
 *
 * ── A payout CLAIMS its commissions, and that is the whole safety property ──
 *
 * Assembling stamps `payout_id` onto each commission it covers, and the update
 * is conditional on that column still being null. Two operators assembling at
 * the same instant do not split a balance in half or double it: the first claims
 * the rows, the second finds nothing left and gets an empty payout it can
 * discard. The balance query already excludes claimed rows, so what is "owed"
 * and what is "being paid" can never overlap.
 *
 * ── One currency at a time, because a payment is ───────────────────────────
 *
 * Commissions are recorded in whatever the customer paid in. A payout spanning
 * currencies would have to invent a conversion rate nobody stored, so it is
 * refused rather than guessed — the operator assembles JOD, then USD.
 *
 * ── A negative balance is not a payout ─────────────────────────────────────
 *
 * Clawbacks can exceed accruals in a period: an affiliate whose referred
 * customers refunded more than they bought is, on paper, owed a negative amount.
 * That is a debt carried forward, not a payment, and certainly not a transfer in
 * the other direction. Assembly refuses, the rows stay unclaimed, and the next
 * period's accruals net against them — which is what the affiliate would expect
 * and the only outcome that does not involve asking somebody for money back.
 */
final class PayoutAssembler
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PAID = 'paid';

    /** 100% in basis points. */
    private const FULL_RATE_BP = 10000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Gather everything unpaid in one currency into a draft payout.
     *
     * @param int $withholdingBp Kept back and remitted on the affiliate's
     *                           behalf. SNAPSHOT onto the row, so changing the
     *                           setting later cannot restate a payout already
     *                           made.
     *
     * @return array{payout_id: int, commissions: int, total_minor: int,
     *               withholding_minor: int, net_minor: int}|null
     *         Null when there is nothing payable — no unclaimed commissions, or
     *         a balance that is zero or negative.
     */
    public function assemble(
        int $affiliateId,
        string $currency,
        int $withholdingBp = 0,
        ?int $assembledByProfileId = null,
    ): ?array {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }

        // THE WHOLE ASSEMBLY IS ONE TRANSACTION. A payout row that exists while
        // its commissions are still unclaimed is a balance that has been paid
        // and still reads as owed; the reverse is commissions pointing at a
        // payout that was never created. Either is worse than failing.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $balance = $this->unclaimedBalance($affiliateId, $currency);

            if ($balance['count'] === 0) {
                if ($ownTransaction) {
                    $this->pdo->rollBack();
                }

                return null;
            }

            if ($balance['total'] <= 0) {
                // A NEGATIVE OR ZERO BALANCE IS CARRIED, NOT PAID. The rows stay
                // unclaimed so the next period's accruals net against them.
                $this->logger->info('An affiliate balance is not payable; it carries forward', [
                    'affiliate_id' => $affiliateId,
                    'currency' => $currency,
                    'balance_minor' => $balance['total'],
                ]);

                if ($ownTransaction) {
                    $this->pdo->rollBack();
                }

                return null;
            }

            $withholding = self::withholdingOn($balance['total'], $withholdingBp);
            $net = $balance['total'] - $withholding;

            $payoutId = $this->createDraft(
                $affiliateId,
                $currency,
                $balance['total'],
                $withholdingBp,
                $withholding,
                $net,
                $assembledByProfileId,
            );

            $claimed = $this->claimCommissions($payoutId, $affiliateId, $currency);

            if ($claimed !== $balance['count']) {
                // SOMEBODY ELSE CLAIMED ROWS BETWEEN THE READ AND THE WRITE.
                // The payout's total no longer describes what it contains, and
                // paying it would settle an amount that does not match its own
                // lines. Refused rather than adjusted: the operator re-assembles
                // and gets a payout that adds up.
                throw new PayoutRaceException(
                    'Another payout claimed some of these commissions while this one was being assembled.'
                );
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            $this->logger->info('An affiliate payout was assembled', [
                'payout_id' => $payoutId,
                'affiliate_id' => $affiliateId,
                'currency' => $currency,
                'commissions' => $claimed,
                'total_minor' => $balance['total'],
                'withholding_minor' => $withholding,
            ]);

            return [
                'payout_id' => $payoutId,
                'commissions' => $claimed,
                'total_minor' => $balance['total'],
                'withholding_minor' => $withholding,
                'net_minor' => $net,
            ];
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * What is kept back, rounded HALF UP.
     *
     * The same arithmetic as {@see \Whity\Core\Billing\InvoiceRepository::addLine()}
     * uses for sales tax, deliberately: two tax roundings in one codebase
     * disagree at exactly the amounts somebody queries.
     */
    public static function withholdingOn(int $grossMinor, int $rateBp): int
    {
        if ($grossMinor <= 0 || $rateBp <= 0) {
            return 0;
        }

        // Capped at the whole amount. A rate above 100% is a configuration
        // error, and a negative net would mean asking the affiliate to pay us.
        $rate = min($rateBp, self::FULL_RATE_BP);

        return intdiv($grossMinor * $rate + 5000, self::FULL_RATE_BP);
    }

    /**
     * Record that the money has actually moved.
     *
     * SEPARATE FROM ASSEMBLY, because they are separate events done by
     * different people at different times — and because nothing here sends
     * money. A human makes a transfer and then says so, which is why there is no
     * status between draft and paid.
     *
     * @param string $reference Whatever the person who made the payment can
     *                          quote back when the affiliate asks where their
     *                          money went.
     *
     * @throws PayoutStateException When the payout is already paid.
     */
    public function markPaid(
        int $payoutId,
        string $reference,
        ?DateTimeImmutable $paidAt = null,
        ?int $paidByProfileId = null,
    ): void {
        $statement = $this->pdo->prepare('SELECT status FROM affiliate_payouts WHERE id = :id');
        $statement->execute([':id' => $payoutId]);
        $status = $statement->fetchColumn();

        if ($status === false) {
            throw PayoutStateException::notFound();
        }

        if ($status === self::STATUS_PAID) {
            // ALREADY PAID IS REFUSED, NOT RE-APPLIED. Re-marking would move
            // `paid_at` and overwrite the bank reference of a transfer that
            // really happened — destroying the only record of which payment
            // settled it.
            throw PayoutStateException::alreadyPaid();
        }

        $paidAt ??= new DateTimeImmutable();

        $statement = $this->pdo->prepare(
            'UPDATE affiliate_payouts
                SET status = :status, reference = :reference, paid_at = :paid_at,
                    paid_by_profile_id = :paid_by, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND status <> :paid'
        );
        $statement->bindValue(':status', self::STATUS_PAID);
        $statement->bindValue(':reference', $reference);
        $statement->bindValue(':paid_at', $paidAt->format('Y-m-d H:i:s'));
        $statement->bindValue(
            ':paid_by',
            $paidByProfileId,
            $paidByProfileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':id', $payoutId, PDO::PARAM_INT);
        $statement->bindValue(':paid', self::STATUS_PAID);
        $statement->execute();
    }

    /**
     * Abandon a draft, releasing its commissions back to the balance.
     *
     * A draft assembled by mistake — wrong currency, wrong moment — must not
     * strand the money it claimed. Releasing puts every row back where the
     * balance query can see it.
     *
     * A PAID PAYOUT IS NEVER DISCARDED. The money has gone; a record of it that
     * can be deleted is not a record.
     *
     * @throws PayoutStateException When the payout has been paid.
     */
    public function discardDraft(int $payoutId): void
    {
        $statement = $this->pdo->prepare('SELECT status FROM affiliate_payouts WHERE id = :id');
        $statement->execute([':id' => $payoutId]);
        $status = $statement->fetchColumn();

        if ($status === false) {
            throw PayoutStateException::notFound();
        }

        if ($status === self::STATUS_PAID) {
            throw PayoutStateException::paidCannotBeDiscarded();
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // RELEASED FIRST. A deleted payout whose commissions still point at
            // it would be money claimed by nothing — invisible to the balance
            // and unpayable forever. The foreign key is ON DELETE SET NULL, so
            // the database would in fact rescue this one; doing it explicitly
            // means the order does not depend on remembering that.
            $release = $this->pdo->prepare('UPDATE affiliate_commissions SET payout_id = NULL WHERE payout_id = :id');
            $release->execute([':id' => $payoutId]);

            $delete = $this->pdo->prepare('DELETE FROM affiliate_payouts WHERE id = :id AND status <> :paid');
            $delete->bindValue(':id', $payoutId, PDO::PARAM_INT);
            $delete->bindValue(':paid', self::STATUS_PAID);
            $delete->execute();

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Every payout for one affiliate, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $affiliateId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.affiliate_id, p.total_minor, p.withholding_bp, p.withholding_minor,
                    p.net_minor, p.currency, p.status, p.reference, p.paid_at, p.created_at,
                    (SELECT COUNT(*) FROM affiliate_commissions c WHERE c.payout_id = p.id)
                        AS commission_count
               FROM affiliate_payouts p
              WHERE p.affiliate_id = :affiliate
              ORDER BY p.created_at DESC, p.id DESC'
        );
        $statement->execute([':affiliate' => $affiliateId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static function (array $row): array {
                foreach (['id', 'affiliate_id', 'total_minor', 'withholding_bp', 'withholding_minor',
                          'net_minor', 'commission_count'] as $intField) {
                    $row[$intField] = (int) $row[$intField];
                }

                return $row;
            },
            $rows
        );
    }

    /**
     * What is owed in one currency, and across how many rows.
     *
     * @return array{total: int, count: int}
     */
    private function unclaimedBalance(int $affiliateId, string $currency): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0) AS total, COUNT(*) AS rows_found
               FROM affiliate_commissions
              WHERE affiliate_id = :affiliate
                AND currency = :currency
                AND payout_id IS NULL'
        );
        $statement->execute([':affiliate' => $affiliateId, ':currency' => $currency]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return ['total' => (int) $row['total'], 'count' => (int) $row['rows_found']];
    }

    private function createDraft(
        int $affiliateId,
        string $currency,
        int $totalMinor,
        int $withholdingBp,
        int $withholdingMinor,
        int $netMinor,
        ?int $assembledByProfileId,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliate_payouts
                (affiliate_id, total_minor, withholding_bp, withholding_minor, net_minor,
                 currency, status, assembled_by_profile_id, created_at, updated_at)
             VALUES (:affiliate, :total, :rate, :withheld, :net, :currency, :status,
                     :assembled_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':affiliate', $affiliateId, PDO::PARAM_INT);
        $statement->bindValue(':total', $totalMinor, PDO::PARAM_INT);
        $statement->bindValue(':rate', $withholdingBp, PDO::PARAM_INT);
        $statement->bindValue(':withheld', $withholdingMinor, PDO::PARAM_INT);
        $statement->bindValue(':net', $netMinor, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':status', self::STATUS_DRAFT);
        $statement->bindValue(
            ':assembled_by',
            $assembledByProfileId,
            $assembledByProfileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Stamp this payout onto everything it covers.
     *
     * `payout_id IS NULL` IS THE RACE GUARD. Without it, two assemblies running
     * at once would each claim the same rows and the second would silently
     * re-point them, leaving the first payout describing money that is about to
     * be paid twice.
     *
     * @return int How many rows this payout actually claimed.
     */
    private function claimCommissions(int $payoutId, int $affiliateId, string $currency): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE affiliate_commissions
                SET payout_id = :payout
              WHERE affiliate_id = :affiliate
                AND currency = :currency
                AND payout_id IS NULL'
        );
        $statement->bindValue(':payout', $payoutId, PDO::PARAM_INT);
        $statement->bindValue(':affiliate', $affiliateId, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->execute();

        return $statement->rowCount();
    }
}
