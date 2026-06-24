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
     * Register a domain for a tenant.
     *
     * @return int The new row's id.
     */
    public function insert(
        int $tenantId,
        string $domain,
        int $defaultRoleId,
        bool $autoProvision = true,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (:tenant_id, :domain, :default_role_id, :auto_provision, NOW())'
        );
        $stmt->execute([
            ':tenant_id'       => $tenantId,
            ':domain'          => strtolower($domain),
            ':default_role_id' => $defaultRoleId,
            ':auto_provision'  => $autoProvision ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
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
        return [
            'id'              => (int) $row['id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'domain'          => (string) $row['domain'],
            'default_role_id' => (int) $row['default_role_id'],
            'auto_provision'  => (bool) $row['auto_provision'],
            'created_at'      => (string) $row['created_at'],
        ];
    }
}
