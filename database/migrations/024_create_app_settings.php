<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateAppSettings migration (Website Settings).
 *
 * Creates `app_settings` — the platform-wide GLOBAL website-settings defaults
 * (site_name, timezone, locale, support_email, and any future registry keys).
 * It is a SANCTIONED GLOBAL table: rows are unique platform-wide and carry NO
 * `tenant_id` (per-tenant overrides live in the tenant-owned `tenant_settings`
 * table created by migration 025). It is therefore enumerated in
 * {@see \Whity\Core\Tenant\SanctionedGlobalTables} so the tenant-predicate guard
 * treats it as exempt.
 *
 * Schema notes
 * ------------
 *  - `setting_key` is UNIQUE — one global value per key; the upsert path relies
 *    on this for ON CONFLICT (setting_key).
 *  - `value` is TEXT and nullable; the {@see \Whity\Core\Settings\SettingsRegistry}
 *    owns the typed contract and validation, not the schema, so new keys need no
 *    migration.
 *  - No `tenant_id` — global by design.
 *
 * Additive, idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateAppSettings
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                id SERIAL PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                value TEXT,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS app_settings CASCADE');
    }
}
