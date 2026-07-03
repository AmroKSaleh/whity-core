<?php

declare(strict_types=1);

namespace Whity\Core\Delegation;

use PDO;

/**
 * Data-access layer for `permission_delegations` (WC-34 / WC-bc07b6de).
 *
 * All SQL touching the delegation table lives here so API handlers never issue
 * raw queries (project convention). Every method is tenant-scoped and fails
 * closed: a delegation written under one tenant can never be read or mutated
 * under another. The polymorphic grantee is modelled as a (`grantee_type`,
 * `grantee_id`) pair where `grantee_type` is one of {@see self::GRANTEE_ROLE} /
 * {@see self::GRANTEE_PROFILE}.
 *
 * Profile-based FK (WC-bc07b6de)
 * --------------------------------
 * Migration 037 re-pointed `grantor_user_id → grantor_profile_id` (references
 * `profiles.id`) and updated `grantee_id` for `grantee_type = 'profile'` rows
 * to reference `profiles.id` as well. The discriminator for user-grantees is
 * now {@see self::GRANTEE_PROFILE}; the value {@see self::GRANTEE_USER} is kept
 * as a deprecated alias that resolves to the same discriminator value so
 * existing call-sites continue to work during Phase B.
 *
 * Tenant isolation: every delegation is scoped by `tenant_id`; grantee
 * visibility is confirmed via the `memberships` table (profile_id + tenant_id)
 * rather than the `users` table (see DelegationsApiHandler::granteeVisible).
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

    /**
     * Discriminator value: the grantee is a profile (WC-bc07b6de).
     *
     * New callers must use GRANTEE_PROFILE. GRANTEE_USER is kept as a deprecated
     * alias (same string value) for backward compatibility during Phase B.
     */
    public const GRANTEE_PROFILE = 'profile';

    /**
     * Deprecated alias for GRANTEE_PROFILE.
     *
     * @deprecated Use {@see self::GRANTEE_PROFILE} for new code.
     */
    public const GRANTEE_USER = 'profile';

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
     * @param int      $tenantId         The owning tenant.
     * @param int      $grantorProfileId The profile creating the delegation (profile_id).
     * @param string   $granteeType      {@see self::GRANTEE_ROLE} or {@see self::GRANTEE_PROFILE}.
     * @param int      $granteeId        The role id or profile id receiving the grant.
     * @param string   $permission       The delegated `resource:action` string.
     * @param int|null $ouId             Optional OU-subtree scope (null = tenant-wide).
     * @return int The new delegation id.
     */
    public function insert(
        int $tenantId,
        int $grantorProfileId,
        string $granteeType,
        int $granteeId,
        string $permission,
        ?int $ouId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, ou_id, granted_at)
             VALUES (:tenant_id, :grantor, :grantee_type, :grantee_id, :permission, :ou_id, NOW())'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':grantor'     => $grantorProfileId,
            ':grantee_type' => $granteeType,
            ':grantee_id'  => $granteeId,
            ':permission'  => $permission,
            ':ou_id'       => $ouId,
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
     * Count delegation rows matching the given filters.
     *
     * @param int         $tenantId         The acting tenant (0 = system).
     * @param string|null $granteeType      Filter by grantee type, or null.
     * @param int|null    $granteeId        Filter by grantee id, or null.
     * @param int|null    $grantorProfileId Filter by grantor profile id, or null.
     * @param bool        $includeRevoked   Whether to include revoked delegations.
     * @return int Total matching rows.
     */
    public function count(
        int $tenantId,
        ?string $granteeType = null,
        ?int $granteeId = null,
        ?int $grantorProfileId = null,
        bool $includeRevoked = false
    ): int {
        [$where, $params] = $this->buildListWhere(
            $tenantId, $granteeType, $granteeId, $grantorProfileId, $includeRevoked
        );

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants
        $sql = 'SELECT COUNT(*) AS cnt FROM permission_delegations';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int)($row['cnt'] ?? 0) : 0;
    }

    /**
     * @param int         $tenantId
     * @param string|null $granteeType
     * @param int|null    $granteeId
     * @param int|null    $grantorProfileId
     * @param bool        $includeRevoked
     * @param int|null    $limit
     * @param int         $offset
     * @return array<int, array<string, mixed>> Normalised rows.
     */
    public function list(
        int $tenantId,
        ?string $granteeType = null,
        ?int $granteeId = null,
        ?int $grantorProfileId = null,
        bool $includeRevoked = false,
        ?int $limit = null,
        int $offset = 0
    ): array {
        [$where, $params] = $this->buildListWhere(
            $tenantId, $granteeType, $granteeId, $grantorProfileId, $includeRevoked
        );

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants; system tenant (id 0) lists all delegations by design
        $sql = 'SELECT * FROM permission_delegations';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY granted_at DESC, id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params[':limit']  = $limit;
            $params[':offset'] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * Build the WHERE clause and params array shared by count() and list().
     *
     * @return array{array<int, string>, array<string, mixed>}
     */
    private function buildListWhere(
        int $tenantId,
        ?string $granteeType,
        ?int $granteeId,
        ?int $grantorProfileId,
        bool $includeRevoked
    ): array {
        $where  = [];
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

        if ($grantorProfileId !== null) {
            $where[] = 'grantor_profile_id = :grantor';
            $params[':grantor'] = $grantorProfileId;
        }

        if (!$includeRevoked) {
            $where[] = 'revoked_at IS NULL';
        }

        return [$where, $params];
    }

    /**
     * Fetch the LIVE (non-revoked) delegated permission strings for a grantee
     * within a tenant, optionally constrained to a set of in-scope OU ids.
     *
     * This is the resolution-path query {@see DelegationService} uses to feed
     * {@see \Whity\Auth\RoleChecker}. A delegation matches when:
     *  - it is live (`revoked_at IS NULL`),
     *  - it targets this (tenant, grantee_type, grantee_id),
     *  - AND its OU scope is satisfied: either tenant-wide (`ou_id IS NULL`) or
     *    its `ou_id` is one of `$inScopeOuIds` (the grantee's OU + ancestors).
     *
     * @param int            $tenantId     The tenant scope (never 0 here; resolution always has a concrete tenant).
     * @param string         $granteeType  {@see self::GRANTEE_ROLE} or {@see self::GRANTEE_PROFILE}.
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
            ':tenant_id'   => $tenantId,
            ':grantee_type' => $granteeType,
            ':grantee_id'  => $granteeId,
        ];

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
     * Handles both the new schema (grantor_profile_id) and a transitional
     * fallback to grantor_user_id in case a row was read before migration 037 ran.
     *
     * @param array<string, mixed> $row Raw row from a SELECT *.
     * @return array<string, mixed> Normalised row.
     */
    private function normalizeRow(array $row): array
    {
        // grantor_profile_id is the canonical column after migration 037.
        // Fall back to grantor_user_id for rows returned before the migration ran.
        $grantorId = $row['grantor_profile_id'] ?? $row['grantor_user_id'] ?? 0;

        return [
            'id'                 => (int) $row['id'],
            'tenant_id'          => (int) $row['tenant_id'],
            'grantor_profile_id' => (int) $grantorId,
            'grantee_type'       => (string) $row['grantee_type'],
            'grantee_id'         => (int) $row['grantee_id'],
            'permission'         => (string) $row['permission'],
            'ou_id'              => isset($row['ou_id']) && $row['ou_id'] !== null
                ? (int) $row['ou_id']
                : null,
            'granted_at'  => isset($row['granted_at']) ? (string) $row['granted_at'] : null,
            'revoked_at'  => isset($row['revoked_at']) && $row['revoked_at'] !== null
                ? (string) $row['revoked_at']
                : null,
        ];
    }
}
