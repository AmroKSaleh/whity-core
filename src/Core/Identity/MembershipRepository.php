<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Repository for the `memberships` table (WC-101).
 *
 * `memberships` is the explicit profile-to-tenant binding introduced by ADR
 * 0005. It replaces the implicit `users.tenant_id` FK with a lifecycle-managed
 * row whose status (active | invited | suspended) controls access.
 *
 * Tenant scoping
 * --------------
 * All methods that read or mutate a specific membership accept a `tenantId`
 * parameter and include `AND tenant_id = :tenant_id` in every statement. This
 * is the tenant-predicate pattern enforced across the platform; a cross-tenant
 * read or write therefore touches zero rows (findById returns null; update/
 * delete returns 0 affected rows).
 *
 * The one deliberate exception is {@see findForProfile()}, used only by the
 * login flow to enumerate a profile's memberships across ALL tenants — it is
 * intentionally unscoped and must not be called from tenant-scoped handlers
 * (see ADR 0005 §6, login flow step 4).
 */
final class MembershipRepository
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INVITED   = 'invited';
    public const STATUS_SUSPENDED = 'suspended';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a membership with an explicit status (default: active).
     *
     * @return int The new row's id.
     */
    public function insert(
        int $profileId,
        int $tenantId,
        int $roleId,
        ?int $ouId = null,
        string $status = self::STATUS_ACTIVE,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:profile_id, :tenant_id, :role_id, :ou_id, :status, NOW())'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id'  => $tenantId,
            ':role_id'    => $roleId,
            ':ou_id'      => $ouId,
            ':status'     => $status,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Create an invitation (status = 'invited').
     *
     * The profile cannot log in to this tenant until {@see accept()} is called.
     *
     * @return int The new row's id.
     */
    public function invite(int $profileId, int $tenantId, int $roleId, ?int $ouId = null): int
    {
        return $this->insert($profileId, $tenantId, $roleId, $ouId, self::STATUS_INVITED);
    }

    /**
     * Find a membership by primary key, scoped to a tenant.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Find the unique membership for a profile within a specific tenant, or null
     * if the profile has no membership in that tenant.
     *
     * @return array<string, mixed>|null
     */
    public function findByProfile(int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships WHERE profile_id = :profile_id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':profile_id' => $profileId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Return ALL memberships for a profile across every tenant.
     *
     * INTENTIONALLY UNSCOPED — used only by the login flow (ADR 0005 §6 step 4)
     * to enumerate which tenants a profile belongs to so the caller can determine
     * whether to auto-select a tenant or present a tenant-selection screen.
     * Must NOT be called from tenant-scoped request handlers.
     *
     * @return list<array<string, mixed>>
     */
    public function findForProfile(int $profileId): array
    {
        // @tenant-guard-ignore: login flow — enumerates all tenant memberships for one profile (ADR 0005 §6)
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships WHERE profile_id = :profile_id ORDER BY created_at ASC'
        );
        $stmt->execute([':profile_id' => $profileId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * List memberships in a tenant, optionally filtered by status.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM memberships WHERE tenant_id = :tenant_id AND status = :status ORDER BY created_at ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM memberships WHERE tenant_id = :tenant_id ORDER BY created_at ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId]);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Count memberships in a tenant.
     */
    public function countForTenant(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM memberships WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Transition 'invited' → 'active'. Scoped to the tenant so a cross-tenant
     * accept returns 0 and leaves the foreign row intact.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function accept(int $id, int $tenantId): int
    {
        return $this->setStatus($id, $tenantId, self::STATUS_ACTIVE);
    }

    /**
     * Transition any status → 'suspended'. Scoped to the tenant.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function suspend(int $id, int $tenantId): int
    {
        return $this->setStatus($id, $tenantId, self::STATUS_SUSPENDED);
    }

    /**
     * Transition 'suspended' → 'active'. Scoped to the tenant.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function reactivate(int $id, int $tenantId): int
    {
        return $this->setStatus($id, $tenantId, self::STATUS_ACTIVE);
    }

    /**
     * Remove a membership row. Scoped to the tenant so a cross-tenant delete
     * returns 0 and leaves the foreign row intact.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function delete(int $id, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM memberships WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Internal helper: set status with tenant predicate.
     */
    private function setStatus(int $id, int $tenantId, string $status): int
    {
        $stmt = $this->db->prepare(
            'UPDATE memberships SET status = :status WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':status' => $status, ':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Cast PDO string columns to proper PHP types.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'profile_id' => (int) $row['profile_id'],
            'tenant_id'  => (int) $row['tenant_id'],
            'role_id'    => (int) $row['role_id'],
            'ou_id'      => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            'status'     => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
