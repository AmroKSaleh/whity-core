<?php

declare(strict_types=1);

namespace Whity\Core\Delegation;

use PDO;

/**
 * Data-access layer for `permission_delegations` (WC-34).
 *
 * All SQL touching the delegation table lives here so API handlers never issue
 * raw queries (project convention). Every method is tenant-scoped and fails
 * closed: a delegation written under one tenant can never be read or mutated
 * under another. The polymorphic grantee is modelled as a (`grantee_type`,
 * `grantee_id`) pair where `grantee_type` is one of {@see self::GRANTEE_ROLE} /
 * {@see self::GRANTEE_USER}.
 *
 * Type discipline (real-Postgres parity): PostgreSQL's PDO driver returns
 * integer columns as PHP STRINGS, so every id/flag read back is normalised with
 * an explicit `(int)` cast before use — the int-vs-string trap the project's
 * real-engine tests exist to catch.
 */
class DelegationRepository
{
    /** Discriminator value: the grantee is a role. */
    public const GRANTEE_ROLE = 'role';

    /** Discriminator value: the grantee is a user. */
    public const GRANTEE_USER = 'user';

    private PDO $db;

    /**
     * @param PDO $db Database connection.
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert one delegation row (one granted permission).
     *
     * @param int      $tenantId      The owning tenant.
     * @param int      $grantorUserId The user creating the delegation.
     * @param string   $granteeType   {@see self::GRANTEE_ROLE} or {@see self::GRANTEE_USER}.
     * @param int      $granteeId     The role id or user id receiving the grant.
     * @param string   $permission    The delegated `resource:action` string.
     * @param int|null $ouId          Optional OU-subtree scope (null = tenant-wide).
     * @return int The new delegation id.
     */
    public function insert(
        int $tenantId,
        int $grantorUserId,
        string $granteeType,
        int $granteeId,
        string $permission,
        ?int $ouId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO permission_delegations
                (tenant_id, grantor_user_id, grantee_type, grantee_id, permission, ou_id, granted_at)
             VALUES (:tenant_id, :grantor, :grantee_type, :grantee_id, :permission, :ou_id, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':grantor' => $grantorUserId,
            ':grantee_type' => $granteeType,
            ':grantee_id' => $granteeId,
            ':permission' => $permission,
            ':ou_id' => $ouId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Fetch a single delegation by id, scoped to the tenant.
     *
     * The system tenant (id 0) may read a delegation in any tenant; any other
     * tenant reads only its own. Returns null when not visible/absent so callers
     * surface a 404 without disclosing cross-tenant existence.
     *
     * @param int $id       The delegation id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return array<string, mixed>|null The row (normalised), or null.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT * FROM permission_delegations WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM permission_delegations WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * List delegations visible to the tenant, newest first, with optional filters.
     *
     * The system tenant (id 0) sees all tenants' delegations; any other tenant
     * sees only its own. Optional filters narrow by grantee, grantor, and
     * whether to include revoked rows (default: live only).
     *
     * @param int         $tenantId      The acting tenant (0 = system).
     * @param string|null $granteeType   Filter by grantee type, or null for any.
     * @param int|null    $granteeId     Filter by grantee id, or null for any.
     * @param int|null    $grantorUserId Filter by grantor, or null for any.
     * @param bool        $includeRevoked Whether to include revoked delegations.
     * @return array<int, array<string, mixed>> Normalised rows.
     */
    public function list(
        int $tenantId,
        ?string $granteeType = null,
        ?int $granteeId = null,
        ?int $grantorUserId = null,
        bool $includeRevoked = false
    ): array {
        $where = [];
        $params = [];

        if ($tenantId !== 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        if ($granteeType !== null) {
            $where[] = 'grantee_type = :grantee_type';
            $params[':grantee_type'] = $granteeType;
        }

        if ($granteeId !== null) {
            $where[] = 'grantee_id = :grantee_id';
            $params[':grantee_id'] = $granteeId;
        }

        if ($grantorUserId !== null) {
            $where[] = 'grantor_user_id = :grantor';
            $params[':grantor'] = $grantorUserId;
        }

        if (!$includeRevoked) {
            $where[] = 'revoked_at IS NULL';
        }

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants; system tenant (id 0) lists all delegations by design
        $sql = 'SELECT * FROM permission_delegations';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY granted_at DESC, id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * Fetch the LIVE (non-revoked) delegated permission strings for a grantee
     * within a tenant, optionally constrained to a set of in-scope OU ids.
     *
     * This is the resolution-path query {@see DelegationService::delegatedPermissionsFor()}
     * uses to feed {@see \Whity\Auth\RoleChecker}. A delegation matches when:
     *  - it is live (`revoked_at IS NULL`),
     *  - it targets this (tenant, grantee_type, grantee_id),
     *  - AND its OU scope is satisfied: either tenant-wide (`ou_id IS NULL`) or
     *    its `ou_id` is one of `$inScopeOuIds` (the grantee's OU + ancestors).
     *
     * @param int            $tenantId     The tenant scope (never 0 here; resolution always has a concrete tenant).
     * @param string         $granteeType  {@see self::GRANTEE_ROLE} or {@see self::GRANTEE_USER}.
     * @param int            $granteeId    The grantee id.
     * @param array<int,int> $inScopeOuIds OU ids whose OU-scoped delegations apply (may be empty).
     * @return array<int, string> Distinct live delegated permission strings.
     */
    public function livePermissionsForGrantee(
        int $tenantId,
        string $granteeType,
        int $granteeId,
        array $inScopeOuIds
    ): array {
        $sql = 'SELECT DISTINCT permission
                FROM permission_delegations
                WHERE tenant_id = :tenant_id
                  AND grantee_type = :grantee_type
                  AND grantee_id = :grantee_id
                  AND revoked_at IS NULL';

        $params = [
            ':tenant_id' => $tenantId,
            ':grantee_type' => $granteeType,
            ':grantee_id' => $granteeId,
        ];

        // OU scope: tenant-wide delegations (ou_id IS NULL) always apply; an
        // OU-scoped delegation applies only when its ou_id is in scope for this
        // grantee. With no in-scope OU ids, only tenant-wide delegations match.
        if ($inScopeOuIds === []) {
            $sql .= ' AND ou_id IS NULL';
        } else {
            $placeholders = [];
            foreach (array_values($inScopeOuIds) as $i => $ouId) {
                $key = ':ou' . $i;
                $placeholders[] = $key;
                $params[$key] = $ouId;
            }
            $sql .= ' AND (ou_id IS NULL OR ou_id IN (' . implode(', ', $placeholders) . '))';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['permission'], $rows);
    }

    /**
     * Mark a delegation revoked (non-destructive: sets `revoked_at`).
     *
     * Tenant-scoped: a non-system tenant may revoke only its own delegations.
     * Already-revoked rows are left untouched (idempotent). Returns the number of
     * rows updated (0 when not found / not visible / already revoked).
     *
     * @param int $id       The delegation id.
     * @param int $tenantId The acting tenant (0 = system, may revoke any).
     * @return int Rows affected.
     */
    public function revoke(int $id, int $tenantId): int
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare(
                'UPDATE permission_delegations SET revoked_at = NOW()
                 WHERE id = :id AND revoked_at IS NULL'
            );
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE permission_delegations SET revoked_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id AND revoked_at IS NULL'
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        return $stmt->rowCount();
    }

    /**
     * Normalise a raw delegation row: cast integer/nullable columns so callers
     * and JSON output never depend on the PDO driver's int-as-string behaviour.
     *
     * @param array<string, mixed> $row Raw row from a SELECT *.
     * @return array<string, mixed> Normalised row.
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'grantor_user_id' => (int) $row['grantor_user_id'],
            'grantee_type' => (string) $row['grantee_type'],
            'grantee_id' => (int) $row['grantee_id'],
            'permission' => (string) $row['permission'],
            'ou_id' => isset($row['ou_id']) && $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            'granted_at' => isset($row['granted_at']) ? (string) $row['granted_at'] : null,
            'revoked_at' => isset($row['revoked_at']) && $row['revoked_at'] !== null
                ? (string) $row['revoked_at']
                : null,
        ];
    }
}
