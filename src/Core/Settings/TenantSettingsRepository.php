<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use PDO;

/**
 * Data-access layer for `tenant_settings` — the PER-TENANT website-settings
 * overrides (Website Settings feature).
 *
 * `tenant_settings` is a TENANT-OWNED table (see
 * {@see \Whity\Core\Tenant\TenantOwnedTables}): every row belongs to exactly one
 * tenant and the platform's #1 isolation invariant requires every
 * SELECT/UPDATE/DELETE to bind an explicit, parameterised `tenant_id` predicate.
 * Every method here takes the acting tenant id (sourced from
 * {@see \Whity\Core\Tenant\TenantContext} by the caller) and binds it, so a
 * row written under one tenant can never be read or mutated under another.
 *
 * All SQL touching the table lives here so handlers never issue raw queries
 * (project convention). Values are persisted as TEXT; the {@see SettingsRegistry}
 * owns the typed contract.
 *
 * Type discipline: PostgreSQL's PDO driver returns columns as PHP strings, so
 * every value is read back as a string.
 */
final class TenantSettingsRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * All stored overrides for one tenant as a key => value map.
     *
     * @param int $tenantId The owning tenant (always concrete; the service does
     *                      not call this for the system tenant 0, which has no
     *                      per-tenant override layer).
     * @return array<string, string>
     */
    public function allForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT setting_key, value FROM tenant_settings WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = (string) ($row['value'] ?? '');
        }

        return $out;
    }

    /**
     * Upsert a single override for one tenant (insert or update value + timestamp).
     *
     * @param int    $tenantId The owning tenant.
     * @param string $key      The setting key.
     * @param string $value    The override value (TEXT).
     */
    public function set(int $tenantId, string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
             VALUES (:tenant_id, :key, :value, NOW())
             ON CONFLICT (tenant_id, setting_key)
             DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key, ':value' => $value]);
    }

    /**
     * Delete a single override for one tenant, clearing it so the global/registry
     * default applies again. Returns the number of rows removed (0 when absent).
     *
     * @param int    $tenantId The owning tenant.
     * @param string $key      The setting key.
     */
    public function delete(int $tenantId, string $key): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key]);

        return $stmt->rowCount();
    }
}
