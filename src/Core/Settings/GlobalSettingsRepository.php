<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use PDO;

/**
 * Data-access layer for `app_settings` — the GLOBAL website-settings defaults
 * (Website Settings feature).
 *
 * `app_settings` is a SANCTIONED GLOBAL table (see
 * {@see \Whity\Core\Tenant\SanctionedGlobalTables}): its rows are platform-wide,
 * carry NO `tenant_id`, and a tenant predicate would be meaningless. All SQL
 * touching the table lives here so handlers never issue raw queries (project
 * convention). Values are persisted as TEXT; the {@see SettingsRegistry} owns
 * the typed contract.
 *
 * Type discipline: PostgreSQL's PDO driver returns columns as PHP strings, so
 * every value is read back as a string with no numeric assumptions.
 */
final class GlobalSettingsRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * All stored global settings as a key => value map.
     *
     * Only rows actually present are returned; unset keys fall back to the
     * registry default at the service layer.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        // @tenant-guard-ignore: app_settings is a sanctioned global table (no tenant_id column); platform-wide defaults by design.
        $stmt = $this->db->query('SELECT setting_key, value FROM app_settings');
        if ($stmt === false) {
            return [];
        }

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
     * A single stored global setting, or null when the key has no stored row.
     *
     * Used for out-of-registry values (e.g. the encrypted SMTP password) that are
     * persisted in `app_settings` but deliberately not part of the typed
     * {@see SettingsRegistry} surface, so they never leak through the settings API.
     */
    public function get(string $key): ?string
    {
        // @tenant-guard-ignore: app_settings is a sanctioned global table (no tenant_id column); platform-wide defaults by design.
        $stmt = $this->db->prepare('SELECT value FROM app_settings WHERE setting_key = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * Upsert a single global setting (insert or update its value + timestamp).
     */
    public function set(string $key, string $value): void
    {
        // @tenant-guard-ignore: app_settings is a sanctioned global table (no tenant_id column); platform-wide defaults by design.
        $stmt = $this->db->prepare(
            'INSERT INTO app_settings (setting_key, value, updated_at)
             VALUES (:key, :value, NOW())
             ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    /**
     * Delete a single global setting, clearing the override so the registry
     * default applies again. Returns the number of rows removed (0 when absent).
     */
    public function delete(string $key): int
    {
        // @tenant-guard-ignore: app_settings is a sanctioned global table (no tenant_id column); platform-wide defaults by design.
        $stmt = $this->db->prepare('DELETE FROM app_settings WHERE setting_key = :key');
        $stmt->execute([':key' => $key]);

        return $stmt->rowCount();
    }
}
