<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * The people who send us customers, and what they are owed.
 *
 * ── Why the listing carries the money ──────────────────────────────────────
 *
 * "How much do we owe this person" is the question somebody opens this screen
 * to answer, and it is a SUM over a ledger rather than a column anybody
 * maintains. A listing without it would need one request per row to find out,
 * and a stored copy would be a second source of truth that drifts the first time
 * a commission is written by a path that forgot to update it — which is the
 * exact drift that reads as "they say we owe them more than our system does".
 *
 * So the balance is derived here, in the same statement, as the promotions
 * listing derives its redemption count.
 *
 * ── Reversals are part of the sum, deliberately ────────────────────────────
 *
 * A clawback is a negative row rather than an edit, so `SUM(amount_minor)` is
 * the balance with nothing to remember and no branch to get the sign wrong. A
 * repository that filtered to `status = 'accrued'` would report what was earned
 * and quietly ignore what was given back.
 *
 * ── Currency is NOT summed across ──────────────────────────────────────────
 *
 * Commissions are recorded in whatever the customer paid in, and adding dinars
 * to dollars produces a number that is wrong in a way nobody can see. The
 * balance comes back PER CURRENCY, which is uglier on a screen and is the only
 * honest shape: a payout is made in one currency at a time anyway.
 */
final class AffiliateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every affiliate, with what they have earned and how many workspaces they
     * have sent.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        // @tenant-guard-ignore: an operator listing of every affiliate across the whole platform, system-tenant gated — the referral counts ARE the cross-tenant question it exists to answer, exactly as the accrual sweep reads referrals with no tenant context.
        $statement = $this->pdo->query(
            'SELECT a.id, a.code, a.name, a.email, a.profile_id, a.commission_bp,
                    a.window_months, a.promotion_id, a.is_active, a.created_at,
                    (SELECT COUNT(*) FROM affiliate_referrals r WHERE r.affiliate_id = a.id)
                        AS referral_count,
                    (SELECT COUNT(*) FROM affiliate_referrals r
                      WHERE r.affiliate_id = a.id AND r.first_paid_at IS NOT NULL)
                        AS converted_count
               FROM affiliates a
              ORDER BY a.is_active DESC, a.created_at DESC, a.id DESC'
        );

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $balances = $this->balances();

        return array_map(
            function (array $row) use ($balances): array {
                $row['id'] = (int) $row['id'];
                $row['commission_bp'] = (int) $row['commission_bp'];
                $row['window_months'] = (int) $row['window_months'];
                $row['referral_count'] = (int) $row['referral_count'];
                $row['converted_count'] = (int) $row['converted_count'];
                $row['profile_id'] = $row['profile_id'] === null ? null : (int) $row['profile_id'];
                $row['promotion_id'] = $row['promotion_id'] === null ? null : (int) $row['promotion_id'];
                // NOT `(bool)`. A BOOLEAN column has four possible spellings
                // across drivers — bool(false), '0', 'f', 'false' — and
                // `(bool) 'f'` is TRUE, so a plain cast reports every
                // deactivated affiliate as still earning. On PostgreSQL only,
                // which is where this runs.
                $row['is_active'] = DbBool::of($row['is_active']);
                $row['balances'] = $balances[$row['id']] ?? [];

                return $row;
            },
            $rows
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        foreach ($this->listAll() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a code is already taken, however it was typed.
     *
     * CASE-INSENSITIVE, matching the functional unique index the table carries
     * and the lookup attribution does. Comparing raw would let an operator
     * create 'spring26' beside 'SPRING26' and then watch every referral go to
     * whichever the index happened to return.
     */
    public function codeExists(string $code, ?int $excludingId = null): bool
    {
        $sql = 'SELECT 1 FROM affiliates WHERE LOWER(code) = LOWER(:code)';
        if ($excludingId !== null) {
            $sql .= ' AND id <> :id';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':code', $code);
        if ($excludingId !== null) {
            $statement->bindValue(':id', $excludingId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function create(
        string $code,
        string $name,
        int $commissionBp,
        int $windowMonths,
        ?string $email = null,
        ?int $profileId = null,
        ?int $promotionId = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliates (code, name, email, profile_id, commission_bp,
                                     window_months, promotion_id, is_active)
             VALUES (:code, :name, :email, :profile, :bp, :window, :promotion, :active)'
        );
        $statement->bindValue(':code', $code);
        $statement->bindValue(':name', $name);
        $statement->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':bp', $commissionBp, PDO::PARAM_INT);
        $statement->bindValue(':window', $windowMonths, PDO::PARAM_INT);
        $statement->bindValue(':promotion', $promotionId, $promotionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':active', true, PDO::PARAM_BOOL);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Change the terms of a deal.
     *
     * THE RATE IS NOT RETROACTIVE and this is where that is easiest to get
     * wrong. Every commission already accrued copied the rate it was earned at
     * onto its own row, so changing this one moves only what has not happened
     * yet — which is what renegotiating means, and the opposite of what an
     * earnings report would show if the rate were joined for at read time.
     */
    public function updateTerms(int $id, int $commissionBp, int $windowMonths, ?int $promotionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE affiliates
                SET commission_bp = :bp, window_months = :window,
                    promotion_id = :promotion, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $statement->bindValue(':bp', $commissionBp, PDO::PARAM_INT);
        $statement->bindValue(':window', $windowMonths, PDO::PARAM_INT);
        $statement->bindValue(':promotion', $promotionId, $promotionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * Stop an affiliate earning, without touching what they have earned.
     *
     * DEACTIVATED, NEVER DELETED. Money already owed is owed whatever happens to
     * the arrangement, and the rows proving it point at this one. An affiliate
     * whose row vanished would take their own payout history with them.
     *
     * Existing referrals keep their window and stop accruing, because the
     * accrual sweep only looks at active affiliates — see
     * {@see CommissionAccrualRun::referrals()}.
     */
    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE affiliates SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->bindValue(':active', $active, PDO::PARAM_BOOL);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * What each affiliate is owed, per currency, unpaid.
     *
     * UNPAID ONLY. A balance that included commissions already paid out would
     * grow forever and be read, by somebody assembling a payout, as money still
     * owed — which is how an affiliate gets paid twice for the same month.
     *
     * @return array<int, list<array{currency: string, amount_minor: int}>>
     */
    private function balances(): array
    {
        $statement = $this->pdo->query(
            'SELECT affiliate_id, currency, SUM(amount_minor) AS amount_minor
               FROM affiliate_commissions
              WHERE payout_id IS NULL
              GROUP BY affiliate_id, currency
              ORDER BY currency ASC'
        );

        if ($statement === false) {
            return [];
        }

        $balances = [];
        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $balances[(int) $row['affiliate_id']][] = [
                'currency' => (string) $row['currency'],
                'amount_minor' => (int) $row['amount_minor'],
            ];
        }

        return $balances;
    }
}
