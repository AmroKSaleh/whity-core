<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * What a plan costs, on particular terms.
 *
 * A plan is a TIER — what you get. A price is an offer to sell that tier for a
 * currency, over a billing period, either flat or per seat. Monthly and yearly
 * are two prices for one plan rather than two plans, because they grant
 * identical entitlements and a tenant moving between them has not changed what
 * it bought.
 *
 * MONEY IS AN INTEGER OF MINOR UNITS throughout — fils, halalas, cents. Never a
 * float: a float cannot hold 0.10 exactly and the error compounds through the
 * exact operations billing performs (multiply by seats, take a percentage off,
 * sum a total). No exponent is stored either, because how many decimal places a
 * number has is a property of the CURRENCY and not of the row.
 *
 * PRICES ARE SWITCHED OFF, NOT DELETED. A deleted price that a tenant was being
 * billed against would leave a subscription pointing at nothing, and the row is
 * the evidence of what somebody was charged. {@see self::deactivate()} is the
 * ordinary end of a price's life; {@see self::delete()} exists for a row created
 * in error and nothing more.
 */
final class PlanPriceRepository
{
    public const PERIOD_MONTH = 'month';
    public const PERIOD_YEAR = 'year';
    /** A one-off charge: a perpetual licence, a setup fee. */
    public const PERIOD_ONCE = 'once';

    public const PERIODS = [self::PERIOD_MONTH, self::PERIOD_YEAR, self::PERIOD_ONCE];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @throws PlanValidationException When the currency, amount or period is
     *         not one the system can bill.
     */
    public function create(
        int $planId,
        string $currency,
        int $unitAmount,
        string $billingPeriod,
        bool $isPerSeat = false,
    ): int {
        $currency = self::normalizeCurrency($currency);
        self::assertValid($currency, $unitAmount, $billingPeriod);

        $stmt = $this->db->prepare(
            'INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at)
             VALUES (:plan_id, :currency, :unit_amount, :billing_period, :is_per_seat, :is_active, NOW(), NOW())'
        );
        $stmt->execute([
            ':plan_id'        => $planId,
            ':currency'       => $currency,
            ':unit_amount'    => $unitAmount,
            ':billing_period' => $billingPeriod,
            ':is_per_seat'    => $isPerSeat ? 1 : 0,
            ':is_active'      => 1,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Retire a price.
     *
     * The row stays. It is what a past charge was made against, and the partial
     * unique index frees its slot the moment it stops being active — so the
     * replacement can be created without the old one being destroyed.
     *
     * @return int Rows affected.
     */
    public function deactivate(int $id): int
    {
        $stmt = $this->db->prepare('UPDATE plan_prices SET is_active = :off, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id, ':off' => 0]);

        return $stmt->rowCount();
    }

    /**
     * Remove a price outright.
     *
     * For a row created in error, and nothing else. Retiring is
     * {@see self::deactivate()}: deleting a price a tenant was billed against
     * destroys the record of what they were charged.
     *
     * @return int Rows affected.
     */
    public function delete(int $id): int
    {
        $stmt = $this->db->prepare('DELETE FROM plan_prices WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plan_prices WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Every price for a plan.
     *
     * Retired prices are included unless `$activeOnly`, because a management
     * screen showing only live prices cannot explain a charge somebody is
     * querying — and "what was this tenant paying in March" is the question
     * such a screen is opened to answer.
     *
     * @return list<array<string, mixed>>
     */
    public function listForPlan(int $planId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM plan_prices WHERE plan_id = :plan_id';
        if ($activeOnly) {
            $sql .= ' AND is_active = :on';
        }
        $sql .= ' ORDER BY currency ASC, billing_period ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $params = [':plan_id' => $planId];
        if ($activeOnly) {
            $params[':on'] = 1;
        }
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * The one live price for a plan on these terms, or null.
     *
     * Single by construction: the partial unique index allows at most one active
     * row per (plan, currency, period, per-seat-ness), so this cannot silently
     * pick between two — which is how a customer ends up charged an amount no
     * screen displayed.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(
        int $planId,
        string $currency,
        string $billingPeriod,
        bool $isPerSeat = false,
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM plan_prices
              WHERE plan_id = :plan_id AND currency = :currency
                AND billing_period = :billing_period AND is_per_seat = :is_per_seat
                AND is_active = :on'
        );
        $stmt->execute([
            ':plan_id'        => $planId,
            ':currency'       => self::normalizeCurrency($currency),
            ':billing_period' => $billingPeriod,
            ':is_per_seat'    => $isPerSeat ? 1 : 0,
            ':on'             => 1,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * ISO 4217 is upper case, and storing it either way would make `SAR` and
     * `sar` two currencies that never match each other.
     */
    public static function normalizeCurrency(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    /**
     * @throws PlanValidationException
     */
    private static function assertValid(string $currency, int $unitAmount, string $billingPeriod): void
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PlanValidationException('currency', "must be a three-letter ISO 4217 code, got '{$currency}'");
        }

        // Zero is allowed: a free tier is a real commercial object, and it is
        // better expressed as a price of nothing than as the absence of a price,
        // which is indistinguishable from a plan nobody has priced yet.
        if ($unitAmount < 0) {
            throw new PlanValidationException('unit_amount', 'must be zero or a positive number of minor units');
        }

        if (!in_array($billingPeriod, self::PERIODS, true)) {
            throw new PlanValidationException(
                'billing_period',
                'must be one of: ' . implode(', ', self::PERIODS)
            );
        }
    }

    /**
     * Give the row PHP types.
     *
     * `unit_amount` is cast to int rather than left as the string PDO returns —
     * a string amount compares and arithmetics wrongly in ways that look right
     * for small numbers, and the whole point of storing minor units is that the
     * value is an integer.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'plan_id'        => (int) $row['plan_id'],
            'currency'       => (string) $row['currency'],
            'unit_amount'    => (int) $row['unit_amount'],
            'billing_period' => (string) $row['billing_period'],
            // DbBool, NOT a (bool) cast. PostgreSQL returns 'f' for false and
            // `(bool) 'f'` is TRUE in PHP — a retired price would read as live
            // on the real engine and as retired on SQLite, so every test would
            // pass and every production row would lie.
            'is_per_seat'    => DbBool::of($row['is_per_seat']),
            'is_active'      => DbBool::of($row['is_active']),
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }
}
