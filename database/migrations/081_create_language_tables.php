<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateLanguageTables migration
 *
 * Creates the foundational i18n tables (languages and translations) in their final form.
 *
 * The languages table is global (no tenant_id) — all tenants see the same language codes.
 * The translations table is scoped: NULL tenant_id = system defaults, tenant_id>0 = tenant overrides.
 *
 * The unique index on (language_id, domain, key, tenant_id) prevents duplicate translations
 * for the same key in the same language, domain, and tenant scope.
 *
 * Both tables are idempotent (IF NOT EXISTS) and the down() reverses exactly this migration.
 */
class CreateLanguageTables
{
    public static function up(Database $db): void
    {
        // Languages table — global, available to all tenants.
        $db->exec('
            CREATE TABLE IF NOT EXISTS languages (
                id SERIAL PRIMARY KEY,
                code VARCHAR(10) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                enabled BOOLEAN DEFAULT true,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Index code for fast lookups by language code.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_languages_code ON languages(code)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_languages_enabled ON languages(enabled)');

        // Translations table — scoped by language, domain, key, and tenant.
        // tenant_id=NULL means system default (English/Arabic base strings).
        // tenant_id>0 means tenant-specific override.
        $db->exec('
            CREATE TABLE IF NOT EXISTS translations (
                id SERIAL PRIMARY KEY,
                language_id BIGINT NOT NULL REFERENCES languages(id) ON DELETE CASCADE,
                domain VARCHAR(100) NOT NULL,
                key VARCHAR(255) NOT NULL,
                translation TEXT NOT NULL,
                tenant_id BIGINT,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(language_id, domain, key, tenant_id)
            )
        ');

        // Index for fast lookups by language, domain, and tenant.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_translations_language_domain ON translations(language_id, domain)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_translations_language_tenant ON translations(language_id, tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_translations_tenant ON translations(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS translations CASCADE');
        $db->exec('DROP TABLE IF EXISTS languages CASCADE');
    }
}
