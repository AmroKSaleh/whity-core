<?php

declare(strict_types=1);

namespace Whity\Core\Promotion;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Early birds, offers and promo codes — stored as one thing.
 *
 * They differ only in how they are DISCOVERED: a code is typed by the customer,
 * an early bird applies to whoever arrives in time. Everything else — the
 * amount off, the window, the caps, the plans, the ledger — is identical, so
 * `code` is nullable and its absence is what makes a promotion automatic.
 *
 * MONEY IS AN INTEGER OF MINOR UNITS, as in {@see \Whity\Core\Plan\PlanPriceRepository}.
 * A percentage carries no currency; a fixed amount must carry one, because
 * "50 off" without saying 50 of what is not an offer.
 */
final class PromotionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A percentage-off promotion. Applies to a price in any currency, which is
     * the whole reason both kinds exist.
     *
     * @param list<int> $planIds Empty means EVERY plan — see the migration note
     *        on why a blanket promotion stores no rows rather than one per plan.
     * @throws PromotionValidationException
     */
    public function createPercentOff(
        string $name,
        int $percentOff,
        ?string $code = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?int $maxRedemptions = null,
        int $maxPerTenant = 1,
        array $planIds = [],
    ): int {
        if ($percentOff <= 0 || $percentOff > 100) {
            throw new PromotionValidationException('percent_off', 'must be between 1 and 100');
        }

        return $this->insert(
            $name,
            $code,
            $percentOff,
            null,
            null,
            $startsAt,
            $endsAt,
            $maxRedemptions,
            $maxPerTenant,
            $planIds
        );
    }

    /**
     * A fixed-amount promotion, in minor units of one currency.
     *
     * @param list<int> $planIds Empty means every plan.
     * @throws PromotionValidationException
     */
    public function createAmountOff(
        string $name,
        int $amountOff,
        string $currency,
        ?string $code = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?int $maxRedemptions = null,
        int $maxPerTenant = 1,
        array $planIds = [],
    ): int {
        if ($amountOff <= 0) {
            throw new PromotionValidationException('amount_off', 'must be a positive number of minor units');
        }
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PromotionValidationException('currency', "must be a three-letter ISO 4217 code, got '{$currency}'");
        }

        return $this->insert(
            $name,
            $code,
            null,
            $amountOff,
            $currency,
            $startsAt,
            $endsAt,
            $maxRedemptions,
            $maxPerTenant,
            $planIds
        );
    }

    /**
     * @param list<int> $planIds
     * @throws PromotionValidationException
     */
    private function insert(
        string $name,
        ?string $code,
        ?int $percentOff,
        ?int $amountOff,
        ?string $currency,
        ?string $startsAt,
        ?string $endsAt,
        ?int $maxRedemptions,
        int $maxPerTenant,
        array $planIds,
    ): int {
        if (trim($name) === '') {
            throw new PromotionValidationException('name', 'is required — a report listing only a code tells nobody what it was for');
        }
        if ($maxPerTenant < 1) {
            throw new PromotionValidationException('max_redemptions_per_tenant', 'must be at least 1');
        }
        if ($maxRedemptions !== null && $maxRedemptions < 1) {
            throw new PromotionValidationException('max_redemptions', 'must be at least 1, or null for unlimited');
        }
        // A window that closes before it opens can never be used, and a
        // promotion nobody can take is more likely a typo than an intention.
        if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            throw new PromotionValidationException('ends_at', 'must be after starts_at');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO promotions
                (name, code, percent_off, amount_off, currency, starts_at, ends_at,
                 max_redemptions, max_redemptions_per_tenant, is_active, created_at, updated_at)
             VALUES
                (:name, :code, :percent_off, :amount_off, :currency, :starts_at, :ends_at,
                 :max_redemptions, :max_per_tenant, :is_active, NOW(), NOW())'
        );
        $stmt->execute([
            ':name'            => trim($name),
            ':code'            => self::normalizeCode($code),
            ':percent_off'     => $percentOff,
            ':amount_off'      => $amountOff,
            ':currency'        => $currency,
            ':starts_at'       => $startsAt,
            ':ends_at'         => $endsAt,
            ':max_redemptions' => $maxRedemptions,
            ':max_per_tenant'  => $maxPerTenant,
            ':is_active'       => 1,
        ]);

        $id = (int) $this->db->lastInsertId();

        foreach ($planIds as $planId) {
            $link = $this->db->prepare(
                'INSERT INTO promotion_plans (promotion_id, plan_id) VALUES (:promotion_id, :plan_id)'
            );
            $link->execute([':promotion_id' => $id, ':plan_id' => $planId]);
        }

        return $id;
    }

    /**
     * Codes are compared upper case, so `summer24` and `SUMMER24` cannot become
     * two promotions — or, worse, one that a customer can only redeem by
     * matching the operator's shift key.
     */
    public static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));

        return $code === '' ? null : $code;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM promotions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * The live promotion carrying this code, if any.
     *
     * Single by construction: the partial unique index allows one active
     * promotion per code, so this cannot silently choose between two.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM promotions WHERE code = :code AND is_active = :on');
        $stmt->execute([':code' => self::normalizeCode($code), ':on' => 1]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Any promotion carrying this code, live or not.
     *
     * Separate from {@see self::findActiveByCode()} so an operator screen can
     * tell "no such code" from "switched off" — a distinction a CUSTOMER must
     * not be given, and which is therefore made here rather than guessed at by
     * the caller.
     *
     * @return array<string, mixed>|null
     */
    public function findAnyByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM promotions WHERE code = :code ORDER BY is_active DESC, id DESC');
        $stmt->execute([':code' => self::normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * The automatic promotions — the early birds and offers, which carry no
     * code and apply to whoever qualifies.
     *
     * @return list<array<string, mixed>>
     */
    public function listAutomatic(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM promotions WHERE code IS NULL AND is_active = :on ORDER BY id ASC'
        );
        $stmt->execute([':on' => 1]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * Which plans a promotion is restricted to. EMPTY MEANS EVERY PLAN.
     *
     * @return list<int>
     */
    public function planIdsFor(int $promotionId): array
    {
        $stmt = $this->db->prepare('SELECT plan_id FROM promotion_plans WHERE promotion_id = :id ORDER BY plan_id');
        $stmt->execute([':id' => $promotionId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function deactivate(int $id): int
    {
        $stmt = $this->db->prepare('UPDATE promotions SET is_active = :off, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id, ':off' => 0]);

        return $stmt->rowCount();
    }

    // ── the ledger ───────────────────────────────────────────────────────────

    /**
     * Record that a tenant took a promotion.
     *
     * The amount is stored as it was AT THE TIME. Recomputing it later would
     * give a different answer once the price moved, and the question people ask
     * is what they were actually charged.
     */
    public function recordRedemption(
        int $promotionId,
        int $tenantId,
        ?int $amountDiscounted = null,
        ?string $currency = null,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO promotion_redemptions (promotion_id, tenant_id, redeemed_at, amount_discounted, currency)
             VALUES (:promotion_id, :tenant_id, NOW(), :amount, :currency)'
        );
        $stmt->execute([
            ':promotion_id' => $promotionId,
            ':tenant_id'    => $tenantId,
            ':amount'       => $amountDiscounted,
            ':currency'     => $currency === null ? null : strtoupper($currency),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * How many times a promotion has been taken, BY ANYONE.
     *
     * Deliberately cross-tenant: `max_redemptions` means "how many times in
     * total may this be taken", which is the early bird's defining limit —
     * the first fifty customers, across every tenant there is. Scoping this to
     * one tenant would make an allocation of fifty mean fifty EACH, and the
     * cap would never be reached.
     *
     * The per-tenant question is {@see self::redemptionCountForTenant()}, and
     * the two are separate methods precisely so neither can be mistaken for the
     * other at a call site.
     */
    public function redemptionCount(int $promotionId): int
    {
        // @tenant-guard-ignore: the GLOBAL allocation count — an early bird's cap spans tenants by definition; the per-tenant count is redemptionCountForTenant() below and binds tenant_id
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM promotion_redemptions WHERE promotion_id = :id');
        $stmt->execute([':id' => $promotionId]);

        return (int) $stmt->fetchColumn();
    }

    /** How many times ONE tenant has taken it. */
    public function redemptionCountForTenant(int $promotionId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM promotion_redemptions WHERE promotion_id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $promotionId, ':tenant_id' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Give the row PHP types.
     *
     * `DbBool` rather than a cast, because PostgreSQL returns 'f' for false and
     * `(bool) 'f'` is TRUE in PHP — a retired promotion would read as live on
     * the real engine and retired on SQLite, so every test would pass and every
     * production row would lie.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'                         => (int) $row['id'],
            'name'                       => (string) $row['name'],
            'code'                       => $row['code'] === null ? null : (string) $row['code'],
            'percent_off'                => $row['percent_off'] === null ? null : (int) $row['percent_off'],
            'amount_off'                 => $row['amount_off'] === null ? null : (int) $row['amount_off'],
            'currency'                   => $row['currency'] === null ? null : (string) $row['currency'],
            'starts_at'                  => $row['starts_at'] === null ? null : (string) $row['starts_at'],
            'ends_at'                    => $row['ends_at'] === null ? null : (string) $row['ends_at'],
            'max_redemptions'            => $row['max_redemptions'] === null ? null : (int) $row['max_redemptions'],
            'max_redemptions_per_tenant' => (int) $row['max_redemptions_per_tenant'],
            'is_active'                  => DbBool::of($row['is_active']),
            'created_at'                 => (string) $row['created_at'],
            'updated_at'                 => (string) $row['updated_at'],
        ];
    }
}
