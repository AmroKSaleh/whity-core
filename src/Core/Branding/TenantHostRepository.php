<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

use PDO;

/**
 * Data access for host→tenant lookups + branding_host management (Tenant
 * Branding). The `tenants` table is the tenant registry (not a per-row
 * tenant-owned table), so these lookups are intentionally cross-tenant by host;
 * they expose only tenant ids, never tenant data.
 */
final class TenantHostRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findIdByBrandingHost(string $host): ?int
    {
        // @tenant-guard-ignore: tenants registry lookup by host; returns an id only.
        $stmt = $this->db->prepare('SELECT id FROM tenants WHERE LOWER(branding_host) = :h LIMIT 1');
        $stmt->execute([':h' => strtolower($host)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function findIdBySlug(string $slug): ?int
    {
        // @tenant-guard-ignore: tenants registry lookup by slug; returns an id only.
        $stmt = $this->db->prepare('SELECT id FROM tenants WHERE LOWER(slug) = :s LIMIT 1');
        $stmt->execute([':s' => strtolower($slug)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function brandingHostExists(string $host, int $exceptTenantId): bool
    {
        // @tenant-guard-ignore: uniqueness check across the tenants registry.
        $stmt = $this->db->prepare(
            'SELECT 1 FROM tenants WHERE LOWER(branding_host) = :h AND id <> :id LIMIT 1'
        );
        $stmt->execute([':h' => strtolower($host), ':id' => $exceptTenantId]);
        return $stmt->fetchColumn() !== false;
    }

    public function setBrandingHost(int $tenantId, ?string $host): void
    {
        // @tenant-guard-ignore: updates the tenants registry row for the given tenant id.
        $stmt = $this->db->prepare('UPDATE tenants SET branding_host = :h WHERE id = :id');
        $stmt->execute([':h' => $host, ':id' => $tenantId]);
    }
}
