<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * Data-access layer for `two_factor_policies` — admin-enforced 2FA policy
 * rows (WC-525 PR-3). Used by the admin CRUD/status API; login-time
 * resolution stays in {@see TwoFactorPolicyResolver}, which reads the same
 * table independently.
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every row
 * belongs to one tenant and every SELECT/UPDATE/DELETE binds an explicit
 * `tenant_id` predicate, so a row written under one tenant can never be read
 * or mutated under another.
 */
final class TwoFactorPoliciesRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every policy row for the tenant, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, scope_type, scope_id, grace_period_days, created_by, created_at, updated_at
             FROM two_factor_policies
             WHERE tenant_id = :tenant_id
             ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * A single policy row scoped to the tenant, or null when absent (including
     * when the id belongs to a DIFFERENT tenant — the tenant_id predicate makes
     * that indistinguishable from "does not exist", never a cross-tenant leak).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, scope_type, scope_id, grace_period_days, created_by, created_at, updated_at
             FROM two_factor_policies
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Create a policy row. Returns the new id, or null when a policy already
     * exists for this exact scope (the table's partial unique indexes reject
     * the insert; the caller should translate that to a 409).
     */
    public function create(int $tenantId, string $scopeType, ?int $scopeId, int $gracePeriodDays, ?int $createdBy): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO two_factor_policies (tenant_id, scope_type, scope_id, grace_period_days, created_by, created_at, updated_at)
                 VALUES (:tenant_id, :scope_type, :scope_id, :grace_period_days, :created_by, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id'         => $tenantId,
                ':scope_type'        => $scopeType,
                ':scope_id'          => $scopeId,
                ':grace_period_days' => $gracePeriodDays,
                ':created_by'        => $createdBy,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Unique-violation (Postgres 23505 / SQLite "UNIQUE constraint failed")
            // means a policy already exists for this exact scope.
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Update a policy's grace period. Returns false when no row matched
     * (wrong id, or belongs to a different tenant).
     */
    public function updateGracePeriod(int $tenantId, int $id, int $gracePeriodDays): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE two_factor_policies
             SET grace_period_days = :grace_period_days, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':grace_period_days' => $gracePeriodDays,
            ':tenant_id'         => $tenantId,
            ':id'                => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a policy row. Returns false when no row matched.
     */
    public function delete(int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM two_factor_policies WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'tenant_id'         => (int) $row['tenant_id'],
            'scope_type'        => (string) $row['scope_type'],
            'scope_id'          => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
            'grace_period_days' => (int) $row['grace_period_days'],
            'created_by'        => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
        ];
    }
}
