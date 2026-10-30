<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Editor M4b: a template's own fields, and where a document came from.
 *
 * `own_fields` (on document_sources and on each published version): fields a
 * template declares itself, for values no record type provides — a CSV
 * column on a label sheet, a value typed at print time. A list of
 * {key, label, sample?}; the editor inserts them as `template.<key>`.
 *
 * `starter_key`: set only by the starter seeder, so a tenant gets each
 * starter once and a renamed starter keeps its identity (as migration 075 did
 * for the old designer).
 *
 * `legacy_template_id`: the old designer's `document_templates` row this
 * document was converted from, so the conversion runs once per template.
 * No foreign key: the old table is removed with the old designer (M4c).
 *
 * Additive and idempotent; down() drops what up() added.
 */
class AddOwnFieldsAndOriginsToDocumentSources
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_sources ADD COLUMN IF NOT EXISTS own_fields JSONB');
        $db->exec('ALTER TABLE document_sources ADD COLUMN IF NOT EXISTS starter_key VARCHAR(64)');
        $db->exec('ALTER TABLE document_sources ADD COLUMN IF NOT EXISTS legacy_template_id BIGINT');
        $db->exec('ALTER TABLE document_source_versions ADD COLUMN IF NOT EXISTS own_fields JSONB');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_sources_tenant_starter ON document_sources(tenant_id, starter_key)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_sources_tenant_legacy ON document_sources(tenant_id, legacy_template_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_document_sources_tenant_legacy');
        $db->exec('DROP INDEX IF EXISTS idx_document_sources_tenant_starter');

        $db->exec('ALTER TABLE document_source_versions DROP COLUMN IF EXISTS own_fields');
        $db->exec('ALTER TABLE document_sources DROP COLUMN IF EXISTS legacy_template_id');
        $db->exec('ALTER TABLE document_sources DROP COLUMN IF EXISTS starter_key');
        $db->exec('ALTER TABLE document_sources DROP COLUMN IF EXISTS own_fields');
    }
}
