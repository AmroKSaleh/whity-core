<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Repository for the `tenant_email_domains` table (WC-9b87).
 *
 * Stores the email-domain ownership records that drive the membership policy.
 * When a profile verifies an email on a registered domain the policy service
 * uses this repository to locate which tenants claim that domain so it can
 * auto-provision or auto-accept memberships.
 *
 * Tenant scoping
 * --------------
 * All methods that read or mutate a specific row accept a `tenantId` parameter
 * and include `AND tenant_id = :tenant_id` in every statement.
 *
 * The one deliberate exception is {@see findTenantsByDomain()}, used only by the
 * policy service to enumerate ALL tenants that claim a given domain — it is
 * intentionally unscoped and must not be called from tenant-scoped handlers.
 */
final class TenantEmailDomainsRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Register a domain for a tenant. A fresh, unguessable `verification_token` is
     * generated so the tenant can prove ownership (DNS TXT); the row starts
     * UNVERIFIED (`verified_at` NULL), so it never auto-provisions until verified.
     *
     * @return int The new row's id.
     */
    public function insert(
        int $tenantId,
        string $domain,
        int $defaultRoleId,
        bool $autoProvision = true,
    ): int {
        // `auto_provision` is a CONTROLLED boolean (never user text), so it is
        // inlined as a TRUE/FALSE literal — portable across Postgres + the SQLite
        // test shim. Binding an int/PHP-bool to a PG boolean column is the classic
        // 42804 trap (and PHP false binds as '' which PG rejects), so avoid it.
        $autoProvisionLiteral = $autoProvision ? 'TRUE' : 'FALSE';
        $stmt = $this->db->prepare(
            "INSERT INTO tenant_email_domains
                 (tenant_id, domain, default_role_id, auto_provision, verification_token, created_at)
             VALUES (:tenant_id, :domain, :default_role_id, {$autoProvisionLiteral}, :token, NOW())"
        );
        $stmt->execute([
            ':tenant_id'       => $tenantId,
            ':domain'          => strtolower($domain),
            ':default_role_id' => $defaultRoleId,
            ':token'           => self::generateToken(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Ensure the registration has a verification token, generating one if absent
     * (e.g. rows created before ownership verification existed). Tenant-scoped.
     *
     * @return string|null The token, or null if the row does not exist for the tenant.
     */
    public function ensureToken(int $id, int $tenantId): ?string
    {
        $row = $this->findById($id, $tenantId);
        if ($row === null) {
            return null;
        }
        $existing = $row['verification_token'];
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = self::generateToken();
        $stmt = $this->db->prepare(
            'UPDATE tenant_email_domains SET verification_token = :token
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':token' => $token, ':id' => $id, ':tenant_id' => $tenantId]);
        return $token;
    }

    /**
     * Mark a registration as ownership-verified (sets `verified_at = NOW()`).
     * Tenant-scoped so a cross-tenant call affects zero rows.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function markVerified(int $id, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE tenant_email_domains SET verified_at = NOW()
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Find a domain registration by primary key, scoped to a tenant.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tenant_email_domains WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Find the registration for a specific domain within a tenant, or null if
     * the tenant has not registered that domain.
     *
     * @return array<string, mixed>|null
     */
    public function findByDomain(string $domain, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tenant_email_domains WHERE domain = :domain AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':domain' => strtolower($domain), ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Return ALL tenants that have registered a given domain.
     *
     * INTENTIONALLY UNSCOPED — used only by the policy service to enumerate
     * which tenants claim a domain so it can auto-provision memberships across
     * tenants. Must NOT be called from tenant-scoped request handlers.
     *
     * @return list<array<string, mixed>>
     */
    public function findTenantsByDomain(string $domain): array
    {
        // @tenant-guard-ignore: domain-policy service — enumerates all tenants claiming a domain for JIT membership (ADR 0005)
        $stmt = $this->db->prepare(
            'SELECT * FROM tenant_email_domains WHERE domain = :domain ORDER BY created_at ASC'
        );
        $stmt->execute([':domain' => strtolower($domain)]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * List all domain registrations for a tenant.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tenant_email_domains WHERE tenant_id = :tenant_id ORDER BY domain ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Remove a domain registration. Scoped to the tenant.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function delete(int $id, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tenant_email_domains WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
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
        $verifiedAt = isset($row['verified_at']) && $row['verified_at'] !== null
            ? (string) $row['verified_at']
            : null;
        $token = isset($row['verification_token']) && $row['verification_token'] !== null
            ? (string) $row['verification_token']
            : null;

        return [
            'id'                 => (int) $row['id'],
            'tenant_id'          => (int) $row['tenant_id'],
            'domain'             => (string) $row['domain'],
            'default_role_id'    => (int) $row['default_role_id'],
            'auto_provision'     => self::toBool($row['auto_provision']),
            'verified_at'        => $verifiedAt,
            'verification_token' => $token,
            // Convenience flag: a domain is trusted for auto-provisioning ONLY when
            // ownership has been verified.
            'is_verified'        => $verifiedAt !== null,
            'created_at'         => (string) $row['created_at'],
        ];
    }

    /**
     * Generate an opaque, unguessable verification token (32 hex chars).
     */
    private static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Coerce a DB boolean to PHP bool across drivers. pdo_pgsql returns a boolean
     * column as the STRING 't'/'f' — and `(bool) 'f' === true` — so a naive cast
     * reports EVERY Postgres row as auto_provision=true, silently forcing
     * auto-provisioning even where an admin set it FALSE. Match the canonical
     * true-set explicitly (mirrors IdentityProviderRepository::toBool()).
     */
    private static function toBool(mixed $value): bool
    {
        return in_array((string) $value, ['1', 't', 'true'], true);
    }
}
